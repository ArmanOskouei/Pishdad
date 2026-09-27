<?php

namespace App\Services\Plugins;

use App\Models\Plugin;
use App\Models\Theme;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * رجیستری اعلانی مانیفست پلاگین‌ها (تسک ۴ + ۶ + ۷).
 *
 * هسته خام + پلاگین اعلانی: پلاگین با manifest.json اعلام می‌کند چه ماژول
 * دسترسی (permissions)، چه ویجت (widgets) و چه نوع صفحه (page_types) اضافه
 * می‌کند؛ این سرویس همان‌ها را با رجیستری هسته (config) ادغام می‌کند تا
 * ماتریس نقش‌ها، schema ویجت‌ها و تب‌های بلوک‌ها داینامیک باشند (نه هاردکد).
 *
 * فرمت‌ها در docs/PLUGIN-MANIFEST-CONTRACT.md مستند است.
 */
class ManifestRegistry
{
    public const ACTIONS = ['view', 'edit', 'delete'];

    /** کلید و TTL کش مانیفست‌های فعال (فاز ۰ / K1.8). */
    public const CACHE_KEY = 'plugin:manifests:active';

    public const CACHE_TTL = 300;

    public const ACTION_FA = [
        'view' => 'مشاهده',
        'edit' => 'ویرایش',
        'delete' => 'حذف',
    ];

    /** برچسب فارسی هر نوع ادغام — فقط برای پیام گزارش برخورد. */
    private const KIND_FA = [
        'permission' => 'ماژول دسترسی',
        'widget' => 'ویجت',
        'page_type' => 'نوع صفحه',
        'settings' => 'فرم تنظیمات',
    ];

    /** @var array<string, array<int, array<string, string>>> برخوردهای آخرین ادغام، به تفکیک kind. */
    private static array $collisions = [];

    /** @var array<string, true> کلید برخوردهایی که لاگ شده‌اند (ضد سیل لاگ در هر درخواست). */
    private static array $logged = [];

    /**
     * برخوردهای ادغام آخر — قابل خواندن برای پنل سلامت پلاگین و برای تست.
     *
     * بدون این، «رد شدن» یک ورودی فقط یک `continue` بی‌صدا بود: پلاگین نصب
     * می‌شد، رکوردش ساخته می‌شد، ولی هیچ‌جا دیده نمی‌شد و کاربر فکر می‌کرد
     * کار می‌کند. حالا هر رد با منبع نگه‌دارنده و منبع بازنده قابل ممیزی است.
     *
     * @return array<int, array<string, string>>|array<string, array<int, array<string, string>>>
     */
    public static function collisions(?string $kind = null): array
    {
        if ($kind !== null) {
            return self::$collisions[$kind] ?? [];
        }

        return self::$collisions;
    }

    /**
     * ثبت برخورد: اولویت با «kept» می‌ماند و بازنده گزارش می‌شود.
     *
     * چرا هشدار و نه خطا: این متدها روی مسیر خواندن/رندر صدا زده می‌شوند
     * (ماتریس نقش‌ها، پالت ویجت‌ها، تب بلوک‌ها) و برای هر بازدیدکننده اجرا
     * می‌شوند — پرتاب استثنا یعنی یک مانیفست سوم‌طرف، کل سایت را از کار
     * می‌اندازد و آن هم به‌جای نصب‌کننده، برای بازدیدکننده. نقطهٔ درستِ
     * رد سخت‌گیرانه، اعتبارسنجی زمان نصب است
     * (`PluginPackageContract`/`PluginPackageValidator`) که مال این فایل
     * نیست. اینجا اولویت قطعی حفظ می‌شود تا رندر ناپایدار نشود، ولی رد
     * دیگر بی‌صدا نمی‌ماند.
     */
    private static function recordCollision(string $kind, string $key, string $kept, string $dropped): void
    {
        $entry = [
            'kind' => $kind,
            'key' => $key,
            'kept' => $kept,
            'dropped' => $dropped,
            'message' => sprintf(
                '%s «%s» از «%s» تکراری بود و اعمال نشد؛ «%s» اولویت دارد.',
                self::KIND_FA[$kind] ?? $kind,
                $key,
                $dropped,
                $kept,
            ),
        ];

        self::$collisions[$kind][] = $entry;

        $fingerprint = implode('|', [$kind, $key, $kept, $dropped]);
        if (isset(self::$logged[$fingerprint])) {
            return;
        }
        self::$logged[$fingerprint] = true;

        Log::warning('plugin.manifest_collision', $entry);
    }

