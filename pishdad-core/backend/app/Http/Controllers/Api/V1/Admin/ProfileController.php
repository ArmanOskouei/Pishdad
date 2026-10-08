<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoginEvent;
use App\Models\Media;
use App\Models\User;
use App\Services\Plugins\ManifestRegistry;
use App\Services\Sessions\SessionDeviceParser;
use App\Services\Sms\SmsDriverFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * تسک ۵.۲ — پروفایل مدیر: نمایش/ویرایش + تغییر رمز + متدهای 2FA + اسلات پلاگین.
 *
 * فعال‌سازی TOTP دومرحله‌ای است: اول secret (بدون فعال‌سازی)، بعد تأیید کد.
 * ریکاوری‌کدها فقط یک‌بار به‌صورت plaintext برگردانده و هش‌شده ذخیره می‌شوند.
 */
class ProfileController extends Controller
{
    /** WF-M9 — تعداد کدهای بازیابیِ صادرشده در هر مجموعه. */
    private const RECOVERY_CODE_COUNT = 8;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar_media_id' => $user->avatar_media_id,
            'avatar_url' => $this->avatarUrl($request, $user->avatar_media_id),
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'bio' => $user->bio,
            'google2fa_enabled' => (bool) $user->google2fa_enabled,
            'roles' => $user->getRoleNames()->all(),
            // K6.1 — پرمیشن‌های *خودِ همین* مدیر.
            //
            // تا پیش از این فقط `roles` برمی‌گشت و هیچ endpointی پرمیشن‌های
            // کاربر جاری را نمی‌داد. `/v1/admin/permissions` وجود دارد ولی پشت
            // `perm:users.view` است، پس برای نقش‌هایی که مجاز به مدیریت کاربران
            // نیستند ۴۰۳ می‌داد — یعنی فرانت هرگز نمی‌توانست بفهمد چه چیزی
            // برای او باز است. نتیجه: دروازهٔ `permission` در `mergePluginMenu`
            // fail-closed است و با ورودی خالی همه‌چیز را می‌بست.
            //
            // این خودِ کاربر است، نه کاتالوگ کل سیستم: فقط پرمیشن‌هایی که واقعاً
            // *دارد* برمی‌گردد. کاتالوگ برای صفحهٔ نقش‌ها از مسیر دیگری می‌آید.
            'permissions' => $this->effectivePermissions($user),
        ]]);
    }

    /**
     * پرمیشن‌های مؤثر مدیر جاری، نام‌نویسی‌شده به شکل `module.action`.
     *
     * ## چرا superadmin فهرست کامل می‌گیرد
     *
     * `EnsurePermission` (`:20`) به superadmin همیشه اجازه می‌دهد. اگر اینجا
     * فهرستش خالی برگردد، فرانت superadmin را هم مثل یک مدیر بدون دسترسی
     * رندر می‌کند و آیتم‌های منویش ناپدید می‌شوند — در حالی که هر کلیکش در
     * واقعیت کار می‌کند. یعنی رابط کاربری **دروغ** می‌گفت.
     *
     * پس superadmin همهٔ ماژول‌ها × همهٔ اکشن‌ها را می‌گیرد، دقیقاً همان چیزی که
     * سرور واقعاً قبول می‌کند. نام‌ها از همان رجیستری می‌آید که
     * `permissionsMatrix` می‌دهد، پس فرانت و بک‌اند یک منبع حقیقت دارند.
     */
    private function effectivePermissions(User $user): array
    {
        $granted = [];

        foreach (ManifestRegistry::permissionModules() as $module) {
            $name = is_string($module['name'] ?? null) ? $module['name'] : null;

            if ($name === null || $name === '') {
                continue;
            }

            foreach (ManifestRegistry::ACTIONS as $action) {
                if ($user->can("{$name}.{$action}")) {
                    $granted[] = "{$name}.{$action}";
                }
            }
        }

        return array_values(array_unique($granted));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $email = null;
        $emailChanged = false;

        if ($request->has('email')) {
            $rawEmail = $request->input('email');
            if (! is_string($rawEmail)) {
                return response()->json([
                    'message' => 'قالب ایمیل معتبر نیست.',
                    'errors' => ['email' => ['قالب ایمیل معتبر نیست.']],
                ], 422);
            }

            $email = strtolower(trim($rawEmail));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return response()->json([
                    'message' => 'قالب ایمیل معتبر نیست.',
                    'errors' => ['email' => ['قالب ایمیل معتبر نیست.']],
                ], 422);
            }

            $emailChanged = $email !== $user->email;
            if ($emailChanged && User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->where('id', '!=', $user->id)
                ->exists()) {
                return response()->json([
                    'message' => 'این ایمیل قبلاً ثبت شده است',
                    'errors' => ['email' => ['این ایمیل قبلاً ثبت شده است']],
                ], 422);
            }
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:150',
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^09\d{9}$/'],
            'avatar_media_id' => 'sometimes|nullable|integer|exists:media,id',
            'first_name' => 'sometimes|nullable|string|max:60',
            'last_name' => 'sometimes|nullable|string|max:60',
            'bio' => 'sometimes|nullable|string|max:1000',
        ], [
            'phone.regex' => 'شماره موبایل معتبر نیست (مثل 09123456789).',
            'avatar_media_id.exists' => 'تصویر انتخاب‌شده یافت نشد.',
            'first_name.max' => 'نام خیلی طولانی است (حداکثر ۶۰ نویسه).',
            'last_name.max' => 'نام خانوادگی خیلی طولانی است (حداکثر ۶۰ نویسه).',
            'bio.max' => 'متن «درباره من» خیلی طولانی است (حداکثر ۱۰۰۰ نویسه).',
        ]);

        if ($emailChanged) {
            $currentPassword = $request->input('current_password');
            if (! is_string($currentPassword) || $currentPassword === '') {
                return response()->json([
                    'message' => 'برای تغییر ایمیل، رمز فعلی الزامی است.',
                    'errors' => ['current_password' => ['برای تغییر ایمیل، رمز فعلی الزامی است.']],
                ], 422);
            }
            if (! Hash::check($currentPassword, $user->password)) {
                return response()->json([
                    'message' => 'رمز فعلی صحیح نیست.',
                    'errors' => ['current_password' => ['رمز فعلی صحیح نیست.']],
                ], 422);
            }
        }

        if (! empty($validated['avatar_media_id']) && ! Media::query()
            ->where('id', (int) $validated['avatar_media_id'])
            ->exists()) {
            return response()->json(['message' => 'تصویر انتخاب‌شده یافت نشد.'], 404);
        }

        $user->fill($validated);
        if ($emailChanged) {
            $user->email = $email;
        }

        $twoFactorDisabled = $emailChanged && (bool) $user->google2fa_enabled;
        if ($twoFactorDisabled) {
            $user->forceFill([
                'google2fa_secret' => null,
                'google2fa_enabled' => false,
                'recovery_codes' => [],
            ]);
        }
        $user->save();
        $fresh = $user->fresh();

        $message = 'پروفایل به‌روزرسانی شد.';
        if ($twoFactorDisabled) {
            $message = 'پروفایل به‌روزرسانی شد. تأیید دومرحله‌ای غیرفعال شد؛ برای ادامه باید دوباره آن را فعال کنید.';
        }

        return response()->json([
            'message' => $message,
            'data' => array_merge(
                $fresh->only([
                    'id', 'name', 'email', 'phone',
                    'avatar_media_id', 'first_name', 'last_name', 'bio',
                ]),
                [
                    'avatar_url' => $this->avatarUrl($request, $fresh->avatar_media_id),
                    'google2fa_enabled' => (bool) $fresh->google2fa_enabled,
                    'two_factor_disabled' => $twoFactorDisabled,
                ]
            ),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'password' => [
                'required', 'string', 'confirmed', 'min:10',
                'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/', 'regex:/[^A-Za-z0-9]/',
            ],
        ], [
            'current_password.required' => 'رمز فعلی الزامی است.',
            'password.required' => 'رمز جدید الزامی است.',
            'password.min' => 'رمز عبور باید حداقل ۱۰ نویسه باشد.',
            'password.confirmed' => 'تکرار رمز عبور مطابقت ندارد.',
            'password.regex' => 'رمز عبور باید شامل حروف بزرگ و کوچک، عدد و نویسه ویژه باشد.',
        ]);

        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['message' => 'رمز فعلی صحیح نیست.'], 422);
        }

        $user->forceFill(['password' => Hash::make($validated['password'])])->save();
        $user->tokens()->delete(); // همه نشست‌های دیگر باطل می‌شوند.

        return response()->json(['message' => 'رمز عبور تغییر کرد. لطفاً دوباره وارد شوید.']);
    }

    public function twoFactorMethods(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'totp' => ['enabled' => (bool) $user->google2fa_enabled],
            'recovery_codes_remaining' => count($user->recovery_codes ?? []),
            // دسته UIUX (افزودنی): وضعیت تأیید پیامکی.
            'sms' => [
                'enabled' => (bool) Cache::get("sms_verified:{$user->id}", false),
                'phone' => $user->phone,
            ],
        ]]);
    }

    public function enableTwoFactor(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->google2fa_enabled) {
            return response()->json(['message' => 'تأیید دومرحله‌ای قبلاً فعال است.'], 422);
        }

        $google2fa = new Google2FA;
        $cacheKey = "2fa_pending:{$user->id}";

        // گام دوم: تأیید کد روی secret موقت.
        if ($request->filled('code')) {
            $secret = Cache::get($cacheKey);
            if (! is_string($secret)) {
                return response()->json(['message' => 'ابتدا درخواست فعال‌سازی را ثبت کنید.'], 422);
            }

            $code = str_replace(' ', '', (string) $request->string('code'));
            if (! $google2fa->verifyKey($secret, $code, 1)) {
                return response()->json(['message' => 'کد تأیید صحیح نیست.'], 422);
            }

            $plainCodes = collect(range(1, self::RECOVERY_CODE_COUNT))
                ->map(fn () => Str::upper(Str::random(10)))->all();

            $user->forceFill([
                'google2fa_secret' => $secret,
                'google2fa_enabled' => true,
                'recovery_codes' => array_map(fn ($c) => Hash::make($c), $plainCodes),
            ])->save();
            Cache::forget($cacheKey);
            Cache::put("2fa_fresh:{$user->id}", true, now()->addMinutes(5));

            return response()->json([
                'message' => 'تأیید دومرحله‌ای فعال شد. کدهای بازیابی را در جای امن نگه دارید.',
                'data' => ['recovery_codes' => $plainCodes],
            ]);
        }

        // گام اول: صدور secret موقت (۱۰ دقیقه) + otpauth برای QR.
        $secret = $google2fa->generateSecretKey();
        Cache::put($cacheKey, $secret, now()->addMinutes(10));

        return response()->json([
            'message' => 'کد QR را اسکن و سپس کد ۶ رقمی را تأیید کنید.',
            'data' => [
                'secret' => $secret,
                'otpauth_url' => $google2fa->getQRCodeUrl('CMS', $user->email, $secret),
            ],
        ]);
    }

    public function disableTwoFactor(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->google2fa_enabled) {
            return response()->json(['message' => 'تأیید دومرحله‌ای فعال نیست.'], 422);
        }

        $request->validate([
            'code' => 'sometimes|string',
            'password' => 'sometimes|string',
        ]);

        $ok = false;
        if ($request->filled('code') && $user->google2fa_secret) {
            $code = str_replace(' ', '', (string) $request->string('code'));
            $ok = (new Google2FA)->verifyKey($user->google2fa_secret, $code, 1);
        }
        if (! $ok && $request->filled('password')) {
            $ok = Hash::check((string) $request->string('password'), $user->password);
        }
        if (! $ok) {
            return response()->json(['message' => 'برای غیرفعال‌سازی، کد TOTP یا رمز عبور لازم است.'], 422);
        }

        $user->forceFill([
            'google2fa_secret' => null,
            'google2fa_enabled' => false,
            'recovery_codes' => [],
        ])->save();

        return response()->json(['message' => 'تأیید دومرحله‌ای غیرفعال شد.']);
    }

    /**
     * E9 — بازتولید کدهای بازیابی: ۸ کد تازه، کدهای قبلی باطل، نمایش یک‌باره.
     *
     * نیازمند مدرک مالکیت (کد TOTP فعلی یا رمز عبور) — همان سیاستِ غیرفعال‌سازی.
     * کدهای جدید هش‌شده ذخیره و plaintext دقیقاً یک‌بار در همین پاسخ برمی‌گردد.
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->google2fa_enabled) {
            return response()->json(['message' => 'برای بازتولید کدهای بازیابی، ابتدا تأیید دومرحله‌ای را فعال کنید.'], 422);
        }

        $request->validate([
            'code' => 'sometimes|string',
            'password' => 'sometimes|string',
        ]);

        $ok = false;
        if ($request->filled('code') && $user->google2fa_secret) {
            $code = str_replace(' ', '', (string) $request->string('code'));
            $ok = (new Google2FA)->verifyKey($user->google2fa_secret, $code, 1);
        }
        if (! $ok && $request->filled('password')) {
            $ok = Hash::check((string) $request->string('password'), $user->password);
        }
        if (! $ok) {
            return response()->json(['message' => 'برای بازتولید کدهای بازیابی، کد TOTP یا رمز عبور لازم است.'], 422);
        }

        $plainCodes = collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn () => Str::upper(Str::random(10)))->all();

        $user->forceFill([
            'recovery_codes' => array_map(fn ($c) => Hash::make($c), $plainCodes),
        ])->save();
        Cache::put("2fa_fresh:{$user->id}", true, now()->addMinutes(5));

        return response()->json([
            'message' => 'کدهای بازیابی بازتولید شد. کدهای قبلی دیگر معتبر نیستند.',
            'data' => ['recovery_codes' => $plainCodes],
        ]);
    }

    /**
     * WF-M9 — شمارش کدهای بازیابیِ مصرف‌نشده؛ هرگز خودِ کدها را برنمی‌گرداند.
     *
     * منبعِ حقیقت همان آرایهٔ هش‌شده روی کاربر است: کدِ مصرف‌شده از آرایه حذف
     * می‌شود (AuthController::verifyTwoFactor)، پس `count` دقیقاً «باقی‌مانده» است.
     * `low` آستانهٔ هشدار (≤ ۲) را سمت سرور تعیین می‌کند تا همهٔ کلاینت‌ها یک
     * قاعده داشته باشند.
     */
    public function recoveryCodeCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $remaining = count($user->recovery_codes ?? []);

        return response()->json(['data' => [
            'enabled' => (bool) $user->google2fa_enabled,
            'remaining' => $remaining,
            'total' => self::RECOVERY_CODE_COUNT,
            'low' => (bool) $user->google2fa_enabled && $remaining <= 2,
        ]]);
    }

    /**
     * اسلات بخش‌های پروفایل برای اکستنشن پلاگین‌ها — فعلاً خالی با ساختار آماده.
     * پلاگین با همین شکل ثبت می‌کند: {key, title, component, order}.
     */
    public function sections(): JsonResponse
    {
        return response()->json([
            'data' => [
                'registry_version' => '1.0',
                'sections' => [],
            ],
        ]);
    }

    /**
     * WF-H8 — تاریخچهٔ ورود: آخرین ~۱۰ تلاشِ ورودِ همین کاربر (جدیدترین اول).
     *
     * مکملِ `sessions()` است نه جایگزین: `sessions()` فقط نشست‌های فعال را
     * می‌دهد، اینجا هم شکست‌ها و هم نشست‌های بسته‌شده دیده می‌شوند. دستگاه/
     * مرورگر در زمان خواندن parse می‌شود (UA خام ذخیره شده است).
     */
    public function loginHistory(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = LoginEvent::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(function (LoginEvent $event) {
                $parsed = SessionDeviceParser::parse($event->user_agent);

                return [
                    'id' => $event->id,
                    'successful' => (bool) $event->successful,
                    'ip' => $event->ip,
                    'os' => $parsed['os'],
                    'os_version' => $parsed['os_version'],
                    'browser' => $parsed['browser'],
                    'browser_version' => $parsed['browser_version'],
                    'device' => $parsed['device'],
                    'device_type' => $parsed['device_type'],
                    'created_at' => $event->created_at?->toIso8601String(),
                ];
            })->values();

        return response()->json(['data' => [
            'events' => $items,
            'count' => $items->count(),
        ]]);
    }

    /**
     * دسته UIUX (افزودنی): نشست‌های فعال کاربر (توکن‌های Sanctum).
     * هر نشست با فراداده parseشده (مسئله ۲): os / os_version / browser /
     * browser_version / device + ip / created_at / last_used_at / current.
     * فقط فراداده بی‌خطر؛ خود توکن هرگز برنمی‌گردد.
     */
    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentId = $user->currentAccessToken()?->id;

        $items = $user->tokens()->latest()->get()->map(function ($t) use ($currentId) {
            $parsed = SessionDeviceParser::parse($t->user_agent);

            return [
                'id' => $t->id,
                'name' => 'نشست وب',
                'current' => $currentId !== null && (int) $t->id === (int) $currentId,
                'os' => $parsed['os'],
                'os_version' => $parsed['os_version'],
                'browser' => $parsed['browser'],
                'browser_version' => $parsed['browser_version'],
                'device' => $parsed['device'],
                'device_type' => $parsed['device_type'],
                'ip' => $t->ip_address,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
                'expires_at' => $t->expires_at?->toIso8601String(),
            ];
        })->values();

        return response()->json(['data' => [
            'sessions' => $items,
            'count' => $items->count(),
        ]]);
    }

    /**
     * مسئله ۲ — حذف تکی یک نشست (به‌جز نشست جاری).
     * ناموجود = 404؛ نشست جاری = 422.
     */
    public function destroySession(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $currentId = $user->currentAccessToken()?->id;

        if ($currentId !== null && (int) $id === (int) $currentId) {
            return response()->json(['message' => 'نشست جاری را نمی‌توان با این روش بست؛ از «خروج» استفاده کنید.'], 422);
        }

        $token = $user->tokens()->where('id', $id)->first();
        if (! $token) {
            return response()->json(['message' => 'نشست یافت نشد.'], 404);
        }
        $token->delete();

        return response()->json(['message' => 'نشست بسته شد.']);
    }

    /** دسته UIUX (افزودنی): خروج از همه دستگاه‌ها به‌جز نشست جاری. */
    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentId = $user->currentAccessToken()?->id;

        $query = $user->tokens();
        if ($currentId !== null) {
            $query->where('id', '!=', $currentId);
        }
        $revoked = $query->delete();

        return response()->json([
            'message' => 'همه نشست‌های دیگر باطل شد.',
            'data' => ['revoked' => (int) $revoked],
        ]);
    }

    /**
     * دسته UIUX (افزودنی): تأیید پیامکی — گام ۱: صدور کد ۶ رقمی (۵ دقیقه).
     *
     * ⭐ F0.4/E3 — این متد **هیچ‌وقت** «ارسال شد» نمی‌گوید مگر آنکه درایورِ
     * پیامک واقعاً پیام را تحویل داده باشد (`SmsSenderInterface::delivers()` و
     * `send()` هر دو باید true باشند).
     *
     * رفتارِ هر حالت:
     *  - درایور `null`/`log` ⇒ `sms.not_configured` + بدون `debug_code` در پروداکشن.
     *  - درایور `panel` ولی کلیدها ناقص ⇒ exception از `SmsDriverFactory` بالا می‌رود
     *    (fail-closed با نامِ دقیقِ کلید) — بهتر از ۴۲۲ِ مبهم.
     *  - درایور `panel` و ارسال شکست خورد ⇒ همان پیامِ صادقانه، به‌علاوهٔ دلیل.
     *
     * ⭐ چرا کد فقط وقتی در کش می‌رود که پیام واقعاً رفته باشد: اگر کد را نگه
     * داریم ولی پیام نرفته، کاربر `sms/verify` را صدا می‌زند، کد را از یک جای
     * نامرئی حدس می‌زند و گازی داده می‌شود — بدترین حالت ممکن.
     */
    public function smsRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^09\d{9}$/'],
        ], [
            'phone.required' => 'شماره موبایل الزامی است.',
            'phone.regex' => 'شماره موبایل معتبر نیست (مثل 09123456789).',
        ]);

        $phone = $validated['phone'];
        $code = (string) random_int(100000, 999999);
        $user = $request->user();

        // fail-closed: درایورِ واقعیِ بدونِ کلید، همین‌جا می‌ایستد.
        $sender = SmsDriverFactory::make();
        $sent = $sender->send($phone, "کد تأیید شما: {$code}");

        if ($sent) {
            Cache::put("sms_pending:{$user->id}", [
                'phone' => $phone, 'code' => $code,
            ], now()->addMinutes(5));

            return response()->json([
                'message' => 'کد تأیید به شمارهٔ شما پیامک شد.',
                'code' => 'sms.sent',
            ]);
        }

        $payload = [
            'message' => 'سرویس پیامک هنوز فعال نشده است؛ کدی ارسال نشد. (کد فقط در محیط غیرپروداکشن برای تست برمی‌گردد)',
            'code' => 'sms.not_configured',
        ];

        if ($sender->lastError() !== null) {
            $payload['data'] = ['driver' => $sender->driverName(), 'reason' => $sender->lastError()];
        }

        if (! app()->isProduction()) {
            // محیط غیرپروداکشن: کد را می‌دهیم تا مسیر قابلِ تست باشد.
            $payload['data'] = array_merge($payload['data'] ?? [], ['debug_code' => $code]);
            Cache::put("sms_pending:{$user->id}", [
                'phone' => $phone, 'code' => $code,
            ], now()->addMinutes(5));
        }

        return response()->json($payload);
    }

    /** دسته UIUX (افزودنی): تأیید پیامکی — گام ۲: بررسی کد + ثبت شماره روی پروفایل. */
    public function smsVerify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:10',
        ], [
            'code.required' => 'کد تأیید الزامی است.',
        ]);

        $user = $request->user();
        $pending = Cache::get("sms_pending:{$user->id}");
        if (! is_array($pending) || ! isset($pending['code'], $pending['phone'])) {
            return response()->json(['message' => 'ابتدا درخواست کد تأیید را ثبت کنید.'], 422);
        }
        if (trim($validated['code']) !== (string) $pending['code']) {
            return response()->json(['message' => 'کد تأیید صحیح نیست.'], 422);
        }

        $user->forceFill(['phone' => $pending['phone']])->save();
        Cache::forget("sms_pending:{$user->id}");
        Cache::put("sms_verified:{$user->id}", true, now()->addDays(90));

        return response()->json([
            'message' => 'شماره موبایل تأیید شد.',
            'data' => ['phone' => $pending['phone']],
        ]);
    }

    /** دسته UIUX (افزودنی): قطع تأیید پیامکی. */
    public function smsDisconnect(Request $request): JsonResponse
    {
        Cache::forget("sms_verified:{$request->user()->id}");
        Cache::forget("sms_pending:{$request->user()->id}");

        return response()->json(['message' => 'تأیید پیامکی قطع شد.']);
    }

    /** نشانی نمایشی آواتار (حل path با AWS_URL؛ ناموجود → null). */
    private function avatarUrl(Request $request, mixed $mediaId): ?string
    {
        if (! is_numeric($mediaId) || (int) $mediaId <= 0) {
            return null;
        }
        $path = Media::query()
            ->where('id', (int) $mediaId)
            ->value('path');
        if (! $path) {
            return null;
        }
        $base = rtrim((string) config('filesystems.disks.s3.url', ''), '/');

        return $base === '' ? null : $base.'/'.ltrim($path, '/');
    }
}
