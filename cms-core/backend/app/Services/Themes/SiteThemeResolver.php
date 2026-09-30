<?php

declare(strict_types=1);

namespace App\Services\Themes;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * F4.1.C — resolver قالب فعال سایت.
 *
 * ## این کلاس چه می‌کند
 *
 * سه جدول تازه (`site_themes`, `site_theme_presets`, `site_theme_settings`) را
 * می‌خواند و **یک نقشهٔ توکن** درمی‌آورد که دقیقاً همان شکلی است که
 * `themeVars()` در فرانت مصرف می‌کند: `{ "--theme-<key>": "<value>" }`.
 *
 * یعنی قرارداد مصرف‌کننده عوض نمی‌شود — `SiteChromeController` همین را در
 * `theme.globals` می‌گذارد و فرانت همان `--theme-*` را روی کانتینر ریخته و
 * `globals.css` می‌خواند.
 *
 * ## ترتیب ادغام، و چرا همین ترتیب
 *
 * ```
 * layout_tokens  →  preset.tokens  →  overrides
 * ```
 *
 * هر لایه بعدی **قبلی را می‌پوشاند**، نه اضافه. دلیلش اولویت است: انتخاب
 * رنگِ کاربر باید بر رنگِ پیش‌فرضِ قالب بیفتد (کاربر آگاهانه انتخاب کرده)، و
 * override باید بر هر دو (ویرایش موردی، دقیق‌ترین اراده). اگر ترتیب برعکس بود،
 * انتخاب رنگ بی‌اثر می‌شد و کاربر فکر می‌کرد ذخیره نشده.
 *
 * ## چرا `mode` یک selector است، نه یک ستون رنگ
 *
 * `mode: light|dark` **کدام نیمه** از `preset.tokens` را انتخاب می‌کند. خودِ
 * رنگ‌ها یک جا می‌مانند — پس عوض‌کردن حالت، توکن جدیدی نمی‌آورد و ناسازگاری
 * رنگی ممکن نیست. `system` یعنی «از پیش‌فرضِ مرورگر پیروی کن» و در آن حالت
 * `prefers-color-scheme` تصمیم می‌گیرد، پس resolver نیمه‌ای برنمی‌گرداند و
 * CSS خودش انتخاب می‌کند.
 */
final class SiteThemeResolver
{
    public const CACHE_KEY = 'site_theme:tokens';

    public const CACHE_TTL = 300;

    /** پیش‌فرض وقتی هیچ انتخابی ثبت نشده — عمداً همان چیزی که CSS هسته دارد. */
    public const FALLBACK_THEME = 'minimal';

    public const FALLBACK_PRESET = 'azure';

    /**
     * نقش‌هایی که یک preset می‌تواند بازنویسی کند.
     *
     * ⚠️ این فهرست **نقش** است، نه مقدار، و عمداً از روی `globals.css`
     * (`--primary`, `--primary-hover`, `--primary-soft`, `--accent`,
     * `--accent-soft`, `--bg`, `--surface`, `--surface-2`, `--text`,
     * `--text-muted`, `--border`) برداشته شده.
     *
     * دو دلیل برای اینکه فهرست بسته است و نه «هر کلیدی که کسی نوشت»:
     *
     *  ۱. **امنیت.** یک توکنِ دلخواه یعنی یک نامِ CSS که فقط قالب تعیین
     *     می‌کند — و اگر روزی یک قالب `content` را بازنویسی کند، دارد به
     *     چیزی دست می‌زند که قالبِ سایت نباید مالکش باشد. فهرست بسته مرز
     *     را نگه می‌دارد.
     *  ۲. **خطای خاموش.** توکنی که در `globals.css` نباشد بی‌اثر است و هیچ‌جا
     *     خطا نمی‌دهد. تست `test_the_seed_token_names_all_exist_in_css` همین
     *     را نگه می‌دارد.
     */
    public const COLOR_ROLES = [
        'primary',
        'primary-hover',
        'primary-soft',
        'accent',
        'accent-soft',
        'bg',
        'surface',
        'surface-2',
        'text',
        'text-muted',
        'border',
    ];