    /**
     * ماژول‌های دسترسی: هسته (config/modules.php) + پلاگین‌های فعال کاربر.
     * خروجی: [{name, title_fa, source}] — source = core | plugin:{slug}
     */
    public static function permissionModules(): array
    {
        $modules = [];
        $owner = [];
        foreach (config('modules', []) as $name => $def) {
            $modules[] = [
                'name' => $name,
                'title_fa' => $def['title_fa'] ?? $name,
                'source' => 'core',
            ];
            $owner[$name] = 'core';
        }

        self::$collisions['permission'] = [];

        foreach (self::activeManifests() as $slug => $manifest) {
            foreach (self::manifestPermissions($manifest, $slug) as $entry) {
                // B16: کلید مالکیت و نام نمایشی هر دو namespaced می‌شوند،
                // وگرنه دو افزونه با `module: "reports"` یک ردیف مشترک
                // می‌ساختند و تیک‌زدن یکی، دسترسی دیگری را می‌داد.
                $module = $entry['name'];
                $source = "plugin:{$slug}";
                if (isset($owner[$module])) {
                    self::recordCollision('permission', $module, $owner[$module], $source);

                    continue;
                }
                $owner[$module] = $source;
                $modules[] = [
                    'name' => $module,
                    'title_fa' => $entry['title_fa'],
                    'source' => $source,
                ];
            }
        }

        return $modules;
    }

    /**
     * schema ویجت‌ها: هسته (config/widgets.php) + مانیفست پلاگین‌های فعال +
     * قالب فعال (هر دو با همان شکل config). خروجی تخت با فیلد area.
     */
    public static function widgetSchemas(): array
    {
        $out = [];
        $owner = [];
        foreach (['header', 'footer'] as $area) {
            foreach (config("widgets.{$area}", []) as $type => $def) {
                $out[] = [
                    'area' => $area,
                    'type' => $type,
                    'title' => $def['title'] ?? $type,
                    'description' => $def['description'] ?? null,
                    'schema' => $def['schema'] ?? ['type' => 'object'],
                    'ui' => $def['ui'] ?? [],
                    'source' => 'core',
                ];
                $owner["{$area}:{$type}"] = 'core';
            }
        }

        self::$collisions['widget'] = [];

        $merge = function (array $manifest, string $source) use (&$out, &$owner): void {
            $widgets = $manifest['widgets'] ?? null;
            if (! is_array($widgets)) {
                return;
            }
            foreach (['header', 'footer'] as $area) {
                if (! is_array($widgets[$area] ?? null)) {
                    continue;
                }
                foreach ($widgets[$area] as $type => $def) {
                    if (! is_string($type) || ! is_array($def)) {
                        continue;
                    }
                    // type تکراری بازنویسی نمی‌شود — اولویت با نگه‌دارندهٔ قبلی است،
                    // ولی بازنده گزارش می‌شود تا «نصب شد ولی دیده نشد» ممکن نباشد.
                    $key = "{$area}:{$type}";
                    if (isset($owner[$key])) {
                        self::recordCollision('widget', $key, $owner[$key], $source);

                        continue;
                    }
                    $owner[$key] = $source;
                    $out[] = [
                        'area' => $area,
                        'type' => $type,
                        'title' => $def['title'] ?? $type,
                        'description' => $def['description'] ?? null,
                        'schema' => $def['schema'] ?? ['type' => 'object'],
                        'ui' => $def['ui'] ?? [],
                        'source' => $source,
                    ];
                }
            }
        };

        foreach (self::activeManifests() as $slug => $manifest) {
            $merge($manifest, "plugin:{$slug}");
        }

        $theme = Theme::query()->where('active', true)->first();
        if ($theme && is_array($theme->manifest)) {
            $merge($theme->manifest, 'theme:'.($theme->slug ?? 'active'));
        }

        return $out;
    }

