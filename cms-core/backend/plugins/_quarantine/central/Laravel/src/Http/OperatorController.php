<?php

namespace Pishdad\Plugins\Central\Http;

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Pishdad\Plugins\Central\Models\HardwareKey;
use Pishdad\Plugins\Central\Models\KillSwitch;
use Pishdad\Plugins\Central\Models\OperatorActivity;
use Pishdad\Plugins\Central\Models\SetupToken;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * تسک ۵.۷ — پروفایل اپراتور: کلید سخت‌افزاری + توکن نصب + kill-switch + لاگ فعالیت.
 *
 * - setup-tokens و kill-switch نیازمند step-up auth هستند: 2FA تازه (<۵ دقیقه).
 * - kill-switch دومرحله‌ای است: confirm:true + دلیل اجباری + لاگ غیرقابل حذف.
 * - ثبت کلید سخت‌افزاری فعلاً TOTP سخت‌افزاری است (WebAuthn واقعی TODO).
 */
class OperatorController extends Controller
{
    /**
     * پنجرهٔ پیش‌فرض فهرست وقتی بازه‌ای داده نشده: ۳۰ روزِ اخیر.
     *
     * عددی که در UI هم به فیلتر تزریق می‌شود (`filters.default_days`) تا کاربر
     * بداند دارد «همه» را نمی‌بیند.
     */
    public const DEFAULT_WINDOW_DAYS = 30;

    /** سقف `per_page` — بدون آن، یک `?per_page=100000` کل جدول را می‌کشد. */
    private const MAX_PER_PAGE = 100;

    /** `per_page` پیش‌فرض — همان ۲۰ که فهرستِ قبلی داشت. */
    private const DEFAULT_PER_PAGE = 20;

    /**
     * دسته UIUX (افزودنی): صدور secret موقت + otpauth_url برای ثبت کلید
     * با QR (اسکن در اپ Authenticator، بعد ثبت با کد ۶ رقمی).
     */
    public function hardwareSetup(Request $request): JsonResponse
    {
        $secret = (new Google2FA)->generateSecretKey();

        return response()->json([
            'data' => [
                'secret' => $secret,
                'otpauth_url' => (new Google2FA)->getQRCodeUrl('CMS-Operator', $request->user()->email, $secret),
            ],
        ]);
    }

    /**
     * J5 — فهرست کلیدهای سخت‌افزاریِ کاربر.
     *
     * ## چرا این متد تازه است
     *
     * `ProfileController` هسته در پاسخِ پروفایل، `hardware_keys` را می‌گذاشت و
     * برای همین مجبور بود `Central\Models\HardwareKey` را import کند. یعنی
     * **پروفایلِ عمومی** برای یک مفهومِ کاملاً مرکزی (کلیدِ لایسنسِ دستگاهِ
     * اپراتور) به افزونه وابسته بود.
     *
     * کلیدِ سخت‌افزاری ذاتاً چیزی است که فقط **مرکز** معنی دارد، پس جایش اینجاست.
     * شکلِ پاسخ عیناً همان است که فرانت می‌خواند
     * (`[{id, name, created_at}]`)، پس مهاجرت داده‌ای در UI لازم نشد.
     *
     * مسیر: `GET /api/v1/p/central/operator/2fa/hardware`
     */
    public function hardwareKeys(Request $request): JsonResponse
    {
        return response()->json([
            'data' => HardwareKey::query()
                ->where('user_id', $request->user()->id)
                ->get(['id', 'name', 'created_at']),
        ]);
    }