    /**
     * نقش‌هایی که `layout_tokens` می‌تواند بازنویسی کند — «تنظیمات پیشرفته»
     * `ADMIN-PAGES-SPEC.md:156`. جدا از رنگ، چون این‌ها به پوسته تعلق دارند نه
     * به رنگ‌بندی: عوض‌کردن رنگ نباید شعاع گوشه را هم ببرد.
     */
    public const LAYOUT_ROLES = [
        'radius-sm',
        'radius-md',
        'radius-lg',
        'radius-xl',
        'fs-body',
        'fs-small',
        'fs-h',
        'density',
    ];

    /** کلید ردیفِ «انتخابِ فعالِ همین نصب». */
    public const ACTIVE_KEY = 'default';

    /**
     * @return array<string, string> نقشهٔ توکن آمادهٔ CSS
     */
    public static function tokensFor(?int $userId = null): array
    {
        if ($userId === null) {
            return [];
        }

        return Cache::remember(
            self::CACHE_KEY.':'.$userId,
            self::CACHE_TTL,
            static fn (): array => self::resolve($userId),
        );
    }

    /**
     * بدون کش. برای تست و برای مسیری که خودش کش را باطل کرده.
     *
     * @return array<string, string>
     */
    public static function resolve(int $userId): array
    {
        /**
         * کلیدِ ردیفِ فعال `default` است — **نه** `default:{user_id}` و نه
         * `default:{template}`.
         *
         * `F4.1.W` گفته بود جداسازی سه قالب با `key='draft:{template}'` باشد، و
         * آن برای **ویرایشگر** درست است: هر قالب یک پیش‌نویسِ جدا دارد. ولی
         * سایتِ زنده فقط **یک** انتخابِ فعال دارد و آن هم متعلق به همین نصب
         * است — که `user_id` خودش در کلید جداست. پس قالبِ فعال `default`
         * است و بقیه `draft:{template}`.
         *
         * اگر `draft:*` را هم می‌خواندیم، `orderBy('key')` می‌توانست یک پیش‌نویسِ
         * نیمه‌کاره را به‌جای انتخابِ واقعیِ کاربر نشان دهد. پس فقط `default`.
         */
        $active = DB::table('site_theme_settings')
            ->where('user_id', $userId)
            ->where('key', self::ACTIVE_KEY)
            ->first();

        $theme = $active
            ? DB::table('site_themes')->where('id', $active->theme_id)->first()
            : DB::table('site_themes')->where('slug', self::FALLBACK_THEME)->first();

        $preset = $active
            ? DB::table('site_theme_presets')->where('id', $active->preset_id)->first()
            : DB::table('site_theme_presets')->where('slug', self::FALLBACK_PRESET)->first();

        $layout = [];
        $colors = [];

        // ── layer 1: skin, layout roles only
        foreach (self::decode($theme->layout_tokens ?? null) as $k => $v) {
            if (is_string($k) && in_array($k, self::LAYOUT_ROLES, true)) {
                $layout[$k] = $v;
            }
        }

        // `system` نیمه‌ای برنمی‌گرداند: تصمیم با CSS است تا با تنظیمات مرورگر
        // هماهنگ بماند و نیازی به JS برای تشخیص نباشد.
        $mode = $active->mode ?? 'light';
        if ($mode !== 'system') {
            $tokens = self::decode($preset->tokens ?? null);
            $half = self::decode($tokens[$mode] ?? $tokens['light'] ?? []);

            // ── layer 2: colour, colour roles only
            foreach ($half as $k => $v) {
                if (is_string($k) && in_array($k, self::COLOR_ROLES, true)) {
                    $colors[$k] = $v;
                }
            }
        }

        // لایهٔ ۳ — override موردی. هر دو دسته، چون کاربر دقیقاً می‌دانسته
        // دارد چه چیزی را عوض می‌کند — ولی رنگ و چیدمان قاعدهٔ اعتبارسنجیِ متفاوت دارند.
        $overrides = self::decode($active->overrides ?? null);

        $layoutOverrides = [];
        $colorOverrides = [];
        foreach ($overrides as $k => $v) {
            if (! is_string($k)) {
                continue;
            }

            if (in_array($k, self::COLOR_ROLES, true)) {
                $colorOverrides[$k] = $v;
            } elseif (in_array($k, self::LAYOUT_ROLES, true)) {
                $layoutOverrides[$k] = $v;
            }
        }

        // override بر پایهٔ لایه‌های پایین می‌نشیند (و کلید هم‌نام را می‌بلعد).
        return array_merge(
            self::toCssVars(array_merge($layout, $layoutOverrides), self::LAYOUT_ROLES),
            self::toCssVars(array_merge($colors, $colorOverrides), self::COLOR_ROLES, colorsOnly: true),
        );
    }

