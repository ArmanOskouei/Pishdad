<?php

namespace Database\Seeders;

use App\Services\Themes\SiteThemeResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * F4.1.C — سه پوسته × سه رنگ‌بندی برای سایت عمومی.
 *
 * ## چرا این اعداد
 *
 * `ADMIN-PAGES-SPEC.md:155` می‌گوید «هر قالب ۲-۳ رنگ‌بندی» و «۱۵ ترکیب کل».
 * با ۳ قالب و ۳ رنگ می‌شود ۹ ترکیبِ قالب×رنگ، و هر کدام دو نیمهٔ روشن/تاریک
 * دارند ⇒ ۱۸ حالت قابل انتخاب. یعنی «۱۵» یک عددِ تقریبی برای کلِ سیستم بوده
 * (شامل قالب‌های پنل ادمین که `ThemeSwitcher` جداگانه دارد)، نه سقفِ سایت.
 *
 * ## چرا `-soft` را دستی می‌نویسیم و نه `color-mix`
 *
 * `globals.css` از `color-mix(in srgb, var(--primary) 16%, transparent)` استفاده
 * می‌کند. ولی توکنِ ما `--theme-primary-soft` است و به `--primary` اشاره
 * نمی‌کند — و `color-mix` نمی‌تواند به متغیرِ پویا ارجاع دهد بدون اینکه
 * `var()` را داخلش بگذاریم، که با فیلترِ مقدارِ `toCssVars` نمی‌خواند
 * (عمداً: `var()` و `;` می‌توانند استایل تزریق کنند).
 *
 * پس مقدار `-soft` صریح نوشته می‌شود. این همان چیزی است که `F4.1.U` دربارهٔ
 * `--primary-soft` نوشته بود — «بازاعلام لازم است وگرنه کسی حذفش می‌کند» — و
 * اینجا اثرِ همان ریشه است: یک توکنِ محاسبه‌شده نمی‌تواند از راه داده پر شود.
 *
 * ## چرا `updateOrInsert` و نه `insert`
 *
 * seed باید idempotent باشد: `migrate --seed` روی محیطی که از قبل داده دارد
 * نباید خطا بدهد و نباید رکورد تکراری بسازد.
 */
class SiteThemeSeeder extends Seeder
{
    /**
     * سه پوسته. `layout` و `layout_tokens` تفاوتِ واقعی‌اند:
     *  - `minimal`: بدون سرصفحه/پاورقیِ سنگین، فاصله‌های تنفسی
     *  - `editorial`: ستونِ خواندنیِ باریک‌تر، تایپوگرافی درشت‌تر
     *  - `commerce`: چگال‌تر (برای فهرست کالا)، شعاع کمتر
     */
    /**
     * ⭐ قالبی که **نصبِ تازه** با آن فعال می‌شود (E79).
     *
     * همان قالبی که سایتِ نمایشیِ پیشداد با آن بالا می‌آید، تا نسخهٔ منتشرشده
     * دقیقاً همان ظاهرِ معرفی‌شده را نشان دهد. انتخابِ خودِ مدیر هرگز دست‌کاری
     * نمی‌شود — این فقط «حالتِ نبودِ انتخاب» است.
     */
    public const DEFAULT_ACTIVE = 'commerce';

    private const THEMES = [
        [
            'key' => 'minimal',
            'name' => 'مینیمال',
            'slug' => 'minimal',
            'layout' => 'editorial',
            'sort_order' => 1,
            'layout_tokens' => [
                'radius-sm' => '2px',
                'radius-md' => '4px',
                'radius-lg' => '6px',
                'radius-xl' => '8px',
                'fs-body' => '15px',
                'fs-small' => '13px',
                'fs-h' => '19px',
                'density' => '1',
            ],
        ],
        [
            'key' => 'editorial',
            'name' => 'تحریری',
            'slug' => 'editorial',
            'layout' => 'editorial',
            'sort_order' => 2,
            'layout_tokens' => [
                'radius-sm' => '0px',
                'radius-md' => '0px',
                'radius-lg' => '2px',
                'radius-xl' => '2px',
                'fs-body' => '17px',
                'fs-small' => '14px',
                'fs-h' => '24px',
                'density' => '1.15',
            ],
        ],
        [
            'key' => 'commerce',
            'name' => 'فروشگاهی',
            'slug' => 'commerce',
            'layout' => 'grid',
            'sort_order' => 3,
            'layout_tokens' => [
                'radius-sm' => '6px',
                'radius-md' => '10px',
                'radius-lg' => '14px',
                'radius-xl' => '20px',
                'fs-body' => '14px',
                'fs-small' => '12.5px',
                'fs-h' => '18px',
                'density' => '0.9',
            ],
        ],
    ];