    public function registerHardwareKey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'secret' => 'required|string|max:100',
            'code' => 'required|string|max:20',
        ], [
            'name.required' => 'نام کلید الزامی است.',
            'code.required' => 'کد تأیید کلید الزامی است.',
        ]);

        $code = str_replace(' ', '', $validated['code']);
        if (! (new Google2FA)->verifyKey($validated['secret'], $code, 1)) {
            return response()->json(['message' => 'کد کلید سخت‌افزاری صحیح نیست.'], 422);
        }

        // TODO: WebAuthn واقعی (challenge/response با کلید فیزیکی)؛ فعلاً TOTP سخت‌افزاری.
        $key = HardwareKey::query()->create([
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'credential' => Crypt::encryptString($validated['secret']),
        ]);

        OperatorActivity::record($request->user(), 'operator.hardware_key', ['key_id' => $key->id]);

        return response()->json([
            'message' => 'کلید سخت‌افزاری ثبت شد.',
            'data' => $key->only(['id', 'name', 'created_at']),
        ], 201);
    }

    public function createSetupToken(Request $request): JsonResponse
    {
        $stepUp = $this->requireFreshTwoFactor($request);
        if ($stepUp) {
            return $stepUp;
        }

        $validated = $request->validate([
            'purpose' => 'sometimes|nullable|string|max:150',
            'ttl_minutes' => 'sometimes|integer|min:1|max:60',
        ]);

        $plain = Str::random(48);
        $token = SetupToken::query()->create([
            'created_by' => $request->user()->id,
            'token_hash' => hash('sha256', $plain),
            'purpose' => $validated['purpose'] ?? null,
            'expires_at' => now()->addMinutes((int) ($validated['ttl_minutes'] ?? 15)),
        ]);

        OperatorActivity::record($request->user(), 'operator.setup_token', [
            'token_id' => $token->id, 'purpose' => $token->purpose,
        ]);

        // متن توکن فقط یک‌بار نمایش داده می‌شود (فقط هش ذخیره شده است).
        return response()->json([
            'message' => 'توکن نصب ساخته شد. فقط یک‌بار نمایش داده می‌شود.',
            'data' => [
                'token' => $plain,
                'expires_at' => $token->expires_at->toIso8601String(),
            ],
        ], 201);
    }

    public function killSwitch(Request $request): JsonResponse
    {
        $stepUp = $this->requireFreshTwoFactor($request);
        if ($stepUp) {
            return $stepUp;
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:1000',
            'confirm' => 'sometimes|boolean',
        ], [
            'reason.required' => 'دلیل توقف اضطراری الزامی است.',
            'reason.min' => 'دلیل باید حداقل ۱۰ نویسه باشد.',
        ]);

        if (($validated['confirm'] ?? false) !== true) {
            return response()->json(['message' => 'برای اجرای توقف اضطراری، تأیید صریح (confirm:true) لازم است.'], 422);
        }

        $record = KillSwitch::query()->create([
            'executed_by' => $request->user()->id,
            'reason' => $validated['reason'],
            'confirmed' => true,
            'scope' => 'all',
        ]);

        OperatorActivity::record($request->user(), 'operator.kill_switch', [
            'kill_switch_id' => $record->id, 'reason' => $validated['reason'],
        ]);

        return response()->json([
            'message' => 'توقف اضطراری ثبت و در لاگ دائمی درج شد.',
            'data' => $record,
        ]);
    }

    public function activity(Request $request): JsonResponse
    {
        $query = $this->filteredActivity($request)->with('user:id,name,email')->latest();
        // `id` نقشِ tiebreaker دارد: `created_at` ثانیه‌ای است و چند ردیفِ
        // پشت‌سرهم می‌توانند timestamp یکسان بگیرند؛ بدون tiebreaker، صفحهٔ ۲
        // می‌تواند ردیفی را که در صفحهٔ ۱ بود دوباره نشان دهد.
        $query->orderByDesc('id');

        return response()->json([
            'data' => $query->paginate($this->perPage($request)),
            // متادیتای فیلتر تا UI مجبور نباشد فهرستِ اکشن‌ها را حدس بزند؛
            // `data` دقیقاً همان paginator قبلی است (پس مصرف‌کنندهٔ قبلی سالم می‌ماند).
            'filters' => $this->filterMeta(),
        ]);
    }

    /**
     * WF-L4 — خروجی CSV **همان انتخابِ فیلترشده** (نه یک گزارشِ جدا).
     *
     * یعنی فیلترها از یک متدِ مشترک (`filteredActivity`) می‌آیند؛ پس «دیدم ۳ ردیف،
     * CSV را دانلود کردم و ۳۱۲ ردیف بود» ممکن نیست — دقیقاً همان باگی که در
     * گزارش‌های دستی تکرار می‌شد.
     */
    public function activityExport(Request $request): StreamedResponse
    {
        $filename = 'operator-activity-'.now()->format('Ymd-His').'.csv';

        $response = response()->streamDownload(function () use ($request): void {
            $out = fopen('php://output', 'w');

            // BOM برای اکسل فارسی، وگرنه ستون‌ها را به‌هم‌ریخته می‌بیند.
            fwrite($out, "\xEF\xBB\xBF");

            // `escape = ''` مکانیزمِ اختصاصیِ PHP را خاموش می‌کند و خروجی را
            // واقعاً RFC-4180 می‌کند (پیش‌فرض، بک‌اسلش را داخل داده می‌نویسد).
            fputcsv($out, ['id', 'created_at', 'action', 'user_id', 'user_name', 'user_email', 'ip', 'meta'], ',', '"', '', "\r\n");

            $this->filteredActivity($request)
                ->with('user:id,name,email')
                ->latest()
                ->chunk(500, function ($rows) use ($out): void {
                    foreach ($rows as $row) {
                        fputcsv($out, [
                            $row->id,
                            $row->created_at?->toIso8601String(),
                            $this->csvCell((string) $row->action),
                            $row->user_id,
                            $this->csvCell((string) ($row->user?->name ?? '')),
                            $this->csvCell((string) ($row->user?->email ?? '')),
                            $this->csvCell((string) ($row->ip ?? '')),
                            $this->csvCell($this->metaText($row)),
                        ], ',', '"', '', "\r\n");
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);

        // `streamDownload()` هدر را از `HeaderUtils::makeDisposition()` می‌گیرد
        // و آن برای نامِ ASCII یک `filename=<token>` **بدون گیومه** می‌نویسد؛
        // token اجازهٔ فاصله/پرانتز ندارد و چند کلاینت/مدیرِ دانلود همان را
        // ناقص می‌خوانند. پس هدر را به شکلِ quoted-stringِ RFC 6266 خودمان
        // می‌نویسیم (نام ASCII است ⇒ `filename*=` لازم نیست).
        $response->headers->set('Content-Disposition', $this->attachmentDisposition($filename));

        return $response;
    }

    /**
     * هدرِ دانلود به شکلِ RFC 6266 §4.3: یک quoted-string.
     *
     * نام را به `[A-Za-z0-9._-]` می‌بندیم تا هم quoted-string معتبر بماند و هم
     * `"` یا CRLF نتواند داخل هدر جا خز کند (هدرِ ما ورودیِ کاربر ندارد، ولی
     * این تضمین ارزان است و همین کلاس را اگر روزی نامِ دلخواه گرفت ایمن نگه می‌دارد).
     */
    private function attachmentDisposition(string $filename): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);

        return 'attachment; filename="'.($safe !== '' ? $safe : 'operator-activity.csv').'"';
    }

    /**
     * WF-L4 — فیلترهای مشترکِ فهرست و خروجی CSV.
     *
     * اپراتور، اکشن (دقیق)، متن آزاد (اکشن/IP/متادیتا/نام و ایمیلِ اپراتور) و
     * بازهٔ تاریخ را می‌بیند. بدون بازه، **پیش‌فرض ۳۰ روز اخیر** است: لاگِ
     * اپراتور کم‌حجم است ولی یک پنجرهٔ باز یعنی «همه‌چیز» و شمارشِ ردیف‌ها
     * بی‌معنا؛ کاربر معمولاً دنبال «امروز چه شد» است، نه تاریخچهٔ دو ساله.
     */
    private function filteredActivity(Request $request): Builder
    {
        $query = OperatorActivity::query();

        // `is_numeric` و نه cast کور: `?user_id[]=1` آرایه می‌دهد و castِ آرایه
        // بی‌سروصدا به ۱ تبدیل می‌شد — یعنی فیلترِ «کاربرِ اشتباه».
        $userId = $request->query('user_id');
        if (is_numeric($userId)) {
            $query->where('user_id', (int) $userId);
        }

        if ($action = trim((string) $request->query('action', ''))) {
            $query->where('action', $action);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($q) => $q
                ->where('action', 'ilike', "%{$search}%")
                ->orWhere('ip', 'ilike', "%{$search}%")
                // `meta` از نوع jsonb است؛ متنِ آن برای جستجوی آزاد لازم است.
                ->orWhereRaw('meta::text ILIKE ?', ["%{$search}%"])
                ->orWhereHas('user', fn ($u) => $u
                    ->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")));
        }

        [$from, $to] = $this->dateWindow($request);

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }

        return $query;
    }

    /**
     * بازهٔ تاریخ. اگر هیچ سر تاریخی نیامده باشد، پنجرهٔ پیش‌فرض می‌آید — ولی
     * اگر کاربر فقط `to` داده باشد، از او پنجرهٔ پیش‌فرض اعمال **نمی‌شود**؛
     * وگرنه هر بازهٔ تاریخیِ «تا فلان روز» بی‌صدا خالی می‌شد.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function dateWindow(Request $request): array
    {
        $rawFrom = trim((string) $request->query('from', ''));
        $rawTo = trim((string) $request->query('to', ''));

        $from = $this->parseMoment($rawFrom, false);
        $to = $this->parseMoment($rawTo, true);

        if ($from === null && $to === null) {
            $from = now()->subDays(self::DEFAULT_WINDOW_DAYS)->startOfDay();
        }

        return [$from, $to];
    }

    /** تاریخِ نامعتبر «بی‌صدا» رد می‌شود — یک پارامتر خراب نباید ۵۰۰ بدهد. */
    private function parseMoment(string $value, bool $endOfDay): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            $moment = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        // تاریخِ تنها (`2026-10-05`) یعنی «تا پایان همان روز»، نه نیمه‌شب.
        return $endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? $moment->endOfDay()
            : $moment;
    }

    private function perPage(Request $request): int
    {
        $perPage = $request->query('per_page');

        return is_numeric($perPage)
            ? max(1, min((int) $perPage, self::MAX_PER_PAGE))
            : self::DEFAULT_PER_PAGE;
    }

    /** @return array{default_days: int, actions: list<string>} */
    private function filterMeta(): array
    {
        return [
            'default_days' => self::DEFAULT_WINDOW_DAYS,
            // سقف ۵۰: فهرستِ اکشن‌ها برای dropdown است، نه برای گزارش — و اکشن‌ها
            // کمتر از این تعدادند در عمل.
            'actions' => OperatorActivity::query()
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->limit(50)
                ->pluck('action')
                ->map(static fn (mixed $action): string => (string) $action)
                ->all(),
        ];
    }

    private function metaText(OperatorActivity $row): string
    {
        return (string) json_encode($row->meta ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * محافظت از CSV injection: سلول‌های آغازشده با = + - @ با ' بی‌خطر می‌شوند.
     *
     * استثنا: مقداری که **خودش یک عدد** است (`-1`، `+2.5`) داده است، نه حمله —
     * هیچ spreadsheetی `-1` را اجرا نمی‌کند. پیشوند گذاشتن روی آن یعنی تبدیل
     * دادهٔ عددی به متن: در اکسل `'-1` تراز-چپ می‌شود و دیگر عدد نیست. بردارهای
     * واقعی (`-2+3+cmd|'…'!A0`، `=HYPERLINK(…)`) عدد نیستند، پس همچنان خنثی می‌شوند.
     */
    private function csvCell(string $value): string
    {
        if (preg_match('/^[=+\-@]/', $value) !== 1) {
            return $value;
        }

        return is_numeric(trim($value)) ? $value : "'".$value;
    }

    private function requireFreshTwoFactor(Request $request): ?JsonResponse
    {
        if (! AuthController::hasFreshTwoFactor($request->user())) {
            return response()->json(
                ['message' => 'این عمل نیازمند تأیید دومرحله‌ای تازه (کمتر از ۵ دقیقه) است.'],
                403
            );
        }

        return null;
    }
}
