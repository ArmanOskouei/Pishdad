<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\RevalidateDispatcher;
use App\Services\Settings\CachedSettings;
use App\Services\Themes\SiteThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * F4.1.B — بک‌اندِ سه‌قالبه: ۵ اندپوینت `site-theme/*`.
 *
 * ## چرا این‌ها جدا از `ThemeController` هستند
 *
 * `ThemeController` (تسک ۴.۲ قدیمی) دربارهٔ **بستهٔ ZIP قالب** است: آپلود،
 * امضای Ed25519، بازبینیِ مرکزی. `F4.1.B` دربارهٔ **پوسته + رنگ‌بندیِ
 * درون‌ساخت** است. یکی فایل می‌گیرد و در `themes` است، دیگری توکن می‌نویسد
 * و در `site_themes`/`site_theme_presets`. قاطی‌کردنشان یعنی مسیر ZIP بتواند
 * رنگِ قالبِ درون‌ساخت را عوض کند — و `Q5` صریحاً همین را ممنوع کرده.
 *
 * ## مسیر عمداً `site-theme` است، نه `themes`
 *
 * تا با `themes/*` موجود برخورد نکند و تا در فرانت (که tag کش `site-theme` را
 * می‌شناسد) یکپارچه بماند.
 *
 * ## سه چیزی که هر نوشتنی اینجا باید انجام دهد
 *
 *  ۱) `SiteThemeResolver::flush()` — کش پنج‌دقیقه‌ایِ توکن.
 *  ۲) `CachedSettings::forget('site_chrome', …)` — وگرنه بازدیدکننده تا **دو
 *     دقیقه** رنگِ قبلی را می‌بیند، چون `SiteChromeController` کروم را ۱۲۰ ثانیه
 *     کش می‌کند. این باگ هیچ خطایی نمی‌دهد؛ فقط «ذخیره شد ولی اعمال نشد».
 *  ۳) `RevalidateDispatcher::dispatch(['site-theme','site-chrome'])` — purge
 *     کشِ ISR فرانت.
 *
 * هر سه در `invalidate()` جمع شده‌اند تا فراموش‌شدنِ یکی از آن‌ها آسان نباشد.
 */
class SiteThemeController extends Controller
{
    /**
     * کلیدِ کروم برای نصبِ تک‌سایتی. `SiteChromeController` همین را می‌سازد:
     * `'chrome-'.($siteId ?: 'default-global')`.
     *
     * این ثابت عمداً اینجا تکرار و کامنت شده — اگر آن‌ور عوض شود، mismatch
     * بی‌صدا می‌ماند و رنگ ۲ دقیقه کهنه می‌ماند.
     */
    private const CHROME_CACHE_KEY = 'chrome-default-global';

    /** تگ‌هایی که فرانت از `/api/revalidate` می‌پذیرد (F0-B8 / L-B8). */
    private const TAGS = ['site-theme', 'site-chrome'];