    /**
     * سه رنگ‌بندی. هر کدام **دو نیمه** دارد (`light` و `dark`) — این همان
     * «دو مجموعهٔ مستقل» است که `F4.1.W` گفت، و به همین دلیل `tokens` یک ستون
     * است نه دو تا.
     */
    private const PRESETS = [
        [
            'key' => 'azure',
            'name' => 'آبی',
            'slug' => 'azure',
            'sort_order' => 1,
            'tokens' => [
                'light' => [
                    'primary' => '#0d9488',
                    'primary-hover' => '#0b7a70',
                    'primary-soft' => '#d6f0ec',
                    'accent' => '#b45309',
                    'accent-soft' => '#fbeedb',
                    'bg' => '#f7f7f8',
                    'surface' => '#ffffff',
                    'surface-2' => '#f0f1f3',
                    'text' => '#1a1a1e',
                    'text-muted' => '#6b7280',
                    'border' => '#e5e7eb',
                ],
                'dark' => [
                    'primary' => '#2dd4bf',
                    'primary-hover' => '#5eead4',
                    'primary-soft' => '#133b38',
                    'accent' => '#fbbf24',
                    'accent-soft' => '#3d2f10',
                    'bg' => '#0b0f17',
                    'surface' => '#121826',
                    'surface-2' => '#1a2233',
                    'text' => '#e6eaf2',
                    'text-muted' => '#8b94a7',
                    'border' => '#232c40',
                ],
            ],
        ],
        [
            'key' => 'rose',
            'name' => 'سرخابی',
            'slug' => 'rose',
            'sort_order' => 2,
            'tokens' => [
                'light' => [
                    'primary' => '#be123c',
                    'primary-hover' => '#9f1239',
                    'primary-soft' => '#fde2e8',
                    'accent' => '#7c3aed',
                    'accent-soft' => '#ede9fe',
                    'bg' => '#fdfafb',
                    'surface' => '#ffffff',
                    'surface-2' => '#f4f1f2',
                    'text' => '#1c1917',
                    'text-muted' => '#78716c',
                    'border' => '#e7e5e4',
                ],
                'dark' => [
                    'primary' => '#fb7185',
                    'primary-hover' => '#fda4af',
                    'primary-soft' => '#3d1a22',
                    'accent' => '#a78bfa',
                    'accent-soft' => '#2a2140',
                    'bg' => '#140f11',
                    'surface' => '#1d1518',
                    'surface-2' => '#291d21',
                    'text' => '#f5f0f1',
                    'text-muted' => '#a8a29e',
                    'border' => '#33262a',
                ],
            ],
        ],
        [
            'key' => 'forest',
            'name' => 'جنگلی',
            'slug' => 'forest',
            'sort_order' => 3,
            'tokens' => [
                'light' => [
                    'primary' => '#15803d',
                    'primary-hover' => '#166534',
                    'primary-soft' => '#dcf5e5',
                    'accent' => '#ca8a04',
                    'accent-soft' => '#fbf3d6',
                    'bg' => '#f8faf8',
                    'surface' => '#ffffff',
                    'surface-2' => '#eef4ef',
                    'text' => '#14201a',
                    'text-muted' => '#5f7168',
                    'border' => '#dde7e0',
                ],
                'dark' => [
                    'primary' => '#4ade80',
                    'primary-hover' => '#86efac',
                    'primary-soft' => '#153122',
                    'accent' => '#fde047',
                    'accent-soft' => '#3a320f',
                    'bg' => '#0a1210',
                    'surface' => '#101a16',
                    'surface-2' => '#17251f',
                    'text' => '#eaf3ed',
                    'text-muted' => '#94a89b',
                    'border' => '#1f2f27',
                ],
            ],
        ],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::THEMES as $theme) {
            DB::table('site_themes')->updateOrInsert(
                ['slug' => $theme['slug']],
                [
                    'key' => $theme['key'],
                    'name' => $theme['name'],
                    'layout' => $theme['layout'],
                    'layout_tokens' => json_encode($theme['layout_tokens'], JSON_UNESCAPED_UNICODE),
                    'sort_order' => $theme['sort_order'],
                    'is_builtin' => true,
                    'source' => 'builtin',
                    'version' => '1.0.0',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        foreach (self::PRESETS as $preset) {
            DB::table('site_theme_presets')->updateOrInsert(
                ['slug' => $preset['slug']],
                [
                    'key' => $preset['key'],
                    'name' => $preset['name'],
                    'tokens' => json_encode($preset['tokens'], JSON_UNESCAPED_UNICODE),
                    'sort_order' => $preset['sort_order'],
                    'is_builtin' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $this->assertVocabulary();

        $this->seedLegacyThemes($now);

        $this->seedDefaultSelection($now);

        $combos = count(self::THEMES) * count(self::PRESETS);

        $this->command?->info("  {$combos} ترکیب قالب×رنگ، هر کدام با دو نیمهٔ روشن/تاریک.");
    }

    /**
     * ⭐ `Q5` + `F4.1.B` — سه قالب را در جدولِ `themes` هم از **کد** seed می‌کند.
     *
     * ## چرا اصلاً جدولِ دوم
     *
     * `site_themes` (F4.1.C) پوسته و رنگ را جدا نگه می‌دارد — درست برای ویرایشگر.
     * ولی `SiteChromeController` (که **بازدیدکنندهٔ ناشناس** می‌خواند) از جدولِ
     * `themes` می‌خواند و `manifest.globals` را در پاسخ می‌گذارد. بدون سطر در آن
     * جدول، پاسخ `theme: null` می‌شود ⇒ سایت هیچ توکنی نمی‌گیرد ⇒ «قالب انتخاب
     * کردم ولی سایت هیچ تغییری نکرد».
     *
     * ## چرا از کد و نه از استخراجِ ZIP
     *
     * `Q5` این را قید کرده: قالب‌ها از **کد** seed می‌شوند. استخراجِ ZIP برای
     * قالبِ درون‌ساخت یعنی بسته‌ای که باید در repo باشد و با هر تغییرِ رنگ
     * دوباره ساخته شود — و یکبار که باشد، منبعِ حقیقت دو تا می‌شود. اینجا
     * `THEMES`/`PRESETS` بالا تنها منبع‌اند و `themes` فقط **مشتق** از آن‌هاست.
     *
     * ## چرا `globals` ترکیبِ رنگ + چیدمان است
     *
     * `themeVars()` فرانت کلید را `--theme-{key}` می‌کند و `globals.css` همان
     * نقش‌ها را می‌خواند. پس شکلِ درست `role → value` است (نه `--theme-role`)، و
     * محتوایش باید هر دو دسته نقش را داشته باشد وگرنه قالب ناقص رندر می‌شود.
     *
     * نیمهٔ `light` از رنگ‌بندیِ پیش‌فرض (`azure`) گرفته می‌شود: قالب به‌تنهایی
     * رنگ ندارد (رنگ مشترکِ N پوسته است) و بدون رنگ، سایت رنگِ پیش‌فرضِ CSS
     * هسته را می‌گیرد که با چیدمانِ قالب جور نیست.
     */
    private function seedLegacyThemes(mixed $now): void
    {
        if (! Schema::hasTable('themes')) {
            return;
        }

        $light = $this->presetTokens('azure', 'light');
        $slugs = array_column(self::THEMES, 'slug');

        /**
         * ⭐ قالبِ فعالِ *قبل* از seed، بدون placeholderِ `default`.
         *
         * انتخابِ کاربر باید باقی بماند: اگر مدیر قالبی را فعال کرده باشد،
         * `migrate --seed` نباید یواشکی برگرداندش. ولی `default` (placeholderِ
         * تسک ۴.۲، با **صفر** توکن طراحی) استثناست: فعال‌بودنش یعنی سایت بی‌رنگ
         * است، که دقیقاً همان چیزی است که `Q4` می‌خواهد درست شود.
         */
        $previouslyActive = DB::table('themes')
            ->where('active', true)
            ->where('slug', '!=', 'default')
            ->pluck('slug')
            ->all();

        foreach (self::THEMES as $theme) {
            $globals = array_merge($light, $theme['layout_tokens']);

            // `path` عمداً null است: این قالب از ZIP نیامده و فایلی پشتش نیست.
            // `ThemeController::destroy` وقتی `path` خالی باشد چیزی حذف نمی‌کند.
            $row = [
                'slug' => $theme['slug'],
                'name' => $theme['name'],
                'version' => '1.0.0',
                'path' => null,
                'manifest' => json_encode([
                    'name' => $theme['name'],
                    'slug' => $theme['slug'],
                    'version' => '1.0.0',
                    'source' => 'builtin',
                    'builtin' => true,
                    'layout' => $theme['layout'],
                    'globals' => $globals,
                ], JSON_UNESCAPED_UNICODE),
                // فعالیت بعداً و **یک‌بار** تعیین می‌شود، چون به وضعیتِ قبل نیاز
                // دارد — نه در حلقه.
                'active' => false,
                'signature_valid' => false,
                'review_status' => 'approved',
                'updated_at' => $now,
            ];

            $exists = DB::table('themes')->where('slug', $theme['slug'])->first();

            if ($exists === null) {
                // `user_id` nullable است و قیدِ خارجی به `users` دارد، پس `null`
                // می‌ماند نه `SiteThemeResolver::GLOBAL_OWNER` — قالبِ سراسریِ نصب
                // به هیچ کاربری تعلق ندارد و عددِ ساختگی قید را می‌شکند.
                DB::table('themes')->insert($row + ['user_id' => null, 'created_at' => $now]);

                continue;
            }

            // رکوردِ موجود با همان اسلاگ ولی **بدون** پرچم `builtin` مالِ کس دیگری
            // است (مثلاً ZIPی که کاربر با همین نام آپلود کرده) ⇒ دست‌نخورده.
            $isBuiltin = (bool) (json_decode((string) $exists->manifest, true)['builtin'] ?? false);
            if (! $isBuiltin) {
                continue;
            }

            $row['active'] = (bool) $exists->active;
            DB::table('themes')->where('id', $exists->id)->update($row);
        }

        $this->settleActiveTheme($slugs, $previouslyActive);
    }

    /**
     * ⭐ دقیقاً یک قالبِ فعال — و این شرط پیش از `F4.1.B` برقرار نبود.
     *
     * `SiteChromeController` می‌نویسد `Theme::where('active', true)->first()` و
     * `first()` روی چند سطرِ فعال **بی‌ترتیب** است. یعنی با placeholderِ
     * `default` که `ensureDefault()` با `active = true` می‌سازد، نصبِ تازه
     * می‌توانست قالبِ بی‌رنگ را نشان بدهد یا با هر بارِ seed جابه‌جا شود.
     */
    /**
     * ⭐ E79 — «انتخابِ فعال» در سیستمِ جدید هم باید از قبل باشد.
     *
     * پیش از این، seeder فقط `themes.active` را می‌نشاند. ولی
     * `SiteThemeResolver::selection()` از `site_theme_settings` می‌خواند، پس
     * ویرایشگرِ سه‌قالبه در پنل «قالبِ دیگری» نشان می‌داد در حالی که سایت با
     * قالبِ فعال رندر می‌شد — یعنی دو مسیر، دو حقیقت.
     *
     * اینجا سطرِ پیش‌فرض را می‌سازیم (قالب = `DEFAULT_ACTIVE`، رنگ = `azure`،
     * حالت = `light`) تا هر دو مسیر یکی باشند. اگر مدیر قبلاً چیزی انتخاب کرده
     * باشد، همان می‌ماند.
     */
    private function seedDefaultSelection(mixed $now): void
    {
        if (! Schema::hasTable('site_theme_settings')
            || ! Schema::hasTable('site_themes')
            || ! Schema::hasTable('site_theme_presets')) {
            return;
        }

        $owner = SiteThemeResolver::GLOBAL_OWNER;

        if (DB::table('site_theme_settings')->where('user_id', $owner)->exists()) {
            return;
        }

        $theme = DB::table('site_themes')->where('slug', self::DEFAULT_ACTIVE)->first();
        $preset = DB::table('site_theme_presets')->where('slug', 'azure')->first();

        if ($theme === null || $preset === null) {
            return;
        }

        DB::table('site_theme_settings')->insert([
            'user_id' => $owner,
            'key' => SiteThemeResolver::ACTIVE_KEY,
            'theme_id' => $theme->id,
            'preset_id' => $preset->id,
            'mode' => 'light',
            'overrides' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function settleActiveTheme(array $builtinSlugs, array $previouslyActive): void
    {
        $keep = array_values(array_intersect($previouslyActive, $builtinSlugs));

        /*
         * ⭐ E79 — قالبِ پیش‌فرضِ نصبِ تازه = `commerce`.
         *
         * قبلاً `$builtinSlugs[0]` (یعنی `minimal`) فعال می‌شد، پس نسخهٔ منتشر
         * با ظاهری بالا می‌آمد که با سایتِ نمایشیِ پیشداد فرق داشت. حالا هر
         * نصبِ تازه دقیقاً همان قالبی را می‌بیند که در نسخهٔ زنده فعال است.
         *
         * اگر قالبِ commerce نبود (نسخه‌ای که آن را ندارد) همان رفتارِ قبلی
         * حفظ می‌شود — یعنی اولین درون‌ساخت.
         */
        $preferred = in_array(self::DEFAULT_ACTIVE, $builtinSlugs, true)
            ? self::DEFAULT_ACTIVE
            : ($builtinSlugs[0] ?? null);

        // انتخابِ قبلی میان درون‌ساخت‌ها بود ⇒ همان می‌ماند.
        // نبود (یا placeholder) ⇒ قالبِ پیش‌فرضِ محصول.
        $winner = $keep[0] ?? $preferred;

        if ($winner === null) {
            return;
        }

        DB::table('themes')->whereIn('slug', $builtinSlugs)->update(['active' => false]);
        DB::table('themes')->where('slug', $winner)->update(['active' => true]);

        // placeholderِ تسک ۴.۲ از صحنه بیرون می‌رود: صفر توکن دارد و فعالیتش
        // تنها کاری که می‌کند این است که سایت را بی‌رنگ نگه دارد. حذف
        // نمی‌شود — `ThemeController::ensureDefault()` و آزمون‌های `K5` به
        // وجودش تکیه دارند.
        DB::table('themes')->where('slug', 'default')->update(['active' => false]);
    }

    /** @return array<string, string> نقش رنگ از نیمهٔ خواسته‌شدهٔ یک رنگ‌بندی */
    private function presetTokens(string $presetKey, string $mode): array
    {
        foreach (self::PRESETS as $preset) {
            if ($preset['key'] === $presetKey) {
                return $preset['tokens'][$mode] ?? [];
            }
        }

        return [];
    }

    /**
     * نگهبان: هر نقشی که seed می‌نویسد باید در فهرست نقش‌های resolver باشد و
     * برعکس.
     *
     * بدون این، یک نقشِ تازه در seed اضافه می‌شود، بی‌سروصدا از resolver رد
     * می‌شود، و قالب «رنگش اعمال نمی‌شود» — که عیبی است که هیچ‌جا خطا نمی‌دهد.
     */
    private function assertVocabulary(): void
    {
        $allowed = array_merge(SiteThemeResolver::COLOR_ROLES, SiteThemeResolver::LAYOUT_ROLES);

        foreach (self::PRESETS as $preset) {
            foreach (['light', 'dark'] as $half) {
                foreach (array_keys($preset['tokens'][$half]) as $role) {
                    if (! in_array($role, SiteThemeResolver::COLOR_ROLES, true)) {
                        throw new RuntimeException("نقش رنگ ناشناخته در seed «{$preset['key']}.{$half}»: {$role}");
                    }
                }
            }
        }

        foreach (self::THEMES as $theme) {
            foreach (array_keys($theme['layout_tokens']) as $role) {
                if (! in_array($role, SiteThemeResolver::LAYOUT_ROLES, true)) {
                    throw new RuntimeException("نقش چیدمان ناشناخته در seed «{$theme['key']}»: {$role}");
                }
            }
        }

        // و برعکس: نقشی که در فهرست باشد ولی seed ننویسد یعنی یک نقشِ
        // غیرقابل‌انتخاب که فکر می‌کنیم کار می‌کند.
        $seeded = [];
        foreach (self::PRESETS as $p) {
            foreach (['light', 'dark'] as $half) {
                $seeded = array_merge($seeded, array_keys($p['tokens'][$half]));
            }
        }
        foreach (self::THEMES as $t) {
            $seeded = array_merge($seeded, array_keys($t['layout_tokens']));
        }
        $seeded = array_unique($seeded);

        $missing = array_diff($allowed, $seeded);
        if ($missing !== []) {
            throw new RuntimeException('نقش‌هایی که resolver می‌شناسد ولی seed پرشان نمی‌کند: '.implode('، ', $missing));
        }
    }
}
