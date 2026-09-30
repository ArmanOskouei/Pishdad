<?php

namespace Database\Seeders;

use App\Services\Themes\SiteThemeResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
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

        $combos = count(self::THEMES) * count(self::PRESETS);

        $this->command?->info("  {$combos} ترکیب قالب×رنگ، هر کدام با دو نیمهٔ روشن/تاریک.");
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