    /**
     * فرم‌های تنظیمات افزونه — K6.7.
     *
     * ## چرا کلید سطح‌بالا و نه `panel.extensions`
     *
     * الگوی زندهٔ همین کلاس `widgets` و `page_types` است: هر دو کلید سطح‌بالای
     * مانیفست‌اند، نه نقطهٔ اتصال. دلیلش ساختاری است — `panel.extensions` هر اعلان
     * را در برابر یک **میکرو-اسکیما** با `max_depth => 1` می‌سنجد، و اسکیمای
     * JSON یک شیء تودرتو است، پس جای دادنش آنجا یعنی یا بالا بردن سقف عمق برای
     * همهٔ نقاط، یا باز کردن سیستمی که بسته شده بود.
     *
     * `admin.settings_schema` هم دقیقاً همین مشکل را داشت: `fields => []` با
     * `open_schema => false` یعنی `no_schema` خطای سخت، پس **هیچ مانیفستی قانوناً
     * نمی‌توانست آن را پر کند**. نقطهٔ اتصالی که هیچ‌کس نمی‌تواند پرش کند، نقطهٔ
     * اتصال نیست — یک وعدهٔ بدون مصرف‌کننده است.
     *
     * ## چرا بدون `permission`
     *
     * برخلاف `admin.menu`، این فرم داده است نه ناوبری: فیلتر پرمیشن روی خودِ
     * فرم انجام می‌شود (هر مدیر فقط فیلدهایی را می‌بیند که مجاز است)، نه اینجا.
     * اگر بعداً لازم شد، `permission` به‌عنوان کلید اعلانی اضافه می‌شود، ولی
     * الان اضافه‌کردنش یعنی ادعای قاعده‌ای که هیچ‌کس اجرا نمی‌کند.
     *
     * @return list<array{slug: string, key: string, title_fa: string, group: string, schema: array, source: string}>
     */
    public static function pluginSettingsSchemas(): array
    {
        $out = [];
        $owner = [];
        $coreKeys = array_keys(config('plugins.settings_groups', []));

        foreach ($coreKeys as $key) {
            $owner[$key] = 'core';
        }

        foreach (self::activeManifests() as $slug => $manifest) {
            $settings = $manifest['settings'] ?? null;

            if (! is_array($settings)) {
                continue;
            }

            foreach ($settings as $key => $def) {
                if (! is_string($key) || $key === '' || ! is_array($def)) {
                    continue;
                }

                // برخورد با هسته یا افزونهٔ قبلی بازنویسی نمی‌شود و بازنده گزارش
                // می‌گردد — همان قاعدهٔ `widgetSchemas()`.
                if (isset($owner[$key])) {
                    self::recordCollision('settings', $key, $owner[$key], "plugin:{$slug}");

                    continue;
                }

                $owner[$key] = "plugin:{$slug}";

                $out[] = [
                    'slug' => (string) $slug,
                    'key' => $key,
                    'title_fa' => is_string($def['title_fa'] ?? null) ? $def['title_fa'] : $key,
                    'group' => is_string($def['group'] ?? null) ? $def['group'] : 'عمومی',
                    'schema' => is_array($def['schema'] ?? null) ? $def['schema'] : ['type' => 'object'],
                    'source' => "plugin:{$slug}",
                ];
            }
        }

        return $out;
    }