    /**
     * `GET /v1/admin/site-theme` — آنچه ویرایشگر برای رندر لازم دارد.
     *
     * یک فراخوانی، نه سه‌تا: ویرایشگر به قالب‌ها **و** رنگ‌بندی‌ها **و**
     * انتخابِ فعلی **و** توکن‌های نهایی نیاز دارد، و سه فراخوانیِ موازی یعنی
     * سه لحظه که UI می‌تواند نیمه‌پر باشد.
     */
    public function index(Request $request): JsonResponse
    {
        $owner = $this->ownerId();

        $themes = DB::table('site_themes')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'key', 'name', 'slug', 'layout', 'is_builtin', 'source', 'version']);

        $presets = DB::table('site_theme_presets')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'key', 'name', 'slug', 'is_builtin']);

        return response()->json([
            'data' => [
                'themes' => $themes,
                'presets' => $presets,
                'selection' => SiteThemeResolver::selection($owner),
                'tokens' => SiteThemeResolver::globalTokens(),
                'modes' => ['light', 'dark', 'system'],
            ],
        ]);
    }

    /**
     * `POST /v1/admin/site-theme/select` — انتخاب کامل (پوسته + رنگ + حالت).
     *
     * `POST` و نه `PUT`: این «انتخابِ یکی از گزینه‌های موجود» است، نه
     * جایگذاریِ یک سند. رابطه‌اش با منابعِ موجود (`site_themes`) گزینه‌محور
     * است.
     */
    public function select(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'theme_slug' => ['required', 'string', Rule::exists('site_themes', 'slug')],
            'preset_slug' => ['required', 'string', Rule::exists('site_theme_presets', 'slug')],
            'mode' => ['required', Rule::in(['light', 'dark', 'system'])],
        ], [
            'theme_slug.required' => 'انتخاب قالب الزامی است.',
            'theme_slug.exists' => 'چنین قالبی وجود ندارد.',
            'preset_slug.required' => 'انتخاب رنگ‌بندی الزامی است.',
            'preset_slug.exists' => 'چنین رنگ‌بندی‌ای وجود ندارد.',
            'mode.in' => 'حالت باید light، dark یا system باشد.',
        ]);

        $owner = $this->ownerId();

        SiteThemeResolver::select(
            $owner,
            (string) $validated['theme_slug'],
            (string) $validated['preset_slug'],
            (string) $validated['mode'],
        );

        $this->invalidate($owner);

        return $this->state($owner, 'قالب و رنگ‌بندی ذخیره شد.');
    }

    /**
     * `PUT /v1/admin/site-theme/preset` — فقط رنگ‌بندی (و اختیاری حالت).
     *
     * `PUT` چون «رنگ‌بندیِ فعال» یک تک‌مورد است و این عملیات **جایگذاریِ**
     * آن است. ضمناً override‌های کاربر دست‌نخورده می‌مانند — برخلاف `select()`.
     */
    public function preset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preset_slug' => ['required', 'string', Rule::exists('site_theme_presets', 'slug')],
            'mode' => ['sometimes', 'nullable', Rule::in(['light', 'dark', 'system'])],
        ], [
            'preset_slug.required' => 'انتخاب رنگ‌بندی الزامی است.',
            'preset_slug.exists' => 'چنین رنگ‌بندی‌ای وجود ندارد.',
        ]);

        $owner = $this->ownerId();

        try {
            SiteThemeResolver::preset(
                $owner,
                (string) $validated['preset_slug'],
                isset($validated['mode']) && is_string($validated['mode']) ? $validated['mode'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->reject($e->getMessage());
        }

        $this->invalidate($owner);

        return $this->state($owner, 'رنگ‌بندی ذخیره شد.');
    }

    /**
     * `PUT /v1/admin/site-theme/overrides` — overrideهای موردی.
     *
     * `PUT` و نه `PATCH` چون **کل** نقشهٔ override جایگذاری می‌شود: کلیدی که
     * نیامد حذف است. جایگذاریِ جزئی، «کلیدی که فکر می‌کنم هست و نیست» تولید
     * می‌کند.
     *
     * ⭐ امنیت: اعتبارسنجی روی **همین نوشتن** انجام می‌شود (نه در resolverِ
     * خواندن). رنگ باید hex باشد و نقش باید در فهرستِ بستهٔ resolver باشد،
     * وگرنه ۴۲۲ — و کاربر می‌فهمد چرا، به‌جای اینکه بی‌صدا ذخیره شود و اعمال
     * نشود.
     */
    public function overrides(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'overrides' => ['present', 'array'],
            'overrides.*' => ['nullable', 'string', 'max:80'],
        ], [
            'overrides.present' => 'بدنهٔ overrides الزامی است (می‌تواند خالی باشد).',
            'overrides.array' => 'overrides باید نقشه باشد.',
        ]);

        $owner = $this->ownerId();

        $clean = array_filter(
            (array) $validated['overrides'],
            static fn (mixed $v): bool => is_string($v) && $v !== '',
        );

        try {
            SiteThemeResolver::setOverrides($owner, $clean);
        } catch (\InvalidArgumentException $e) {
            return $this->reject($e->getMessage());
        }

        $this->invalidate($owner);

        return $this->state($owner, 'تنظیمات ذخیره شد.');
    }

    /**
     * `POST /v1/admin/site-theme/reset` — برگشت به پیش‌فرضِ درون‌ساخت.
     *
     * `reset` به‌جای `DELETE /site-theme` عمدی است: «قالب» یک رکوردِ
     * نصب‌شده است که **نمی‌میرد** (رنگ‌بندی‌اش مشترکِ N پوسته است)؛ چیزی که
     * پاک می‌شود **انتخابِ نصب** است. نامی که این تفاوت را پنهان کند، بعداً
     * یکی آن را اشتباه می‌فهمد و `site_themes` را می‌ترکاند.
     */
    public function reset(Request $request): JsonResponse
    {
        $owner = $this->ownerId();

        SiteThemeResolver::reset($owner);

        $this->invalidate($owner);

        return $this->state($owner, 'به پیش‌فرض برگشت.');
    }

    // ------------------------------------------------------------------

    /**
     * ⭐ `Q4` — صاحبِ ردیفِ انتخاب، سراسریِ نصب است.
     *
     * عمداً `$request->user()->id` **نیست**: قالبِ سایت سراسری است و بازدیدکنندهٔ
     * ناشناس هم باید همان را ببیند. اگر per-user بود، انتخابِ مدیر A روی سایتِ
     * عمومی دیده نمی‌شد و «انتخاب شد ولی سایت عوض نشد» می‌شد.
     */
    private function ownerId(): int
    {
        return SiteThemeResolver::GLOBAL_OWNER;
    }

    /**
     * سه ابطال، یک نقطه.
     *
     * عمداً هر سه پشتِ یک متد: تک‌تک فراخوانی‌کردنشان یعنی هر مسیرِ تازه باید
     * هر سه را به خاطر بسپارد، و یکی که جا بماند **بی‌صدا** خراب می‌کند (کشِ
     * ۲ دقیقه‌ای یا ISR انقضایی) — یعنی دقیقاً همان باگی که این تسک بسته شد.
     */
    private function invalidate(int $ownerId): void
    {
        SiteThemeResolver::flush($ownerId);

        // ⭐ بدون این، `site_chrome` تا ۱۲۰ ثانیه رنگِ قبلی را برمی‌گرداند.
        CachedSettings::forget('site_chrome', self::CHROME_CACHE_KEY);

        // fire-and-forget: نرسیدنِ فرانت نباید ذخیره‌شدن را برگرداند.
        try {
            app(RevalidateDispatcher::class)->dispatch(self::TAGS);
        } catch (\Throwable) {
            // انقضای خودکارِ ISR همان fallback است.
        }
    }

    /** @return array<string, mixed> */
    private function state(int $ownerId, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => [
                'selection' => SiteThemeResolver::selection($ownerId),
                'tokens' => SiteThemeResolver::globalTokens(),
            ],
        ]);
    }

    private function reject(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 422);
    }
}