    /** انتخاب ذخیره می‌شود و کش باطل می‌شود. */
    public static function select(int $userId, string $themeSlug, string $presetSlug, string $mode): void
    {
        $theme = DB::table('site_themes')->where('slug', $themeSlug)->first();
        $preset = DB::table('site_theme_presets')->where('slug', $presetSlug)->first();

        if ($theme === null || $preset === null) {
            throw new \InvalidArgumentException('قالب یا رنگ‌بندی انتخابی وجود ندارد.');
        }

        if (! in_array($mode, ['light', 'dark', 'system'], true)) {
            throw new \InvalidArgumentException('حالت نامعتبر است.');
        }

        DB::table('site_theme_settings')->updateOrInsert(
            ['user_id' => $userId, 'key' => self::ACTIVE_KEY],
            [
                'theme_id' => $theme->id,
                'preset_id' => $preset->id,
                'mode' => $mode,
                'overrides' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        self::flush($userId);
    }

    public static function flush(?int $userId = null): void
    {
        if ($userId !== null) {
            Cache::forget(self::CACHE_KEY.':'.$userId);

            return;
        }

        // نبودِ کاربر یعنی «همه» — ولی `Cache::flush()` کل Redis را می‌ریزد که
        // در این پروژه اشتراکی است (نصب‌های دیگر). پس فقط کلید قالب پاک می‌شود
        // و بقیهٔ کش دست‌نخورده می‌ماند.
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * نقشهٔ توکن → نقشهٔ متغیر CSS.
     *
     * @param  array<string, mixed>  $tokens
     * @param  list<string>  $allowed
     * @param  bool  $colorsOnly  برای نقش‌های رنگ، قاعده سخت‌گیرانه‌تر می‌شود.
     * @return array<string, string>
     */
    private static function toCssVars(array $tokens, array $allowed, bool $colorsOnly = false): array
    {
        $out = [];

        foreach ($tokens as $key => $value) {
            // نقشِ کثیف یا ناشناخته: بی‌صدا می‌رود. یعنی افزونه/قالب نمی‌تواند
            // هر متغیری که خواست تعریف کند — فقط نقش‌های فهرست‌شده.
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                continue;
            }

            if (! is_string($value) || $value === '') {
                continue;
            }

            // مقدار هم باید بی‌خطر باشد: `var(...)`، `url(...)` و `;` می‌توانند از
            // یک رشتهٔ ظاهراً بی‌خطر، استایل دلخواه بسازند — و `url()` راه
            // درخواست از مرورگر کاربر به هر مقصدی است.
            //
            // برای رنگ، یک گام سخت‌گیرانه‌تر: فقط hex. نام‌های رنگِ معتبرِ CSS مثل
            // `red` یا `transparent` در resolver معنای یکسانی ندارند و بازکردنِ
            // درشان برای `var()`/`url()` سطح حمله را بی‌فایده بزرگ می‌کند.
            $safe = $colorsOnly
                ? preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value) === 1
                : preg_match('/^[#a-z0-9(),.%\-_ ]+$/i', $value) === 1;

            if (! $safe) {
                continue;
            }

            $out['--theme-'.$key] = $value;
        }

        return $out;
    }

    private static function cacheKeyless(array $tokens): array
    {
        return $tokens;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