    /**
     * انواع صفحه: هسته (config/page_types.php) + مانیفست پلاگین‌های فعال.
     * خروجی: [type => def + source + enabled] — typeهای enabled === false
     * حذف می‌شوند (فرانت فقط همین لیست را تب می‌کند).
     *
     * ## K6.8 — دو کانال، یک ادغام
     *
     * اعلان نوع صفحه از دو راه می‌آید و هر دو باید به این فهرست برسند:
     *
     *  ۱. کلید سطح‌بالای `manifest.page_types` — همان الگوی `widgets` و `settings`.
     *  ۲. `panel.extensions` با `point === 'site.page_type'` — تنها نقطهٔ `live` در
     *     قرارداد، و تنها جایی که `PluginPackageValidator` واقعاً اعلانش را
     *     بررسی می‌کند.
     *
     * تا پیش از این فقط کانال اول خوانده می‌شد. یعنی کانال دوم **اعتبارسنجی
     * می‌شد ولی هرگز به فهرست نمی‌رسید** — دقیقاً همان «نصب شد ولی دیده نشد» که
     * این کدبیس برایش اصطلاح دارد. بدتر از بی‌پشتوانه بودن است چون نویسنده پیام
     * خطا هم نمی‌گیرد.
     *
     * ترتیب: کانال دوم اول merge می‌شود، بعد کانال اول روی آن. یعنی در برخورد،
     * کلید سطح‌بالا برنده است — چون کانال مستقیم‌تر و صریح‌تری است و همین قاعده
     * در `widgetSchemas()` هم برقرار است.
     */
    public static function pageTypes(): array
    {
        $types = [];
        foreach (config('page_types', []) as $type => $def) {
            $types[$type] = array_merge($def, [
                'source' => 'core',
                'enabled' => ($def['enabled'] ?? true) !== false,
            ]);
        }

        self::$collisions['page_type'] = [];

        $adopt = function (string $type, array $def, string $source) use (&$types): void {
            if (! is_string($type) || $type === '' || ! is_array($def)) {
                return;
            }

            if (isset($types[$type])) {
                self::recordCollision('page_type', $type, $types[$type]['source'] ?? 'core', $source);

                return;
            }

            $types[$type] = array_merge($def, [
                'source' => $source,
                'enabled' => ($def['enabled'] ?? true) !== false,
            ]);
        };

        $manifests = self::activeManifests();

        // ترتیب عمداً برعکسِ نام متد است: کانال مستقیم‌تر اول می‌آید تا برنده شود.
        // `$adopt` اولین پذیرنده را نگه می‌دارد و بقیه را فقط گزارش می‌کند، پس
        // اگر `panel.extensions` اول merge شود، کلید سطح‌بالا هرگز برنده نمی‌شود
        // و قاعده‌ای که در `widgetSchemas()` برقرار است اینجا وارونه می‌افتد.
        foreach ($manifests as $slug => $manifest) {
            $extra = $manifest['page_types'] ?? null;
            if (! is_array($extra)) {
                continue;
            }
            foreach ($extra as $type => $def) {
                $adopt((string) $type, is_array($def) ? $def : [], "plugin:{$slug}");
            }
        }

        foreach ($manifests as $slug => $manifest) {
            foreach (self::declaredPageTypes($manifest) as $entry) {
                $def = $entry['def'];

                // `point` و مسیر اعلان فیلدهای داخلی رجیستری‌اند، نه چیزی که بقیه
                // ٔ کد ببیند — پس اینجا برداشته می‌شوند.
                unset($def['point'], $def['path']);

                $adopt($entry['type'], $def, "plugin:{$slug}");
            }
        }

        return collect($types)
            ->filter(fn (array $def) => ($def['enabled'] ?? true) !== false)
            ->all();
    }

    /**
     * اعلان‌های نوع صفحه که از راه `panel.extensions` آمده‌اند.
     *
     * @return list<array{type: string, def: array}>
     */
    private static function declaredPageTypes(array $manifest): array
    {
        $extensions = $manifest['panel']['extensions'] ?? null;

        if (! is_array($extensions)) {
            return [];
        }

        $out = [];

        foreach ($extensions as $entry) {
            if (! is_array($entry) || ($entry['point'] ?? null) !== 'site.page_type') {
                continue;
            }

            $type = $entry['type'] ?? null;

            if (! is_string($type) || $type === '') {
                continue;
            }

            $out[] = ['type' => $type, 'def' => $entry];
        }

        return $out;
    }

    /**
     * پیش‌فرض فوتر قالب فعال (docs/FOOTER-COLUMNS.md): manifest.footer =
     * {columns?: 1-4, widgets?: [{type, settings}]} — ورودی نامعتبر
     * fail-soft نادیده گرفته می‌شود. null = قالب ستونی اعلام نکرده.
     */
    public static function themeFooterDefaults(): ?array
    {
        $theme = Theme::query()->where('active', true)->first();
        $manifest = $theme && is_array($theme->manifest) ? $theme->manifest : null;
        if (! is_array($manifest) || ! is_array($manifest['footer'] ?? null)) {
            return null;
        }
        $footer = $manifest['footer'];
        $columns = $footer['columns'] ?? null;
        $columns = is_numeric($columns) && (int) $columns >= 1 && (int) $columns <= 4 ? (int) $columns : null;

        // type مجاز = هسته + ویجت‌های footer اعلام‌شده در همان مانیفست.
        $allowed = array_keys(config('widgets.footer', []));
        if (is_array($manifest['widgets']['footer'] ?? null)) {
            foreach ($manifest['widgets']['footer'] as $type => $def) {
                if (is_string($type)) {
                    $allowed[] = $type;
                }
            }
        }
        $widgets = [];
        if (is_array($footer['widgets'] ?? null)) {
            foreach (array_slice(array_values($footer['widgets']), 0, 30) as $w) {
                if (! is_array($w) || ! is_string($w['type'] ?? null) || ! in_array($w['type'], $allowed, true)) {
                    continue;
                }
                $settings = $w['settings'] ?? [];
                $widgets[] = ['type' => $w['type'], 'settings' => is_array($settings) ? $settings : []];
            }
        }
        if ($columns === null && $widgets === []) {
            return null;
        }

        return ['widgets' => $widgets, 'layout' => $columns ? ['columns' => $columns] : []];
    }

