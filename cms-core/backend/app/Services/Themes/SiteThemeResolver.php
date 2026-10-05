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
     * ⭐ `Q4` — صاحبِ «انتخابِ سراسریِ نصب».
     *
     * تصمیمِ محصول ثبت‌شده: قالبِ سایت **سراسریِ نصب** است، نه per-user. یعنی
     * بازدیدکنندهٔ ناشناس هم باید همان قالبی را ببیند که مدیر انتخاب کرده — و
     * بازدیدکننده هیچ `user_id` ندارد.
     *
     * چرا `0` و نه «کاربرِ سازنده» یا «اولین مدیر»:
     *  - «کاربرِ سازنده» با هر کاربر جدید عوض می‌شود ⇒ قالب بی‌صدا می‌پرد.
     *  - «اولین مدیر» یعنی `users` باید خوانده شود ⇒ یک وابستگی که
     *    `site_chrome` (که کاربر ندارد) نباید داشته باشد.
     *  - `0` یک ردیفِ ثابت است که **هیچ کاربری نمی‌تواند آن را بازنویسی کند**
     *    مگر از مسیر عمومی `site-theme/*` که دقیقاً مالکِ همین تصمیم است.
     *    و `site_theme_settings` روی `user_id` قیدِ خارجی ندارد (فقط روی
     *    `theme_id`/`preset_id` دارد) ⇒ لازم هم هست.
     */
    public const GLOBAL_OWNER = 0;

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
     * `Q4` — توکن‌های سراسریِ نصب، برای بازدیدکنندهٔ ناشناس.
     *
     * تفاوت با `tokensFor(null)` عمدی است: آن یکی **خالی** برمی‌گرداند چون
     * بدون کاربر نمی‌تواند انتخابی را پیدا کند. ولی `Q4` می‌گوید بازدیدکنندهٔ
     * ناشناس هم قالب را ببیند — پس این باید به ردیفِ `GLOBAL_OWNER` نگاه کند،
     * نه اینکه بی‌دلیل خالی باشد.
     *
     * اگر هنوز ردیفی ثبت نشده باشد، `resolve()` به `FALLBACK_THEME`/
     * `FALLBACK_PRESET` می‌افتد — یعنی حتی نصبِ کاملاً تازه هم یک قالبِ
     * معتبر و رنگی می‌بیند، نه صفحهٔ بی‌رنگ.
     *
     * @return array<string, string>
     */
    public static function globalTokens(): array
    {
        return Cache::remember(
            self::CACHE_KEY.':global',
            self::CACHE_TTL,
            static fn (): array => self::resolve(self::GLOBAL_OWNER),
        );
    }

    /**
     * ⭐ همان نقشه، ولی با کلیدِ **نقش** — بدون پیشوندِ `--theme-`.
     *
     * چرا این تبدیل لازم است و چرا این‌جا، نه در مصرف‌کننده:
     *
     * `SiteChromeController` خروجی‌اش را در `theme.globals` می‌گذارد و
     * `themeVars()` فرانت خودش `--theme-` را به کلید اضافه می‌کند. یعنی آن
     * فضا **نقش** است. ولی resolver نقشه‌اش را به شکل `--theme-<role>` برمی‌گرداند
     * (قراردادِ `site_chrome` قبلی همین است).
     *
     * اگر این‌ها بدون تبدیل merge شوند، دو کلیدِ **متفاوت** می‌شوند — `primary`
     * از مانیفست و `--theme-primary` از resolver — و `themeVars()` هر دو را به
     * `--theme-primary` تبدیل می‌کند. کدامش برنده شود به **ترتیب درج** بستگی
     * دارد، نه به منطق. در عمل پایهٔ مانیفست می‌برد و انتخابِ کاربر بی‌اثر می‌شد:
     * «ذخیره شد، ۲۰۰، سایت همان رنگِ قبلی.» دقیقاً همان باگی که این ردیف برای
     * بستنش نوشته شده.
     *
     * @return array<string, string>
     */
    public static function bareTokens(?int $userId = null): array
    {
        $vars = $userId === self::GLOBAL_OWNER ? self::globalTokens() : self::tokensFor($userId);

        $out = [];
        foreach ($vars as $var => $value) {
            if (str_starts_with((string) $var, '--theme-')) {
                $out[substr((string) $var, strlen('--theme-'))] = $value;
            }
        }

        return $out;
    }

    /**
     * ⭐ آیا این اسلاگ یک **پوستهٔ درون‌ساختِ شناخته‌شده** است؟
     *
     * برای همگام‌کردنِ دو ویرایشگر (`ThemeController::activate` در برابر
     * `site-theme/select`): اسلاگی که در `site_themes` نیست یعنی بستهٔ ZIPِ
     * بیرونی، و حدس‌زدنِ پوستهٔ آن یعنی ساختنِ چیزی که مالکش نیست.
     */
    public static function isBuiltinSlug(string $slug): ?string
    {
        $row = DB::table('site_themes')
            ->where('slug', $slug)
            ->where('is_builtin', true)
            ->first();

        return $row === null ? null : (string) $row->slug;
    }

    /**
     * ⭐ ECO1 — اسلاگِ فعالِ قالب برای انتخابِ کامپوننتِ فرانت.
     *
     * منبعِ اول انتخابِ **سراسریِ نصب** در `site_theme_settings` است (همان
     * `Q4`). اگر ردیفی نباشد (نصبِ تازه یا مسیرِ قدیمیِ فعال‌سازیِ ZIP) به
     * `themes.active` برمی‌گردیم، و در نهایت به `FALLBACK_THEME`.
     *
     * چرا هیچ‌وقت `null`/خطا نمی‌دهد: فرانت روی این اسلاگ کامپوننت انتخاب
     * می‌کند. مقدارِ خالی یعنی «قالب را نتوانستم تعیین کنم» و آن‌وقت مرورگر
     * صفحهٔ سفید نشان می‌دهد. قراردادِ ECO1 این است که اسلاگِ ناشناخته به
     * قالبِ پیش‌فرض بیفتد — پس اینجا هم باید همیشه یک اسلاگِ معتبر بدهیم.
     *
     * جایگزینِ `default` (placeholderِ بی‌توکن) عمداً رد می‌شود تا سایتِ
     * بی‌رنگِ تسک ۴.۲ هرگز فعال به‌نظر نرسد.
     */
    public static function activeSlug(int $ownerId = self::GLOBAL_OWNER): string
    {
        $active = self::activeRow($ownerId);
        if ($active !== null) {
            $slug = DB::table('site_themes')->where('id', $active->theme_id)->value('slug');
            if (is_string($slug) && $slug !== '') {
                return $slug;
            }
        }

        $legacy = DB::table('themes')->where('active', true)->value('slug');
        if (is_string($legacy) && $legacy !== '' && $legacy !== 'default') {
            return $legacy;
        }

        return self::FALLBACK_THEME;
    }

    /**
     * وضعیتِ فعلی برای پاسخِ `GET /site-theme` — بدون کش، چون بلافاصله بعد از
     * هر `PUT` خوانده می‌شود و کشِ پنج‌دقیقه‌ای گمراه‌کننده است.
     *
     * @return array{theme_slug: string|null, preset_slug: string|null, mode: string, overrides: array<string, string>}
     */
    public static function selection(int $ownerId = self::GLOBAL_OWNER): array
    {
        $row = DB::table('site_theme_settings')
            ->where('user_id', $ownerId)
            ->where('key', self::ACTIVE_KEY)
            ->first();

        if ($row === null) {
            $theme = DB::table('site_themes')->where('slug', self::FALLBACK_THEME)->first();
            $preset = DB::table('site_theme_presets')->where('slug', self::FALLBACK_PRESET)->first();

            return [
                'theme_slug' => $theme->slug ?? self::FALLBACK_THEME,
                'preset_slug' => $preset->slug ?? self::FALLBACK_PRESET,
                'mode' => 'light',
                'overrides' => [],
            ];
        }

        return [
            'theme_slug' => DB::table('site_themes')->where('id', $row->theme_id)->value('slug'),
            'preset_slug' => DB::table('site_theme_presets')->where('id', $row->preset_id)->value('slug'),
            'mode' => (string) $row->mode,
            'overrides' => self::sanitizeOverrides(self::decode($row->overrides)),
        ];
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

        self::assertMode($mode);

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

            // ⭐ `GLOBAL_OWNER` زیر کلیدِ `:global` کش می‌شود، نه `:0`. بدون این
            // شرط، `flush(0)` کلیدِ اشتباهی را پاک می‌کند و رنگ تا پنج دقیقه
            // کهنه می‌ماند — یعنی «ذخیره شد، ۲۰۰، ولی سایت عوض نشد».
            if ($userId === self::GLOBAL_OWNER) {
                Cache::forget(self::CACHE_KEY.':global');
            }

            return;
        }

        // نبودِ کاربر یعنی «همه» — ولی `Cache::flush()` کل Redis را می‌ریزد که
        // در این پروژه اشتراکی است (نصب‌های دیگر). پس فقط کلید قالب پاک می‌شود
        // و بقیهٔ کش دست‌نخورده می‌ماند.
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY.':global');
    }

    /**
     * ⭐ `F4.1.B` — عوض‌کردنِ فقط رنگ‌بندی، بدون دست‌زدن به پوسته و بدون پاک‌کردن
     * override‌ها.
     *
     * چرا `select()` این کار را نمی‌کند: `select()` «انتخاب کامل» است و طبق
     * طراحی عمداً override را `null` می‌کند — چون انتخابِ تازه یعنی «برگشتم به
     * پیش‌فرضِ این ترکیب». ولی کاربرِ صفحهٔ رنگ‌بندی فقط یک کارت را عوض می‌کند و
     * انتظار دارد تنظیماتِ ریزِ دستی‌اش بماند. دو مسیر، دو معنا.
     */
    public static function preset(int $ownerId, string $presetSlug, ?string $mode = null): void
    {
        $preset = DB::table('site_theme_presets')->where('slug', $presetSlug)->first();
        if ($preset === null) {
            throw new \InvalidArgumentException('رنگ‌بندی انتخابی وجود ندارد.');
        }

        $active = self::activeRow($ownerId);
        if ($active === null) {
            // هنوز انتخابی نیست ⇒ از راه `select()` ردیف را می‌سازد.
            self::select($ownerId, self::FALLBACK_THEME, $presetSlug, $mode ?? 'light');

            return;
        }

        DB::table('site_theme_settings')
            ->where('id', $active->id)
            ->update([
                'preset_id' => $preset->id,
                'mode' => self::assertMode($mode ?? (string) $active->mode),
                'updated_at' => now(),
            ]);

        self::flush($ownerId);
    }

    /**
     * `F4.1.B` — نوشتنِ override‌های موردی.
     *
     * ⭐ ورودی **پیش از نوشتن** با `sanitizeOverrides()` می‌گذرد و مقدارِ ردشده
     * `InvalidArgumentException` می‌اندازد. این عمداً برعکسِ خواندن است: در
     * `toCssVars()` مقدارِ بد **بی‌صدا** می‌افتد (fail-closed برای دادهٔ قدیمی و
     * دستِ افزونه)، ولی اگر همان رفتار را روی نوشتن نگه می‌داشتیم، کاربر یک رنگ
     * می‌نوشت، ذخیره می‌کرد، و بعد می‌دید اعمال نشد — بدون هیچ خطایی. اینجا
     * خطا به کاربر برمی‌گردد.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, string> همان چیزی که واقعاً ذخیره شد
     */
    public static function setOverrides(int $ownerId, array $raw): array
    {
        $clean = self::sanitizeOverrides($raw);

        $active = self::activeRow($ownerId);
        if ($active === null) {
            throw new \InvalidArgumentException('ابتدا باید یک قالب انتخاب شود.');
        }

        DB::table('site_theme_settings')
            ->where('id', $active->id)
            ->update([
                'overrides' => $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

        self::flush($ownerId);

        return $clean;
    }

    /**
     * `F4.1.B` — «برگشت به پیش‌فرض». ردیفِ انتخاب **حذف** می‌شود، نه اینکه با
     * مقادیرِ خالی پر شود: صفرِ سطر یعنی «کاربر هیچ انتخابی نکرده» و resolver
     * خودش به `FALLBACK_*` می‌افتد. نگه‌داشتنِ سطر با `overrides = null`
     * یعنی «انتخاب هست ولی override نداریم» — دو چیز متفاوت که بعداً قاطی
     * می‌شدند.
     */
    public static function reset(int $ownerId = self::GLOBAL_OWNER): void
    {
        DB::table('site_theme_settings')
            ->where('user_id', $ownerId)
            ->where('key', self::ACTIVE_KEY)
            ->delete();

        self::flush($ownerId);
    }

    /**
     * ⭐ تنها گذرگاهِ مجاز برای override — و **همان** قاعده‌ای که `toCssVars()`
     * روی خواندن اعمال می‌کند.
     *
     * یک تعریف، دو مصرف‌کننده. اگر این‌ها دو نسخه می‌داشتند، مقداری که اینجا
     * قبول می‌شد و آنجا می‌افتاد (یا برعکس) بی‌صدا از بین می‌رفت.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, string>
     *
     * @throws \InvalidArgumentException با نامِ نقش‌های ردشده
     */
    public static function sanitizeOverrides(array $raw): array
    {
        $clean = [];
        $rejected = [];

        foreach ($raw as $key => $value) {
            if (! is_string($key)) {
                $rejected[] = '(نامِ نامعتبر)';

                continue;
            }

            $colorsOnly = in_array($key, self::COLOR_ROLES, true);
            $allowed = $colorsOnly ? self::COLOR_ROLES : self::LAYOUT_ROLES;

            if (! in_array($key, $allowed, true)) {
                $rejected[] = $key;

                continue;
            }

            if (! is_string($value) || $value === '') {
                $rejected[] = $key;

                continue;
            }

            if (! self::valueIsSafe($value, $colorsOnly)) {
                $rejected[] = $key;

                continue;
            }

            $clean[$key] = $value;
        }

        if ($rejected !== []) {
            sort($rejected);

            throw new \InvalidArgumentException(
                'نقش یا مقدارِ نامعتبر: '.implode('، ', array_unique($rejected))
            );
        }

        return $clean;
    }

    /**
     * ⭐ قاعدهٔ مشترکِ «مقدارِ بی‌خطر» — بین نوشتن و خواندن.
     *
     * ۱) نقش‌های رنگ: **فقط hex** (`Q6`). نامِ رنگِ معتبرِ CSS مثل `red` در
     *    resolver معنای یکسانی ندارد و بازکردنِ درشان برای `var()`/`url()` سطح
     * حمله را بی‌فایده بزرگ می‌کند.
     * ۲) نقش‌های چیدمان: `;` می‌تواند declaration تازه بسازد و `url()` راهِ
     *    درخواست از مرورگرِ کاربر به هر مقصدی است.
     */
    private static function valueIsSafe(string $value, bool $colorsOnly): bool
    {
        return $colorsOnly
            ? preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value) === 1
            : preg_match('/^[#a-z0-9(),.%\-_ ]+$/i', $value) === 1;
    }

    private static function assertMode(string $mode): string
    {
        if (! in_array($mode, ['light', 'dark', 'system'], true)) {
            throw new \InvalidArgumentException('حالت نامعتبر است.');
        }

        return $mode;
    }

    private static function activeRow(int $ownerId): ?object
    {
        return DB::table('site_theme_settings')
            ->where('user_id', $ownerId)
            ->where('key', self::ACTIVE_KEY)
            ->first();
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
            //
            // ⚠️ قاعده در `valueIsSafe()` است، نه اینجا — چون `sanitizeOverrides()`
            // روی نوشتن **همان** قاعده را لازم دارد و دو نسخه یعنی مقداری که
            // اینجا رد می‌شود و آنجا ذخیره می‌شود.
            if (! self::valueIsSafe($value, $colorsOnly)) {
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
