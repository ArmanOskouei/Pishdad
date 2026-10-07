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
 *
 * کانال اصلیِ همهٔ نقاط اتصال: `panel.extensions`
 * ---------------------------------------------------------------
 * برای **هر** نقطهٔ اتصال، شکلِ اعلانِ موردِ قرارداد این است:
 *
 *     { "panel": { "extensions": [ { "point": "<point>", … } ] } }
 *
 * و همهٔ خواندن‌ها از یک نقطهٔ واحد رد می‌شوند:
 * `ManifestRegistry::pointDeclarations()`. یک کانال، یک شکل، یک validator —
 * یعنی «نقطهٔ اتصال» دیگر دو تعریف ندارد که یک روز یکی‌شان یادش برود.
 *
 * کلیدهای سطح‌بالای قدیمی **لایهٔ سازگاری**‌اند، نه کانال موازی. یعنی:
 *
 * | نقطه | کانال قراردادی | کانال legacy (سازگاری) | در برخورد برنده |
 * |---|---|---|---|
 * | `admin.menu` | `panel.extensions` | `menu` | legacy |
 * | `admin.plugin_tools` | `panel.extensions` | `tools` | legacy |
 * | `site.header_widget` | `panel.extensions` | `widgets.header` | legacy |
 * | `site.footer_widget` | `panel.extensions` | `widgets.footer` | legacy |
 * | `site.page_type` | `panel.extensions` | `page_types` | legacy |
 * | پرمیشن (نقطه نیست — I1.3) | `access` | `permissions` | کانال زنده |
 * | `admin.settings_schema` | ❌ ندارد — `deprecated` | `settings` | — |
 * | `hooks` | ❌ ندارد — K5.5 حذف شد، dispatcher وجود ندارد | ❌ | — |
 *
 * ⚠️ **legacy در پنج نقطهٔ اول برنده است.** این عمدی و fail-closed به‌سمت
 * نصب‌های موجود است: بسته‌ای که امروز `manifest.menu` دارد، بعد از مهاجرت هم
 * باید همان رفتار را بگیرد، نه اینکه یک اعلانِ نقطه‌ای — که شاید نویسنده
 * امروز اضافه کرده — بی‌سروصدا جایش را بگیرد. تنها استثنا پرمیشن است، چون
 * آنجا کانال زنده (`access`) تازه معرفی شده و چیزی را جابه‌جا نمی‌کند.
 *
 * پس دو جمله را باید با هم گفت وگو کرد:
 *  • «`panel.extensions` تنها **اعلانِ** نقطهٔ اتصال است» — یعنی تنها چیزی که
 *    validator می‌سنجد و در تحلیلگر به نویسنده نشان داده می‌شود.
 *  • «کلید سطح‌بالا هنوز خودش هم اعلام می‌کند» — فقط برای نصب‌هایی که پیش از
 *    این کانال نوشته شده‌اند.
 *
 * حذفِ لایهٔ دوم یک تصمیم جداگانه و پرهزینه است (شمارش نصب‌های فعال، مهاجرت
 * داده، و بازگردانی‌پذیری)، نه چیزی که در این مهاجرت انجام می‌شود.
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

    /**
     * ⭐ I1-b — نقاطی که کانال اصلی (`panel.extensions`) برایشان **زنده** است.
     *
     * نه فقط این کلاس: `ServiceProviderRegistry::declaredSearchProviders()` هم
     * از همین کانال می‌خواند، پس `core.service_provider` هم اینجاست. فهرست یکی
     * است تا فرانت هم نداند کدام نقاط را باید در فرمِ ساخت بسته نشان دهد.
     * فهرست **نام‌برده** است نه مشتق‌شده، چون «مشتق‌شده از قرارداد» یعنی هر
     * نقطه‌ای که فقط در `EXTENSION_POINTS` ثبت شده باشد خودکار «خوانده‌شده»
     * اعلام شود — و همان‌جا دروغ می‌گوید: `admin.dashboard_slot` و
     * `site.block_type` در قراردادند ولی هیچ رجیستری‌ای نمی‌خواندشان. اگر
     * روزی برایشان رندرر نوشته شد، این فهرست باید همان‌جا به‌روز شود.
     *
     * ⛔ پرمیشن در این فهرست **نیست**: نقطهٔ اتصال نیست (I1.3) و کانالش
     * `access` است — دلیل در `PluginPackageContract::PERMISSIONS_FIELD`.
     * ⛔ `hooks` هم نیست: K5.5 حذفش کرد و dispatcher ساخته نشد.
     *
     * @var list<string>
     */
    public const EXTENSION_POINT_DECLARABLE = [
        'admin.menu',
        'admin.plugin_tools',
        'core.service_provider',
        'site.header_widget',
        'site.footer_widget',
        'site.page_type',
    ];

    /** برچسب فارسی هر نوع ادغام — فقط برای پیام گزارش برخورد. */
    private const KIND_FA = [
        'permission' => 'ماژول دسترسی',
        'widget' => 'ویجت',
        'page_type' => 'نوع صفحه',
        'settings' => 'فرم تنظیمات',
        'block' => 'بلوک',
    ];

    /**
     * K6.12 — کلیدهایی که از schema بلوک افزونه حذف می‌شوند.
     *
     * افزونه هرگز React/JS نمی‌فرستد؛ این فقط لایهٔ دفاعی است تا اگر روزی
     * مصرف‌کنندهٔ تازه‌ای schema را به‌عنوان کد تفسیر کرد، کلید اجرایی نبیند.
     */
    private const BLOCK_SCHEMA_FORBIDDEN = ['component', 'render', 'js', 'import', 'eval'];

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

        // K6.4 — کانال دوم: `panel.extensions` با `point` برابر
        // `site.header_widget` یا `site.footer_widget`.
        //
        // این نقطه **قبلاً** هیچ‌جا خوانده نمی‌شد، یعنی افزونه می‌توانست ویجت
        // اعلام کند، اعتبارسنجی هم می‌شد، و باز هرگز به فهرست نمی‌رسید. بدتر
        // از بی‌پشتوانه بودن است چون نویسنده هیچ پیام خطایی هم نمی‌گیرد.
        //
        // دربارهٔ ترتیب merge و چرا با `pageTypes()` فرق دارد، توضیح در پایین
        // حلقهٔ `activeManifests()` آمده — همان‌جایی که ترتیب واقعاً دیده
        // می‌شود.
        $mergePointChannel = function (array $manifest, string $source) use (&$out, &$owner): void {
            // نگاشت نقطهٔ اتصال به ناحیه. هر چیز دیگری ویجت نیست.
            $areas = [
                'site.header_widget' => 'header',
                'site.footer_widget' => 'footer',
            ];

            foreach ($areas as $point => $area) {
                foreach (self::pointDeclarations($manifest, $point) as $ext) {
                    $type = $ext['type'] ?? null;
                    if (! is_string($type) || $type === '') {
                        continue;
                    }

                    $key = "{$area}:{$type}";
                    if (isset($owner[$key])) {
                        self::recordCollision('widget', $key, $owner[$key], $source);

                        continue;
                    }

                    $owner[$key] = $source;
                    $out[] = [
                        'area' => $area,
                        'type' => $type,
                        'title' => is_string($ext['title'] ?? null) ? $ext['title'] : $type,
                        'description' => is_string($ext['description'] ?? null) ? $ext['description'] : null,
                        // اسکیمای `fields` خالی است (K6.4) ⇒ هر اعلانی `no_schema`
                        // می‌گیرد و رد می‌شود. پس عملاً همیشه پیش‌فرض خالی است، و
                        // هسته آن را با `SchemaForm` تخت رندر می‌کند.
                        'schema' => is_array($ext['schema'] ?? null) ? $ext['schema'] : ['type' => 'object'],
                        'ui' => is_array($ext['ui'] ?? null) ? $ext['ui'] : [],
                        'source' => $source,
                    ];
                }
            }
        };

        foreach (self::activeManifests() as $slug => $manifest) {
            $source = "plugin:{$slug}";
            // ترتیب برعکسِ چیزی است که در نقطهٔ `pageTypes()` می‌بینیم، و این
            // تناقض ظاهری یک دلیل مشخص دارد: `$owner` اولین ثبت‌کننده را
            // نگه می‌دارد و بازنده‌ها حذف می‌شوند. اینجا کانال **سطح‌بالا**
            // اول merge می‌شود پس زودتر ثبت می‌شود و برنده است — و این همان
            // چیزی است که می‌خواهیم.
            //
            // در `pageTypes()` برعکس است چون آنجا `declaredPageTypes()` کانال
            // سطح‌بالا را با `$out[$type] =` **بازنویسی** می‌کند، نه اینکه
            // فقط اولین برنده را نگه دارد. یعنی «کانال مستقیم‌تر برنده است» در
            // هر دو نقطه برقرار است ولی مکانیزمش فرق دارد، و همین تفاوت است
            // که اگر توضیح داده نشود، بازترتیب‌کردن یک باگ می‌سازد.
            $merge($manifest, $source);
            $mergePointChannel($manifest, $source);
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
     * ⭐ I1-b — تنها راه خواندن اعلان‌های یک نقطه از یک مانیفست.
     *
     * عمداً یک متد است و نه چهار پراکندگیِ `$manifest['panel']['extensions']`:
     * کانال اصلیِ `panel.extensions` باید در **یک** جا تعریف شود، وگرنه یک روز
     * یک نقطه کانال دوم را از یاد می‌برد و اعلانش بی‌صدا از رجیستری می‌افتد —
     * همان باگی که K6.4 برای `site.header_widget` داشت.
     *
     * شکل آبجکتی و شکل «کلید ⇒ اعلان» هر دو پذیرفته می‌شوند (همان قاعدهٔ
     * `ServiceProviderRegistry::serviceProviderDeclarations()`)، ولی در شکل
     * دوم فقط اعلان‌هایی برگردانده می‌شوند که خودشان `point` دارند — وگرنه کلیدِ
     * نقشه به‌عنوان نقطه جا زده می‌شد.
     *
     * @return list<array<string, mixed>>
     */
    public static function pointDeclarations(array $manifest, string $point): array
    {
        $declared = $manifest['panel']['extensions'] ?? null;

        if (! is_array($declared)) {
            return [];
        }

        if (array_is_list($declared) === false) {
            $declared = array_values(array_filter(
                $declared,
                fn ($d) => is_array($d) && ($d['point'] ?? null) === $point
            ));
        }

        $out = [];
        foreach ($declared as $decl) {
            if (is_array($decl) && ($decl['point'] ?? null) === $point) {
                $out[] = $decl;
            }
        }

        return $out;
    }

    /**
     * آیتم‌های منوی پنل که افزونه‌های فعال اعلام کرده‌اند (K6.1).
     *
     * @return list<array{slug: string, key: string, label: string, href: string, icon: string|null, order: int, permission: string|null}>
     */
    public static function menuItems(): array
    {
        $out = [];
        $owner = [];

        foreach (self::activeManifests() as $slug => $manifest) {
            $slug = (string) $slug;

            $items = self::declaredMenuItems($manifest, $slug);

            if ($items === []) {
                continue;
            }

            foreach ($items as $item) {
                // برخورد کلید: اولین افزونه برنده است و بقیه حذف می‌شوند.
                // `slug.key` است نه `key` تنها — دو افزونه هر دو `key => "reports"`
                // می‌توانند بگذارند و هر دو باید زنده بمانند، درست مثل
                // `permissionModules()` که ماژول را namespaced می‌کند.
                $namespace = $item['key'];

                if (isset($owner[$namespace])) {
                    self::recordCollision('menu', $namespace, $owner[$namespace], "plugin:{$slug}");

                    continue;
                }

                $owner[$namespace] = "plugin:{$slug}";
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * خواندن اعلان‌های منو از دو کانال — همان قاعدهٔ K6.8 برای `page_types`.
     *
     *  ۱. `manifest.menu` — کلید سطح‌بالا، الگوی زندهٔ `widgets` و `settings`.
     *  ۲. `panel.extensions` با `point === 'admin.menu'` — نقطهٔ micro-schema.
     *
     * اولی دومی را بازنویسی می‌کند (precedence کانال مستقیم‌تر) و این همان
     * ترتیبی است که `pageTypes()` دنبال می‌کند.
     *
     * اعتبارسنجی کامل در `PluginPackageValidator` انجام می‌شود؛ اینجا فقط
     * شکل‌بندی و بدیهی‌سازی انجام می‌گیرد تا فرانت بتواند فرض کند `href` رشته
     * است و `order` عدد — چون `mergePluginMenu` در فرانت به آن‌ها اعتماد دارد.
     */
    private static function declaredMenuItems(array $manifest, string $slug): array
    {
        $byKey = [];

        // کانال ۲ — `panel.extensions` (کم‌اولویت‌تر).
        foreach (self::pointDeclarations($manifest, 'admin.menu') as $ext) {
            $item = self::normalizeMenuItem($ext, $slug);
            if ($item !== null) {
                $byKey[$item['key']] = $item;
            }
        }

        // کانال ۱ — کلید سطح‌بالا (برندهٔ برخورد).
        $menu = $manifest['menu'] ?? null;
        if (is_array($menu)) {
            foreach ($menu as $key => $def) {
                if (! is_string($key) || ! is_array($def)) {
                    continue;
                }

                $item = self::normalizeMenuItem($def + ['key' => $key], $slug);
                if ($item !== null) {
                    $byKey[$item['key']] = $item;
                }
            }
        }

        return array_values($byKey);
    }

    /**
     * @return array{slug: string, key: string, label: string, href: string, icon: string|null, order: int, permission: string|null}|null
     */
    private static function normalizeMenuItem(array $def, string $slug): ?array
    {
        $key = $def['key'] ?? null;
        $label = $def['label'] ?? null;
        $href = $def['href'] ?? null;

        // سه فیلد اجباری‌اند. اگر یکی نباشد آیتم اصلاً معنا ندارد و ساختنش با
        // مقدار پیش‌فرض فقط یک آیتم شکسته به منو اضافه می‌کند.
        if (! is_string($key) || $key === '' || ! is_string($label) || ! is_string($href)) {
            return null;
        }

        return [
            'slug' => $slug,
            'key' => $key,
            'label' => $label,
            'href' => $href,
            'icon' => is_string($def['icon'] ?? null) ? $def['icon'] : null,
            'order' => is_int($def['order'] ?? null) ? $def['order'] : 999,
            'permission' => is_string($def['permission'] ?? null) ? $def['permission'] : null,
        ];
    }

    /**
     * صفحه‌های اختصاصی افزونه (K6.5) — مسیرهایی زیر `/admin/` که افزونه‌ها
     * برایشان یک page descriptor ثبت می‌کنند.
     *
     * ## چرا فقط مسیر و نه render
     *
     * قرارداد می‌گوید «render از page descriptor می‌آید نه از پلاگین» — یعنی
     * افزونه می‌گوید «این مسیر مال من است» و هسته رندر می‌کند. دلیلش امنیتی
     * است: اگر پلاگین JS خودش را تزریق می‌کرد، می‌توانست `localStorage` را بخواند
     * (توکن آنجاست) یا یک فرم جعلی بسازد. با descriptor، محتوا از مسیر
     * blockهای هسته می‌آید و هیچ کد دلخواهی وارد باندل نمی‌شود.
     *
     * @return list<array{slug: string, path: string, title_fa: string, permission: string|null, layout: string}>
     */
    public static function pageRegistry(): array
    {
        $out = [];
        $owner = [];

        foreach (self::activeManifests() as $slug => $manifest) {
            $slug = (string) $slug;

            $entries = $manifest['pages'] ?? null;

            // کانال دوم `admin.pages` فقط وقتی خوانده می‌شود که کانال اول نبود.
            // `??` روی `$manifest['admin']['pages']` مستقیم warning می‌داد وقتی
            // کلید `admin` وجود نداشت، چون `??` کل زنجیره را محافظت نمی‌کند.
            if ($entries === null && is_array($manifest['admin'] ?? null)) {
                $entries = $manifest['admin']['pages'] ?? null;
            }

            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $key => $def) {
                if (! is_string($key) || ! is_array($def)) {
                    continue;
                }

                $item = self::normalizePageRegistryEntry($def, $slug, $key);

                if ($item === null) {
                    continue;
                }

                // مسیر باید یکتا باشد، وگرنه دو افزونه یک URL را می‌گیرند و
                // اینکه کدام رندر شود به ترتیب بارگذاری بستگی می‌کرد.
                if (isset($owner[$item['path']])) {
                    self::recordCollision('page_registry', $item['path'], $owner[$item['path']], "plugin:{$slug}");

                    continue;
                }

                $owner[$item['path']] = "plugin:{$slug}";
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @return array{slug: string, path: string, title_fa: string, permission: string|null, layout: string}|null
     */
    private static function normalizePageRegistryEntry(array $def, string $slug, string $key): ?array
    {
        // مسیر پیش‌فرض از روی کلید ساخته می‌شود، ولی **با ریشهٔ `/admin/`**:
        // کلید `billing` باید `/admin/billing` بدهد نه `/billing`. نسخهٔ
        // قبلی `'/' . $key` می‌ساخت و بعد با `str_starts_with('/admin/')`
        // رد می‌شد — یعنی هر ورودیِ بدون `path` صریح بی‌صدا حذف می‌شد و
        // افزونه فکر می‌کرد ثبت کرده است.
        $path = is_string($def['path'] ?? null) && $def['path'] !== ''
            ? $def['path']
            : '/admin/'.$key;

        // مسیر باید داخل `/admin/` باشد. بدون این قاعده یک افزونه می‌توانست
        // مسیری بیرون از پنل ثبت کند و خودش را جای بخش دیگری جا بزند.
        //
        // نقطهٔ انتهایی **قبل** از این بررسی حذف می‌شود. الگوی فرانت
        // (`isPluginAdminPath`) نقطهٔ انتهایی را رد می‌کند چون segment آخر باید
        // محتوا داشته باشد؛ اگر اینجا `rtrim` بعد از بررسی می‌آمد، `/admin/a/`
        // که معتبر است رد می‌شد ولی `/admin/a` قبول — یعنی دو لایه دو جور
        // حرف می‌زدند و یک مسیر در بک‌اند می‌مرد و در فرانت زنده می‌شد.
        $path = rtrim($path, '/');

        if (! str_starts_with($path, '/admin/') || str_contains($path, '..') || str_contains($path, '//')) {
            return null;
        }

        $layout = is_string($def['layout'] ?? null) ? $def['layout'] : PluginPageContract::DEFAULT_LAYOUT;

        // K7.18 — `layout` دیگر رشتهٔ آزاد نیست. اگر مقداری خارج از allowlist
        // بیاید، **خودِ مقدار** حفظ می‌شود ولی صفحه `null` می‌شود: بازگشت به
        // پیش‌فرض یعنی رندر یک چیزی که نویسندهٔ افزونه درخواستش نکرده بود.
        //
        // این برای افزونه‌های نصب‌شدهٔ قبلی هم fail-closed است — دروازهٔ نصب
        // (`PluginPackageContract`-level, در `PluginPackageValidator::pageChecks`)
        // ممکن است تازه باشد و آن‌ها از آن رد نشده باشند.
        if (! in_array($layout, PluginPageContract::LAYOUTS, true)) {
            return null;
        }

        return [
            'slug' => $slug,
            'path' => rtrim($path, '/'),
            'title_fa' => is_string($def['title_fa'] ?? null) ? $def['title_fa'] : $key,
            'permission' => is_string($def['permission'] ?? null) ? $def['permission'] : null,
            'layout' => $layout,
            'blocks' => self::normalizePageBlocks($def['blocks'] ?? null),
        ];
    }

    /**
     * K7.18 — بلوک‌های اعلانی صفحه، فقط از واژگان هسته.
     *
     * ## چرا این هم fail-closed است
     *
     * دروازهٔ نصب بلوک‌ها را می‌سنجد، ولی افزونه‌های *نصب‌شدهٔ قبلی* از آن رد
     * نشده‌اند. پس اینجا هم باید باشد — وگرنه یک مانیفست قدیمی با
     * `"type": "evil"` یا `data` غیرآرایه، به `BlockRenderer` می‌رسد و آنجا
     * شاخهٔ `default` فقط یک لاگ می‌نویسد و چیزی رندر نمی‌کند.
     *
     * ## چرا `data` کامل دور ریخته نمی‌شود
     *
     * `data` آبجکت آزاد است و `SchemaForm`/`BlockRenderer` خودشان به آن
     * نگاه می‌کنند؛ ما فقط **شکل** را تضمین می‌کنیم (آرایهٔ خطی نباشد). پس
     * اینجا `array_filter` روی `data` می‌کنیم و مقدارش را دست‌نخورده می‌گذاریم —
     * تنها چیزی که فیلتر می‌شود نوع بلوک است، چون همان چیزی است که تعیین می‌کند
     * کدام کد اجرا شود.
     *
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private static function normalizePageBlocks(mixed $blocks): array
    {
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            return [];
        }

        $allowed = self::allowedBlockTypes();
        $out = [];

        foreach ($blocks as $block) {
            if (! is_array($block) || array_is_list($block)) {
                continue;
            }

            $type = $block['type'] ?? null;
            if (! is_string($type) || ! in_array($type, $allowed, true)) {
                continue;
            }

            $data = $block['data'] ?? null;
            if (! is_array($data) || (array_is_list($data) && $data !== [])) {
                continue;
            }

            $entry = ['type' => $type, 'data' => $data];

            // `_enabled` ریزدانگه‌ای است که `BlockRenderer.tsx:63` به آن نگاه
            // می‌کند؛ اگر از مانیفست بیاید و صفر باشد، بلوک رندر نمی‌شود.
            // فقط `false` معتبر است — هر مقدار دیگری نادیده گرفته می‌شود تا
            // یک رشتهٔ تصادفی نتواند بلوک را بی‌صدا حذف کند.
            if (array_key_exists('_enabled', $block) && $block['_enabled'] === false) {
                $entry['_enabled'] = false;
            }

            $out[] = $entry;

            if (count($out) >= PluginPageContract::MAX_BLOCKS_PER_PAGE) {
                break;
            }
        }

        return $out;
    }

    /**
     * K6.12 — انواع بلوک مجاز برای مسیر خواندن/رندرِ عمومی.
     *
     * برخلاف `PluginPageContract::allowedBlockTypes()` که فقط رجیستری هسته را
     * می‌شناسد (و دروازهٔ نصب باید بسته بماند)، این یکی **کانال زندهٔ
     * `manifest.blocks`** را هم می‌خواند. بدون آن، بلوکی که افزونه فقط schema‌اش
     * را می‌فرستد در `normalizePageBlocks()` بی‌صدا حذف می‌شد — یعنی «نصب شد
     * ولی دیده نشد» از نوع محتوایی.
     *
     * @return list<string>
     */
    public static function allowedBlockTypes(): array
    {
        return array_keys(self::blockSchemas());
    }

    /**
     * K6.12 — رجیستری بلوک‌های schema-only (هسته + افزونه‌های فعال).
     *
     * خروجی `[type => schema]` است؛ همان شکلی که `BlockRenderer` فرانت با
     * پراپِ `schemas` می‌فهمد. افزونه هیچ کد React/JS نمی‌فرستد — فقط
     * `manifest.blocks.{type}.schema` را اعلام می‌کند و هسته با ۱۱ الگوی عمومی
     * رندر می‌کند.
     *
     * ترتیب برنده در برخورد عمداً «هسته اول» است: یک افزونه هرگز نباید بلوک
     * هسته را بپوشاند، وگرنه رندرِ ثابتِ هسته با رجیستریِ افزونه عوض می‌شود.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function blockSchemas(): array
    {
        $schemas = [];
        $owner = [];

        foreach (config('blocks', []) as $type => $def) {
            if (! is_string($type) || $type === '') {
                continue;
            }
            if (is_array($def) && ($def['active'] ?? true) === false) {
                continue;
            }
            $schema = is_array($def) && is_array($def['schema'] ?? null) ? $def['schema'] : ['type' => 'object'];
            $schemas[$type] = self::normalizeBlockSchema($schema);
            $owner[$type] = 'core';
        }

        self::$collisions['block'] = [];

        foreach (self::activeManifests() as $slug => $manifest) {
            foreach (self::declaredBlockSchemas($manifest) as $type => $schema) {
                if (isset($owner[$type])) {
                    self::recordCollision('block', $type, $owner[$type], "plugin:{$slug}");

                    continue;
                }
                $owner[$type] = "plugin:{$slug}";
                $schemas[$type] = $schema;
            }
        }

        return $schemas;
    }

    /**
     * اعلان‌های بلوک یک مانیفست — دو کانال، یک قاعده (هم‌شکل `pageTypes()`).
     *
     *  ۱. `manifest.blocks.{type}` — کانال سطح‌بالا و مستقیم (برنده).
     *  ۲. `panel.extensions` با `point === 'site.block_type'` — نقطهٔ قراردادی.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function declaredBlockSchemas(array $manifest): array
    {
        $byType = [];

        foreach (self::pointDeclarations($manifest, 'site.block_type') as $ext) {
            $type = $ext['type'] ?? $ext['slug'] ?? null;
            if (! is_string($type) || ! self::validBlockType($type)) {
                continue;
            }
            $byType[$type] = self::normalizeBlockSchema(is_array($ext['schema'] ?? null) ? $ext['schema'] : $ext);
        }

        $blocks = $manifest['blocks'] ?? null;
        if (is_array($blocks)) {
            foreach ($blocks as $type => $def) {
                if (! is_string($type) || ! self::validBlockType($type) || ! is_array($def)) {
                    continue;
                }
                $byType[$type] = self::normalizeBlockSchema(is_array($def['schema'] ?? null) ? $def['schema'] : $def);
            }
        }

        return $byType;
    }

    /** الگوی نام نوع بلوک — همان الگوی کلید قرارداد. */
    private static function validBlockType(string $type): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9._-]{0,39}$/', $type) === 1;
    }

    /**
     * schema بلوک افزونه را به شکلِ مصرف‌پذیر می‌رساند: کلیدهای اجرایی حذف
     * می‌شوند و شکلِ آبجکت تضمین می‌شود. هرگز استثنا پرتاب نمی‌کند.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function normalizeBlockSchema(array $schema): array
    {
        $clean = self::stripForbiddenKeys($schema);

        return is_array($clean) ? $clean : ['type' => 'object'];
    }

    /**
     * حذف بازگشتیِ کلیدهای اجرایی از یک ساختار JSON.
     *
     * عمق محدود است تا یک مانیفستِ ساختگی کل حافظه را نگیرد؛ مقدار غیرآرایه‌ای
     * دست‌نخورده می‌ماند. `null` یعنی «همه‌چیز حذف شد».
     */
    private static function stripForbiddenKeys(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return null;
        }
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $list = [];
            foreach ($value as $item) {
                $pruned = self::stripForbiddenKeys($item, $depth + 1);
                if ($pruned !== null) {
                    $list[] = $pruned;
                }
            }

            return $list;
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            if (in_array(strtolower($key), self::BLOCK_SCHEMA_FORBIDDEN, true)) {
                continue;
            }
            $pruned = self::stripForbiddenKeys($item, $depth + 1);
            if ($pruned !== null) {
                $out[$key] = $pruned;
            }
        }

        return $out;
    }

    /**
     * ابزارهای افزونه برای drawer هدر پنل (K6.2).
     *
     * ## چرا `notification_id` و نه `href`
     *
     * قرارداد این نقطه را `closed` و `cross => ['no_href']` اعلام کرده. دلیلش
     * ساختاری است: `href` آزاد یعنی هر افزونه می‌تواند به هر مسیری لینک بدهد
     * — از جمله مسیری که خودش ثبت نکرده. به‌جایش افزونه یک **شناسه** می‌دهد و
     * هسته خودش به نشانی تبدیلش می‌کند، پس هر ابزار یک صفحهٔ ثبت‌شده در
     * `pageRegistry()` پشتش دارد و مسیرش قابل اعتبارسنجی است.
     *
     * ## دو کانال، یک قاعده
     *
     * `manifest.tools` (سطح‌بالا) و `panel.extensions` با `point === 'admin.plugin_tools'`.
     * اولی **اول** merge می‌شود تا برنده باشد — همان قاعدهٔ `widgetSchemas()`
     * (K6.4): `$owner` اولین ثبت‌کننده را نگه می‌دارد.
     *
     * @return list<array{slug: string, key: string, title_fa: string, icon: string|null, description: string|null, notification_id: int|null, permission: string|null}>
     */
    public static function pluginTools(): array
    {
        $out = [];
        $owner = [];

        foreach (self::activeManifests() as $slug => $manifest) {
            $slug = (string) $slug;
            $tools = self::declaredPluginTools($manifest, $slug);

            if ($tools === []) {
                continue;
            }

            foreach ($tools as $tool) {
                if (isset($owner[$tool['key']])) {
                    self::recordCollision('plugin_tools', $tool['key'], $owner[$tool['key']], "plugin:{$slug}");

                    continue;
                }

                $owner[$tool['key']] = "plugin:{$slug}";
                $out[] = $tool;
            }
        }

        return $out;
    }

    /**
     * @return list<array{slug: string, key: string, title_fa: string, icon: string|null, description: string|null, notification_id: int|null, permission: string|null}>
     */
    private static function declaredPluginTools(array $manifest, string $slug): array
    {
        $byKey = [];

        // کانال ۲ — نقطهٔ micro-schema (بازنده در برخورد).
        foreach (self::pointDeclarations($manifest, 'admin.plugin_tools') as $ext) {
            $tool = self::normalizePluginTool($ext, $slug);
            if ($tool !== null) {
                $byKey[$tool['key']] = $tool;
            }
        }

        // کانال ۱ — سطح‌بالا (برنده).
        $tools = $manifest['tools'] ?? null;
        if (is_array($tools)) {
            foreach ($tools as $key => $def) {
                if (! is_string($key) || ! is_array($def)) {
                    continue;
                }
                $tool = self::normalizePluginTool($def + ['key' => $key], $slug);
                if ($tool !== null) {
                    $byKey[$tool['key']] = $tool;
                }
            }
        }

        return array_values($byKey);
    }

    /**
     * @param  array<string, mixed>  $def
     * @return array{slug: string, key: string, title_fa: string, icon: string|null, description: string|null, notification_id: int|null, permission: string|null}|null
     */
    private static function normalizePluginTool(array $def, string $slug): ?array
    {
        $key = $def['key'] ?? null;
        $title = $def['title_fa'] ?? $def['title'] ?? null;

        // دو فیلد بدون آن‌ها ابزار نیست، فقط یک ردیف بی‌معنا در drawer است.
        if (! is_string($key) || $key === '' || ! is_string($title) || $title === '') {
            return null;
        }

        $id = $def['notification_id'] ?? null;

        return [
            'slug' => $slug,
            'key' => $key,
            'title_fa' => $title,
            'icon' => is_string($def['icon'] ?? null) ? $def['icon'] : null,
            'description' => is_string($def['description'] ?? null) ? $def['description'] : null,
            // فقط `int` معتبر. رشتهٔ عددی رد می‌شود چون بعداً به مسیر تبدیل
            // می‌شود و نوعش باید قطعی باشد.
            'notification_id' => is_int($id) && $id > 0 ? $id : null,
            'permission' => is_string($def['permission'] ?? null) ? $def['permission'] : null,
        ];
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
        $out = [];

        foreach (self::pointDeclarations($manifest, 'site.page_type') as $entry) {
            // ⭐ نامِ نوع، `slug` است — نه `type`.
            //
            // قرارداد (`site.page_type`) فیلد اجباری را `slug` نام گذاشته و
            // `example_ok` هم `slug` می‌دهد. ولی این متد تا حالا فقط `type` را
            // می‌خواند، یعنی **اعلانی که خودِ validator قبول می‌کند هرگز به رجیستری
            // نمی‌رسید** — همان «نصب شد ولی دیده نشد» با یک پیامِ سبزِ دروغین.
            //
            // `type` به‌عنوان نام مستعار خوانده می‌شود، ولی **دوم** — تا مانیفست‌های
            // قدیمیِ نوشته‌شده با شکل پیش از K3.9 هم کار کنند و کانال اصلی به‌طور
            // ناخواسته سخت‌گیرانه‌تر از legacy نشود.
            $type = $entry['slug'] ?? $entry['type'] ?? null;

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
            // `orderBy('slug')` عمدی است: بدون آن ترتیب بازگشتی PostgreSQL
            // تضمین نشده، یعنی دو درخواست پشت‌سرهم می‌توانستند منوی متفاوت
            // بدهند و برخورد «کدام افزونه برنده است» بین دو رندر فرق کند.
            // K1.5-B — ردشده‌ها هرگز dispatch نمی‌شوند، حتی اگر flag فعال
            // به‌هرلیل set مانده باشد. بقیهٔ وضعیت‌ها دست‌نخورده‌اند: گیت
            // فعال‌سازی (mayActivate) در زمان activate اعمال می‌شود و پلاگین
            // محلیِ تأییدنشده که با حالت توسعه‌دهنده فعال شده (K0.11: بله با
            // هشدار) باید در dispatch بماند، وگرنه dev mode بی‌اثر می‌شد.
            return Plugin::query()
                ->where('active', true)
                ->where('review_status', '!=', Plugin::REVIEW_REJECTED)
                ->orderBy('slug')
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
    /**
     * ECO3 — مستندات API که افزونه‌های فعال خودشان اعلام می‌کنند.
     *
     * فقط **داده** است: هیچ کد افزونه‌ای رندر نمی‌شود و هیچ URL خارجی‌ای
     * fetch نمی‌شود. هر ورودی نامعتبر بی‌صدا حذف می‌شود (fail-closed) تا یک
     * مانیفست خراب کل صفحهٔ مستندات را نترکاند.
     *
     * @return list<array{slug: string, name: string, version: string, entries: list<array<string, mixed>>}>
     */
    public static function developerDocs(): array
    {
        $out = [];
        foreach (self::activeManifests() as $slug => $manifest) {
            $entries = [];
            foreach (is_array($manifest['docs'] ?? null) ? $manifest['docs'] : [] as $raw) {
                $entry = self::normalizeDocEntry($raw);
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
            if ($entries === []) {
                continue;
            }
            $out[] = [
                'slug' => (string) $slug,
                'name' => is_string($manifest['name'] ?? null) && $manifest['name'] !== '' ? $manifest['name'] : (string) $slug,
                'version' => is_string($manifest['version'] ?? null) ? $manifest['version'] : '',
                'entries' => $entries,
            ];
        }

        return $out;
    }

    /**
     * نرمال‌سازی یک ورودی `manifest.docs` — رشته‌ها trim و اجباری‌ها سنجیده.
     *
     * @return array<string, mixed>|null
     */
    private static function normalizeDocEntry(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $str = static function (mixed $v): string {
            return is_string($v) ? trim($v) : '';
        };
        $titleFa = $str($raw['title_fa'] ?? null);
        $titleEn = $str($raw['title_en'] ?? null);
        $path = $str($raw['path'] ?? null);
        if ($titleFa === '' && $titleEn === '') {
            return null;
        }
        if ($path === '') {
            return null;
        }

        $entry = [
            'title_fa' => $titleFa !== '' ? $titleFa : $titleEn,
            'title_en' => $titleEn !== '' ? $titleEn : $titleFa,
            'method' => strtoupper($str($raw['method'] ?? null)) ?: 'GET',
            'path' => $path,
            'description_fa' => $str($raw['description_fa'] ?? null),
            'description_en' => $str($raw['description_en'] ?? null),
            'auth' => $str($raw['auth'] ?? null),
        ];
        if (isset($raw['request']) && is_array($raw['request'])) {
            $entry['request'] = $raw['request'];
        }
        if (isset($raw['response']) && is_array($raw['response'])) {
            $entry['response'] = $raw['response'];
        }

        return $entry;
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);

        // مجموعهٔ فعال عوض شده ⇒ برخوردهای تازه باید دوباره لاگ شوند، وگرنه
        // ضدّسیل لاگ، تازه‌ترین خرابی را برای همیشه بی‌صدا نگه می‌دارد.
        self::$logged = [];
    }

    /**
     * نرمال‌سازی پرمیشن‌های مانیفست به [{module, name, title_fa, actions}].
     *
     * ## ⭐ I1.3 — چرا این **نقطهٔ اتصال نیست** و چرا کانالش جداست
     *
     * `panel.extensions` برای چیزی است که هسته **رندر** می‌کند. پرمیشن رندر
     * نمی‌شود؛ هسته آن را در `permission_modules` می‌نویسد و میان‌افزار `perm:`
     * می‌سنجدش. جا دادنش در میکرو-اسکیمای نقاط یعنی یا `max_depth` برای همهٔ
     * نقاط بالا می‌رود، یا `actions` بیرون اعلان می‌ماند و هیچ‌کس اعتبارسنجی‌اش
     * نمی‌کند — و «باز بودنِ» نقطه برای پرمیشن یعنی هر افزونه هر اسمی می‌سازد و
     * `perm:` همان را می‌خواند: یک سطح تازهٔ اختیار بدون مهار. دلیل کامل در
     * `PluginPackageContract::PERMISSIONS_FIELD`.
     *
     * ## دو کانال، fail-closed در خواندن
     *
     *  ۱. `manifest.access.*` — کانال زنده (ثابت `PERMISSIONS_FIELD`).
     *  ۲. `manifest.permissions` — کانال قدیمی، فقط برای نصب‌های موجود.
     *
     * اولی **برنده** است: اگر هر دو باشند، فقط `access` خوانده می‌شود تا یک
     * افزونه نتواند با تکرار `permissions` مجموعهٔ ناقصی بسازد و نیمی از
     * دسترسی‌هایش را از دست بدهد. `access` غایب و فقط `permissions` موجود =
     * نصب قدیمی، کار می‌کند و در report نگفته می‌شود چون سکوت اینجا بی‌خطر است
     * (رفتار همان رفتار قبلی است).
     *
     * ورودی نامعتبر نادیده گرفته می‌شود (fail-soft در خواندن؛ اعتبارسنجی
     * سخت‌گیرانه هنگام فعال‌سازی با `ensurePermissions` انجام می‌شود).
     */
    public static function manifestPermissions(array $manifest, string $slug = ''): array
    {
        $primary = PluginPackageContract::PERMISSIONS_FIELD;
        $legacy = PluginPackageContract::PERMISSIONS_FIELD_LEGACY;

        if (array_key_exists($primary, $manifest)) {
            // fail-closed: کانال زنده حاضر است ⇒ تصمیم نویسنده همین است، حتی اگر
            // خراب باشد. برگشتن به کانال قدیمی یعنی «شاید منظورش این بود» و
            // نتیجه‌اش دسترسی‌هایی است که نویسنده حذف کرده بود.
            $entries = $manifest[$primary];
        } else {
            $entries = $manifest[$legacy] ?? null;
        }

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