    /**
     * مبنای فوتر: پیش‌فرض قالب (اگر ستون اعلام کرده) وگرنه پیش‌فرض هسته.
     * تنظیم ذخیره‌شده کاربر همیشه بر این مبنا غلبه می‌کند (fallback، نه بازنویسی).
     */
    public static function footerBase(): array
    {
        $base = [
            'widgets' => [
                ['type' => 'about', 'settings' => []],
                ['type' => 'copyright', 'settings' => []],
            ],
            'layout' => ['columns' => 3],
        ];
        $theme = self::themeFooterDefaults();
        if ($theme === null) {
            return $base;
        }
        if ($theme['widgets'] !== []) {
            $base['widgets'] = $theme['widgets'];
        }
        if (isset($theme['layout']['columns'])) {
            $base['layout'] = ['columns' => $theme['layout']['columns']];
        }

        return $base;
    }

    /**
     * مانیفست پلاگین‌های فعال نصب (مشترک: بدون فیلتر کاربر).
     * user_id روی رکورد فقط سازنده (حسابرسی) است.
     *
     * کش: این متد از `permissionModules()`، `pageTypes()` و `widgetSchemas()`
     * صدا زده می‌شود، یعنی چند بار در هر درخواست. بدون کش، با N پلاگین
     * فعال N+1 کوئری به دیتابیس در هر درخواست می‌خورد.
     */
    public static function activeManifests(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            return Plugin::query()
                ->where('active', true)
                ->pluck('manifest', 'slug')
                ->filter(fn ($m) => is_array($m))
                ->all();
        });
    }

    /**
     * پاک‌سازی کش مانیفست‌ها.
     *
     * باید بعد از هر تغییر در مجموعه پلاگین‌های فعال صدا زده شود، وگرنه
     * پلاگین تازه‌فعال‌شده تا پایان TTL در پاسخ‌ها غایب می‌ماند.
     */
    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);

        // مجموعهٔ فعال عوض شده ⇒ برخوردهای تازه باید دوباره لاگ شوند، وگرنه
        // ضدّسیل لاگ، تازه‌ترین خرابی را برای همیشه بی‌صدا نگه می‌دارد.
        self::$logged = [];
    }

    /**
     * نرمال‌سازی هوک permissions مانیفست به [{module, title_fa, actions}].
     * ورودی نامعتبر نادیده گرفته می‌شود (fail-soft در خواندن؛ اعتبارسنجی
     * سخت‌گیرانه هنگام فعال‌سازی با ensurePermissions انجام می‌شود).
     */
    public static function manifestPermissions(array $manifest, string $slug = ''): array
    {
        $entries = $manifest['permissions'] ?? null;
        if (! is_array($entries)) {
            return [];
        }

        // B16: نام ماژول خام به‌تنهایی یکتا نیست. دو افزونه هر دو با
        // `module: "reports"` نوشته می‌شوند و هر دو ردیف `reports.view`
        // می‌ساختند — یعنی یکی مال دیگری را می‌دید. وقتی slug داده شود،
        // نام نهایی namespaced می‌شود.
        //
        // `$slug` اختیاری است تا این متد همچنان یک تابع خالص و قابل استفاده
        // در UI باشد، جایی که هنوز افزونهٔ مشخصی وجود ندارد.
        $prefix = $slug !== '' ? 'plugin:'.substr(Str::slug($slug), 0, 100).':' : '';

        $out = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $module = $entry['module'] ?? null;
            if (! is_string($module) || ! preg_match('/^[a-z0-9_-]{2,40}$/', $module)) {
                continue;
            }
            $actions = $entry['actions'] ?? self::ACTIONS;
            if (! is_array($actions)) {
                continue;
            }
            $actions = array_values(array_intersect($actions, self::ACTIONS));
            if ($actions === []) {
                continue;
            }
            $out[] = [
                'module' => $module,
                'name' => $prefix.$module,
                'title_fa' => is_string($entry['title_fa'] ?? null) && ($entry['title_fa'] ?? '') !== ''
                    ? mb_substr($entry['title_fa'], 0, 80)
                    : $module,
                'actions' => $actions,
            ];
        }

        return $out;
    }
}
