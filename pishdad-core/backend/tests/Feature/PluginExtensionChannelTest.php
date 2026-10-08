<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\User;
use App\Search\SearchableProvider;
use App\Search\SearchProviderRegistry;
use App\Services\Plugins\ManifestRegistry;
use App\Services\Plugins\PluginPackageContract;
use App\Services\Plugins\ServiceProviderRegistry;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Pishdad\Plugins\TestDouble\CouponSearchProviderForTest;
use Pishdad\Plugins\TestDouble\ShopSearchProviderForTest;
use Tests\TestCase;

/**
 * ⭐ گروه I1 — `panel.extensions` تنها کانال نقاط اتصال، با مهاجرت دو-خواندنی
 * fail-closed.
 *
 * ## چرا یک فایل، و نه یکی به‌ازای هر ردیف
 *
 * ردیف‌های I1 به هم قفل‌اند: «کانال اصلی» بدون «fallback»، یعنی نصب‌های موجود
 * می‌شکنند؛ «fallback» بدون «کانال اصلی»، یعنی دوتاییِ حقیقت. و تصمیم I1.3
 * (پرمیشن نقطهٔ اتصال **نیست**) فقط وقتی معنی دارد که کانال‌های واقعی کنار هم
 * سنجیده شوند. پس یک فایل، با نام هر ردیف روی هر گروه تست.
 *
 * ## قاعدهٔ مشترکِ این فایل
 *
 * هر ادعا دو بار سنجیده می‌شود: **کانال اصلی کار می‌کند** و **fallback هم هنوز
 * کار می‌کند**. تستی که فقط اولی را بسنجد، مهاجرت را اثبات نکرده — مهاجرت یعنی
 * نصبِ قدیمی بعد از مهاجرت هنوز بالا می‌آید.
 */
class PluginExtensionChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ManifestRegistry::flushCache();
        SearchProviderRegistry::flushRuntime();
    }

    // ── کمکی‌ها ───────────────────────────────────────────────────────────

    private function plugin(array $manifest, bool $approved = false): Plugin
    {
        $p = Plugin::query()->create([
            'slug' => $manifest['slug'] ?? 'shop',
            'name' => 'Shop',
            'author' => 'test',
            'version' => '1.0.0',
            'signature' => 'x',
            'public_key' => 'x',
            'review_status' => $approved ? Plugin::REVIEW_APPROVED : Plugin::REVIEW_PENDING,
            'manifest' => $manifest,
            'active' => true,
        ]);

        ManifestRegistry::flushCache();

        return $p;
    }

    /**
     * همان `plugin()` ولی با پاک‌کردنِ افزونه‌های قبلی — برای تست‌هایی که یک
     * برند را چند بار با اسلاگِ یکسان می‌سازند.
     */
    private function freshPlugin(array $manifest, bool $approved = false): Plugin
    {
        Plugin::query()->delete();
        ManifestRegistry::flushCache();

        return $this->plugin($manifest, $approved);
    }

    /** فقط اعلان‌های `panel.extensions` یک نقطه. */
    private function pointOnly(string $point, array $decl): array
    {
        return ['panel' => ['extensions' => [array_merge(['point' => $point], $decl)]]];
    }

    /** اعلانِ معتبرِ نقطهٔ `site.page_type` — تنها نقطهٔ `live`. */
    private function pageType(array $extra = []): array
    {
        return array_merge(['slug' => 'shop_page', 'title_fa' => 'صفحهٔ فروشگاه'], $extra);
    }

    private function owner(): User
    {
        $this->seed(RolesPermissionsSeeder::class);

        $user = User::query()->create([
            'name' => 'مالک', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Owner!1234'), 'role' => 'admin',
        ]);
        $user->assignRole('owner');

        return $user->fresh();
    }

    // ── I1-b — کانال واحد + fallback ──────────────────────────────────────

    /**
     * ⭐ «کانال اصلی» یعنی **همهٔ** نقاط، نه دو تایی که یکی‌شان یادشان مانده.
     *
     * اگر یک نقطه از `pointDeclarations()` رد شود، اعلانش هنوز از validator رد
     * می‌شود و بسته سبز می‌شود ولی هرگز رندر نمی‌گردد — همان باگی که K6.4 برای
     * `site.header_widget` داشت و K6.8 برای `site.page_type`. فهرست زیر عمداً
     * **نام‌برده** است: نقطهٔ تازه‌ای که به این تست اضافه نشود، همین‌جا گم می‌شود.
     */
    public function test_panel_extensions_is_the_primary_channel_for_every_point(): void
    {
        $declared = ManifestRegistry::EXTENSION_POINT_DECLARABLE;

        $this->assertSame(
            [
                'admin.menu',
                'admin.plugin_tools',
                'core.service_provider',
                'site.header_widget',
                'site.footer_widget',
                'site.page_type',
            ],
            $declared,
            'فهرست نقاطی که از `panel.extensions` خوانده می‌شوند عوض شده. اگر نقطه‌ای '
            .'اضافه شده، این فهرست باید به‌روز شود؛ اگر نقطه‌ای از رجیستری افتاد، '
            .'یعنی اعلانش بی‌سروصدا از قهرست حذف می‌شود.'
        );

        foreach ($declared as $point) {
            $manifest = $this->pointOnly($point, $this->probeDeclaration($point));

            $this->assertSame(
                [$this->probeDeclaration($point)],
                array_map(
                    fn (array $d) => array_diff_key($d, ['point' => null]),
                    ManifestRegistry::pointDeclarations($manifest, $point)
                ),
                'نقطهٔ «'.$point.'» از کانال اصلی خوانده نشد.'
            );
        }
    }

    /** اعلانِ کمینه‌ای که وجودش را ثابت می‌کند — محتوایش مهم نیست. */
    private function probeDeclaration(string $point): array
    {
        return match ($point) {
            'admin.menu' => ['key' => 'probe', 'label' => 'پروب', 'href' => '/admin/probe'],
            'admin.plugin_tools' => ['key' => 'probe', 'title_fa' => 'پروب'],
            'core.service_provider' => [
                'interface' => 'App\\Search\\SearchableProvider',
                'class' => 'Pishdad\\Plugins\\Shop\\ProductSearch',
            ],
            'site.header_widget', 'site.footer_widget' => ['type' => 'shop_'.$point],
            'site.page_type' => ['slug' => 'shop_page', 'title_fa' => 'صفحهٔ فروشگاه'],
            default => self::fail('اعلان نمونه برای نقطهٔ «'.$point.'» تعریف نشده.'),
        };
    }

    /**
     * ⭐ مهاجرت دو-خواندنی: نصب قدیمی نباید بشکند.
     *
     * برای هر نقطه‌ای که fallback دارد، همان اعلان از کلید سطح‌بالا هم باید به
     * خروجی رجیستری برسد — **و** اینکه کانال اصلی برندهٔ برخورد باشد.
     */
    public function test_the_legacy_top_level_keys_still_work(): void
    {
        // هر سطر: [مانیفستِ فقط-legacy، خواننده، دلیلِ قابل‌شناساییِ ردیفِ افزونه]
        $cases = [
            [
                ['menu' => ['probe' => ['label' => 'پروب', 'href' => '/admin/probe']]],
                fn () => ManifestRegistry::menuItems(),
                fn (array $row) => ($row['slug'] ?? null) === 'shop' && ($row['key'] ?? null) === 'probe',
            ],
            [
                ['tools' => ['probe' => ['title_fa' => 'پروب']]],
                fn () => ManifestRegistry::pluginTools(),
                fn (array $row) => ($row['slug'] ?? null) === 'shop' && ($row['key'] ?? null) === 'probe',
            ],
            [
                ['widgets' => ['header' => ['shop_head' => ['title' => 'هدر']], 'footer' => ['shop_foot' => ['title' => 'فوتر']]]],
                fn () => ManifestRegistry::widgetSchemas(),
                fn (array $row) => ($row['source'] ?? null) === 'plugin:shop' && $row['type'] === 'shop_head',
            ],
            [
                ['page_types' => ['shop_page' => ['title_fa' => 'صفحهٔ فروشگاه']]],
                fn () => ManifestRegistry::pageTypes(),
                fn (array $row) => ($row['source'] ?? null) === 'plugin:shop' && ($row['title_fa'] ?? null) === 'صفحهٔ فروشگاه',
            ],
        ];

        foreach ($cases as [$legacy, $read, $isOurs]) {
            $this->freshPlugin(['slug' => 'shop'] + $legacy);

            $mine = array_values(array_filter($read(), $isOurs));

            $this->assertNotEmpty(
                $mine,
                'کانال legacy «'.implode(', ', array_keys($legacy)).'» دیگر خوانده نمی‌شود — '
                .'یعنی نصب‌های موجود با مهاجرت می‌شکنند.'
            );
        }
    }

    /**
     * ⭐ ماتریسِ برندهٔ برخورد: در پنج نقطهٔ اول **legacy** برنده است.
     *
     * دلیلش fail-closed به‌سمت نصب‌های موجود است: بسته‌ای که امروز
     * `manifest.menu` دارد، بعد از مهاجرت هم باید همان رفتار را بگیرد. پس
     * این تست یک تصمیم را قفل می‌کند، نه یک تصادف: «یکدست‌کردن» ترتیب،
     * رفتارِ نصب‌های موجود را عوض می‌کند.
     *
     * استثنا پرمیشن است: `access` کانال زندهٔ تازه است و برنده می‌شود (تست
     * جداگانه دارد).
     */
    public function test_the_precedence_matrix_is_pinned_per_point(): void
    {
        // ── page_types: legacy زودتر adopt می‌شود، پس برنده است.
        $this->plugin(['slug' => 'shop', 'panel' => [
            'extensions' => [$this->pageType(['point' => 'site.page_type', 'title_fa' => 'از کانال اصلی'])],
        ], 'page_types' => ['shop_page' => ['title_fa' => 'از کانال قدیمی']]]);

        $types = ManifestRegistry::pageTypes();
        $this->assertSame('از کانال قدیمی', $types['shop_page']['title_fa'], 'page_types: legacy باید برنده باشد.');
        $this->assertSame('plugin:shop', $types['shop_page']['source']);

        // ── widgets: legacy اول merge می‌شود، پس برنده است.
        $this->freshPlugin(['slug' => 'shop', 'panel' => [
            'extensions' => [['point' => 'site.header_widget', 'type' => 'shop_head', 'title' => 'از کانال اصلی']],
        ], 'widgets' => ['header' => ['shop_head' => ['title' => 'از کانال قدیمی']]]]);

        $widget = collect(ManifestRegistry::widgetSchemas())
            ->firstWhere(fn (array $w) => $w['type'] === 'shop_head');

        $this->assertSame('از کانال قدیمی', $widget['title'], 'widgets: legacy باید برنده باشد.');

        // ── menu: سطح‌بالا آخر نوشته می‌شود، پس برنده است.
        $this->freshPlugin(['slug' => 'shop', 'panel' => [
            'extensions' => [['point' => 'admin.menu', 'key' => 'probe', 'label' => 'از کانال اصلی', 'href' => '/admin/a']],
        ], 'menu' => ['probe' => ['label' => 'از کانال قدیمی', 'href' => '/admin/b']]]);

        $item = collect(ManifestRegistry::menuItems())->firstWhere(fn (array $i) => $i['key'] === 'probe');
        $this->assertSame('از کانال قدیمی', $item['label'], 'menu: legacy باید برنده باشد.');

        // ── plugin_tools: همان.
        $this->freshPlugin(['slug' => 'shop', 'panel' => [
            'extensions' => [['point' => 'admin.plugin_tools', 'key' => 'probe', 'title_fa' => 'از کانال اصلی']],
        ], 'tools' => ['probe' => ['title_fa' => 'از کانال قدیمی']]]);

        $tool = collect(ManifestRegistry::pluginTools())->firstWhere(fn (array $t) => $t['key'] === 'probe');
        $this->assertSame('از کانال قدیمی', $tool['title_fa'], 'plugin_tools: legacy باید برنده باشد.');
    }

    /**
     * ⭐ شکل نقشه‌ای (`key => declaration`) هم پذیرفته می‌شود.
     *
     * `ServiceProviderRegistry` این شکل را می‌پذیرفت و `ManifestRegistry` نه —
     * یعنی یک مانیفست می‌توانست در validator سبز شود و در رجیستری بی‌اثر بماند.
     * عمداً «فقط نقشه‌ای» تست می‌شود: کارِ این متد **فیلتر کردن** است، نه
     * حدس زدن اینکه کدام شکل درست است (آن را validator داوری می‌کند).
     */
    public function test_the_point_channel_also_reads_the_map_shape(): void
    {
        $decl = ['point' => 'site.page_type', 'slug' => 'shop_page', 'title_fa' => 'نقشه‌ای'];

        $this->assertSame(
            [$decl],
            ManifestRegistry::pointDeclarations(['panel' => ['extensions' => ['first' => $decl]]], 'site.page_type')
        );

        // و اعلانِ نقطهٔ دیگری نباید نشت کند.
        $this->assertSame(
            [],
            ManifestRegistry::pointDeclarations(['panel' => ['extensions' => ['first' => $decl]]], 'site.header_widget')
        );
    }

    // ── I1.1 — `manifest.widgets.*` ⇒ `site.header_widget`/`site.footer_widget` ──

    /** @dataProvider widgetPoint */
    public function test_a_widget_point_reaches_the_registry(string $point, string $area): void
    {
        $this->plugin(['slug' => 'shop', 'panel' => [
            'extensions' => [['point' => $point, 'type' => 'shop_'.$area, 'title' => 'ویجت فروشگاه']],
        ]]);

        $rows = collect(ManifestRegistry::widgetSchemas())
            ->where('source', 'plugin:shop')->values()->all();

        $this->assertCount(1, $rows);
        $this->assertSame($area, $rows[0]['area'], 'ناحیه باید از خودِ نقطه بیاید، نه از اعلان.');
        $this->assertSame('shop_'.$area, $rows[0]['type']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function widgetPoint(): array
    {
        return ['header' => ['site.header_widget', 'header'], 'footer' => ['site.footer_widget', 'footer']];
    }

    /** و بلعکس: کانال سطح‌بالا هنوز ویجت می‌سازد (مهاجرت، نه حذف). */
    public function test_the_legacy_widget_key_still_reaches_the_registry(): void
    {
        $this->plugin(['slug' => 'shop', 'widgets' => ['header' => ['shop_head' => ['title' => 'هدر قدیمی']]]]);

        $rows = collect(ManifestRegistry::widgetSchemas())->where('source', 'plugin:shop')->values()->all();

        $this->assertCount(1, $rows);
        $this->assertSame('header', $rows[0]['area']);
        $this->assertSame('shop_head', $rows[0]['type']);
    }

    // ── I1.2 — `manifest.page_types` ⇒ `site.page_type` ────────────────────

    public function test_a_page_type_point_reaches_the_registry(): void
    {
        $this->plugin(['slug' => 'shop', 'panel' => [
            'extensions' => [$this->pageType(['point' => 'site.page_type', 'title_fa' => 'فروشگاه'])],
        ]]);

        $types = ManifestRegistry::pageTypes();

        $this->assertArrayHasKey('shop_page', $types);
        $this->assertSame('plugin:shop', $types['shop_page']['source']);
        $this->assertSame('فروشگاه', $types['shop_page']['title_fa']);
    }

    public function test_the_legacy_page_types_key_still_reaches_the_registry(): void
    {
        $this->plugin(['slug' => 'shop', 'page_types' => ['shop_page' => ['title_fa' => 'فروشگاه قدیمی']]]);

        $types = ManifestRegistry::pageTypes();

        $this->assertArrayHasKey('shop_page', $types);
        $this->assertSame('فروشگاه قدیمی', $types['shop_page']['title_fa']);
    }

    /**
     * ⛔ نقطهٔ `site.page_type` تنها نقطهٔ `live` است، پس `enabled => false`
     * باید واقعاً از فهرست تب‌ها حذف شود — وگرنه فرانت تبی می‌کشد که
     * رندرری ندارد.
     */
    public function test_a_disabled_point_declaration_is_dropped(): void
    {
        $this->plugin(['slug' => 'shop', 'panel' => [
            'extensions' => [$this->pageType(['point' => 'site.page_type', 'enabled' => false])],
        ]]);

        $this->assertArrayNotHasKey('shop_page', ManifestRegistry::pageTypes());
    }

    // ── I1.3 — پرمیشن: فیلد جدا، نه نقطهٔ اتصال ─────────────────────────

    /**
     * ⭐ تصمیم، در قالب تست: پرمیشن **نقطهٔ اتصال نیست**.
     *
     * اگر روزی کسی نقطه‌ای به اسم `admin.permissions` به `EXTENSION_POINTS`
     * اضافه کند، این تست می‌افتد — و باید اول تصمیم بگیرد چرا. دلیل کامل در
     * `PluginPackageContract::PERMISSIONS_FIELD`.
     */
    public function test_permissions_is_not_an_extension_point(): void
    {
        $this->assertFalse(
            PluginPackageContract::isKnownExtensionPoint('admin.permissions'),
            'پرمیشن authorization است نه محتوای رندرشدنی؛ نقطهٔ اتصال شدنش «باز بودنِ» بی‌مهار می‌سازد.'
        );

        $this->assertSame('access', PluginPackageContract::PERMISSIONS_FIELD);
        $this->assertSame('permissions', PluginPackageContract::PERMISSIONS_FIELD_LEGACY);
    }

    public function test_the_access_field_is_the_live_permission_channel(): void
    {
        $rows = ManifestRegistry::manifestPermissions([
            'access' => [['module' => 'reports', 'title_fa' => 'گزارش‌ها', 'actions' => ['view', 'edit']]],
        ], 'shop');

        $this->assertCount(1, $rows);
        $this->assertSame('reports', $rows[0]['module']);
        $this->assertSame('plugin:shop:reports', $rows[0]['name']);
        $this->assertSame(['view', 'edit'], $rows[0]['actions']);
    }

    public function test_the_legacy_permissions_field_still_works(): void
    {
        $rows = ManifestRegistry::manifestPermissions([
            'permissions' => [['module' => 'reports', 'title_fa' => 'گزارش‌ها']],
        ], 'shop');

        $this->assertCount(1, $rows);
        $this->assertSame('plugin:shop:reports', $rows[0]['name']);
        $this->assertSame(ManifestRegistry::ACTIONS, $rows[0]['actions'], 'نبودِ `actions` باید پیش‌فرض باشد.');
    }

    /**
     * ⭐ برندهٔ دو کانال، کانال زنده است — وگرنه افزونه با تکرار کلید قدیمی
     * مجموعهٔ ناقصی می‌سازد و بی‌سروصدا بخشی از دسترسی‌هایش را از دست می‌دهد.
     */
    public function test_the_access_field_wins_over_the_legacy_permissions_field(): void
    {
        $rows = ManifestRegistry::manifestPermissions([
            'access' => [['module' => 'reports']],
            'permissions' => [['module' => 'reports'], ['module' => 'coupons']],
        ], 'shop');

        $this->assertCount(1, $rows, 'کانال قدیمی نباید وقتی `access` هست خوانده شود.');
        $this->assertSame('reports', $rows[0]['module']);
    }

    /**
     * ⛔ `access` حاضر ولی خراب ⇒ کانال قدیمی **نمی‌آید**.
     *
     * fail-closed: وجودِ کانال زنده یعنی تصمیم نویسنده همین است. برگشتن به
     * کانال قدیمی یعنی «شاید منظورش این بود» — و نتیجه‌اش دسترسی‌هایی است که
     * نویسنده حذفشان کرده بود.
     */
    public function test_a_broken_access_field_does_not_fall_back_to_the_legacy_field(): void
    {
        $this->assertSame([], ManifestRegistry::manifestPermissions([
            'access' => 'not-an-array',
            'permissions' => [['module' => 'reports']],
        ], 'shop'));
    }

    // ── I6 — namespacing با اسلاگ ─────────────────────────────────────────

    /**
     * ⭐ I6 — دو افزونه هر دو `module: "reports"` می‌نویسند و هر دو ردیف
     * `reports.view` می‌ساختند: یعنی یکی مال دیگری را می‌دید. namespacing با
     * اسلاگ این را می‌بندد و این تست همان را نگه می‌دارد.
     */
    public function test_permission_modules_are_namespaced_by_plugin_slug(): void
    {
        $entry = [['module' => 'reports', 'title_fa' => 'گزارش‌ها']];

        $shop = ManifestRegistry::manifestPermissions(['access' => $entry], 'shop');
        $blog = ManifestRegistry::manifestPermissions(['access' => $entry], 'blog');

        $this->assertSame('plugin:shop:reports', $shop[0]['name']);
        $this->assertSame('plugin:blog:reports', $blog[0]['name']);
        $this->assertNotSame($shop[0]['name'], $blog[0]['name']);

        // ماژول خام دست‌نخورده می‌ماند تا نمایش فارسی‌اش («گزارش‌ها») نشکند.
        $this->assertSame('reports', $shop[0]['module']);
    }

    public function test_two_plugins_declaring_the_same_module_both_stay(): void
    {
        $entry = [['module' => 'reports', 'title_fa' => 'گزارش‌ها']];

        $this->plugin(['slug' => 'shop', 'access' => $entry], approved: true);
        $this->plugin(['slug' => 'blog', 'access' => $entry], approved: true);

        $names = collect(ManifestRegistry::permissionModules())
            ->where('source', 'plugin:shop')->pluck('name')->all();
        $blog = collect(ManifestRegistry::permissionModules())
            ->where('source', 'plugin:blog')->pluck('name')->all();

        $this->assertSame(['plugin:shop:reports'], $names);
        $this->assertSame(['plugin:blog:reports'], $blog);
    }

    /**
     * ⭐ کانال legacy پرمیشن هم مثل `hooks` **ساکت** نمی‌ماند.
     *
     * رجیستری آن را می‌خواند تا نصب‌های موجود نشکنند، ولی اگر به نویسنده نگوییم،
     * همان اشتباهِ `hooks` تکرار می‌شود: فکر می‌کند یک مسیر رسمی نوشته و بعداً
     * کلیدش حذف می‌شود.
     */
    public function test_the_legacy_permissions_field_is_nudged_towards_access(): void
    {
        $codes = $this->warningCodesFor(['permissions' => [['module' => 'reports']]]);

        $this->assertContains(
            'access.legacy_permissions_field',
            $codes,
            'کدهای برگشتی: '.implode('، ', $codes)
        );
    }

    /** و وقتی `access` هم حاضر است، هشدار تکراری معنا ندارد. */
    public function test_no_legacy_warning_when_the_live_channel_is_present(): void
    {
        $codes = $this->warningCodesFor([
            'access' => [['module' => 'reports']],
            'permissions' => [['module' => 'reports']],
        ]);

        $this->assertNotContains('access.legacy_permissions_field', $codes);
    }

    /**
     * بستهٔ کوچک برای عبور از `analyze()` — همان الگوی `PluginPackageValidatorTest`.
     *
     * @return list<string> کدهای هشدار
     */
    private function warningCodesFor(array $manifest): array
    {
        $manifest = array_merge(['name' => 'Shop', 'slug' => 'shop', 'version' => '1.0.0'], $manifest);

        $path = tempnam(sys_get_temp_dir(), 'plg').'.zip';

        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->fail('ساخت ZIP آزمایشی ناموفق بود.');
        }
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));
        $zip->addFromString('Laravel/src/Plugin.php', '<?php // stub');
        $zip->close();

        $result = app(\App\Services\Plugins\PluginPackageValidator::class)
            ->analyze($path, ['allowUnsigned' => true]);

        @unlink($path);

        return array_column($result['warnings'] ?? [], 'code');
    }

    // ── I1.4 — `hooks` تمام‌شده ───────────────────────────────────────────

    /**
     * ⭐ چهار نقطهٔ «gone» با هم، چون یکی تنها بی‌فایده است:
     * قرارداد، validator، رجیستری، و UI.
     */
    public function test_hooks_is_gone_from_the_contract_the_registry_and_the_model(): void
    {
        // ۱) قرارداد: نه نقطه‌ای به اسم hooks، نه فیلدی در کلیدهای مانیفست.
        $this->assertFalse(PluginPackageContract::isKnownExtensionPoint('hooks'));
        $this->assertArrayNotHasKey('hooks', PluginPackageContract::EXTENSION_POINTS);
        $this->assertNotContains('hooks', PluginPackageContract::DECLARATION_META_FIELDS);

        // ۲) validator: اعلامش یک هشدارِ صریح می‌دهد، نه سکوت.
        $this->plugin(['slug' => 'shop', 'hooks' => ['order.placed']]);
        $rows = collect(ManifestRegistry::menuItems())->count();
        $this->assertSame(0, $rows, '`hooks` نباید هیچ اعلانی در رجیستری بسازد.');

        // ۳) رجیستری: هیچ نقطه‌ای به رجیستریِ dispatcher می‌رود.
        foreach (array_keys(PluginPackageContract::EXTENSION_POINTS) as $point) {
            $this->assertStringNotContainsString('hook', $point);
        }
    }

    // ── I2 — قرارداد کامل از یک endpoint ──────────────────────────────────

    /**
     * ⭐ شکل endpoint، پین‌شده.
     *
     * فرانت باید از همین یکی بخواند؛ اگر روزی رفت سراغ فهرستِ hardcode یا
     * فایل تولیدشده، drift برمی‌گردد و این تست دیگر آن را نمی‌گیرد. کلیدهای
     * زیر عمداً **نام‌برده**اند.
     */
    public function test_the_contract_endpoint_exposes_the_whole_contract(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $auth->getJson('/api/v1/admin/plugins/contract')
            ->assertOk()
            ->assertJsonPath('data.core_contract_version', PluginPackageContract::CORE_CONTRACT_VERSION)
            ->assertJsonPath('data.channels.primary', 'panel.extensions')
            ->assertJsonPath('data.permissions_field', 'access')
            ->assertJsonPath('data.declaration_meta_fields', PluginPackageContract::DECLARATION_META_FIELDS);

        $payload = $auth->getJson('/api/v1/admin/plugins/contract')->json('data');

        // هر نقطه باید کامل باشد: وضعیت، گشودگی، اسکیما، سقف، و مثال‌ها.
        foreach ($payload['extension_points'] as $point) {
            foreach (['key', 'label_fa', 'status', 'openness', 'openness_why', 'since', 'schema_version', 'schema', 'max', 'example_ok', 'example_bad'] as $field) {
                $this->assertArrayHasKey(
                    $field,
                    $point,
                    'فیلد «'.$field.'» از نقطهٔ «'.$point['key'].'» در قرارداد نیست — فرانت نمی‌تواند آن را رندر کند.'
                );
            }
        }

        // و همان فهرست، بدون هیچ افزایشی.
        $this->assertSame(
            array_keys(PluginPackageContract::EXTENSION_POINTS),
            array_column($payload['extension_points'], 'key')
        );

        // `hooks` صریحاً «حذف‌شده» اعلام می‌شود تا UI بتواند صادقانه پیام بدهد.
        $this->assertContains('hooks', $payload['channels']['removed']);
    }

    /**
     * ECO3 — قرارداد توسعه‌دهنده: کدهای خطا + راهنمای رفع، نمونه‌ها، و
     * مستندات افزونه‌ها.
     *
     * هر کد خطا باید به یک دسته اشاره کند و هر دسته هر چهار متن را داشته
     * باشد، وگرنه UI درمانی برای نشان دادن ندارد و صفحه «ناقصِ ساکت» می‌شود.
     */
    public function test_the_contract_endpoint_exposes_developer_documentation(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');
        $payload = $auth->getJson('/api/v1/admin/plugins/contract')->json('data');

        foreach (['error_code_groups', 'error_codes', 'api_examples', 'plugin_docs'] as $key) {
            $this->assertArrayHasKey($key, $payload, "کلید «{$key}» در قرارداد نیست.");
        }

        $this->assertNotEmpty($payload['error_codes']);
        foreach ($payload['error_codes'] as $code => $group) {
            $this->assertArrayHasKey(
                $group,
                $payload['error_code_groups'],
                "کد خطای «{$code}» به دستهٔ ناموجود «{$group}» اشاره می‌کند."
            );
        }
        foreach ($payload['error_code_groups'] as $group => $meta) {
            foreach (['label_fa', 'label_en', 'remedy_fa', 'remedy_en'] as $field) {
                $this->assertNotSame('', $meta[$field], "دستهٔ «{$group}» فیلد «{$field}» را ندارد.");
            }
        }

        $this->assertNotEmpty($payload['api_examples']);
        foreach ($payload['api_examples'] as $example) {
            foreach (['id', 'title_fa', 'title_en', 'method', 'path', 'note_fa', 'note_en'] as $field) {
                $this->assertArrayHasKey($field, $example, "نمونهٔ «{$example['id']}» فیلد «{$field}» را ندارد.");
            }
        }

        // مستندات افزونه‌ها در نصبِ بدون افزونهٔ مستنددار خالی است — و همین درست است.
        $this->assertIsArray($payload['plugin_docs']);
    }

    /**
     * ECO3 — مستندات API که خودِ افزونه در `manifest.docs` اعلام می‌کند.
     *
     * فقط داده: هیچ کد افزونه‌ای رندر نمی‌شود. ورودی بی‌عنوان یا بدون مسیر
     * بی‌صدا حذف می‌شود (fail-closed).
     */
    public function test_active_plugin_docs_are_merged_into_the_contract(): void
    {
        $this->owner();
        \App\Models\Plugin::query()->create([
            'slug' => 'blog',
            'name' => 'وبلاگ',
            'version' => '1.0.0',
            'active' => true,
            'review_status' => \App\Models\Plugin::REVIEW_APPROVED,
            'manifest' => [
                'name' => 'وبلاگ',
                'slug' => 'blog',
                'version' => '1.0.0',
                'docs' => [
                    ['title_fa' => 'فهرست نوشته‌ها', 'method' => 'get', 'path' => '/v1/p/blog/posts'],
                    ['title_fa' => '', 'path' => '/v1/p/blog/broken'], // بدون عنوان ⇒ حذف
                    ['title_fa' => 'بدون مسیر'], // بدون path ⇒ حذف
                ],
            ],
        ]);
        \App\Services\Plugins\ManifestRegistry::flushCache();

        $auth = $this->actingAs($this->owner(), 'sanctum');
        $docs = $auth->getJson('/api/v1/admin/plugins/contract')->json('data.plugin_docs');

        $this->assertCount(1, $docs);
        $this->assertSame('blog', $docs[0]['slug']);
        $this->assertCount(1, $docs[0]['entries']);
        $this->assertSame('GET', $docs[0]['entries'][0]['method']);
        $this->assertSame('/v1/p/blog/posts', $docs[0]['entries'][0]['path']);
    }

    public function test_the_contract_endpoint_needs_permission(): void
    {
        $this->getJson('/api/v1/admin/plugins/contract')->assertUnauthorized();
    }

    // ── I3 — هر نقطه مثال دارد، یا دلیل نبودنش را ─────────────────────────

    /**
     * ⭐ I3 — «هر نقطه مثال دارد» به‌صورت لفظی **نادرست** است و این تست دقیقاً
     * همان چیزی را نگه می‌دارد که صادقانه ممکن است.
     *
     * برای نقطه‌ای که `schema.fields` خالی و `open_schema` بسته دارد، هر
     * `example_ok`ای که می‌گذاشتیم خودِ validator آن را با `no_schema` رد
     * می‌کرد — یعنی نمایشِ مثالی که سیستم خودش رد می‌کند. پس تضمینِ درست این
     * است: **کلید `example_ok` همیشه هست**، و هرجا خالی است دلیلش هم هست
     * (`openness_why`) تا UI یک چیزِ صادقانه نشان بدهد به‌جای سکوت.
     */
    public function test_every_point_exposes_an_example_or_a_written_reason(): void
    {
        foreach (PluginPackageContract::extensionPoints() as $point) {
            $this->assertArrayHasKey(
                'example_ok',
                $point,
                'نقطهٔ «'.$point['key'].'» کلید `example_ok` ندارد — UI نمی‌تواند بفهمد باید چه‌کند.'
            );

            if ($point['example_ok'] !== []) {
                continue;
            }

            $this->assertGreaterThan(
                30,
                mb_strlen((string) $point['openness_why']),
                'نقطهٔ «'.$point['key'].'» نه مثال دارد و نه دلیلِ نبودنش. UI در این حالت '
                .'یا سکوت می‌کند یا عدد صفر نشان می‌دهد؛ هر دو دروغ‌اند.'
            );

            $this->assertNotEmpty(
                $point['example_bad'],
                'بدون `example_ok` تنها چیزی که UI می‌تواند نشان بدهد `example_bad` است.'
            );
        }
    }

    /**
     * ⛔ هر `example_ok` باید از validator رد شود و — مهم‌تر — رجیستری باید
     * فیلدهایش را واقعاً ببرد. اگر روزی مثالی اضافه شود که خودِ سیستم ردش
     * کند، همین‌جا می‌افتد.
     */
    public function test_every_example_is_accepted_by_the_validator(): void
    {
        foreach (PluginPackageContract::EXTENSION_POINTS as $key => $meta) {
            foreach ($meta['example_ok'] as $i => $example) {
                $issues = PluginPackageContract::validateDeclaration($key, $example);

                $this->assertSame(
                    [],
                    $issues,
                    'مثالِ معتبرِ «'.$key.'» (#'.$i.') خودش توسط validator رد می‌شود.'
                );
            }
        }
    }

    // ── I5 — سازگاری نسخه ─────────────────────────────────────────────────

    /**
     * ⭐ بسته‌ای که برای هستهٔ تازه‌تری نوشته شده، **خطا** می‌گیرد نه هشدار.
     *
     * بدترین حالتِ ممکن این است: بسته نصب شود، سبز باشد، و فیلدهایی که این
     * هسته نمی‌شناسد بی‌اثر بمانند — بدون یک کلمه گله.
     */
    public function test_a_declaration_newer_than_the_core_is_a_clean_error(): void
    {
        $issues = PluginPackageContract::validateDeclaration('site.page_type', $this->pageType([
            'since' => '99.0.0',
        ]));

        $this->assertContains('site.page_type.core_contract.too_new', array_column($issues, 'code'));

        $this->assertSame(
            ['error'],
            array_unique(array_column($issues, 'severity')),
            'ناسازگاری نسخه باید خطا باشد؛ هشدار یعنی «نصب می‌شود و بی‌اثر می‌ماند».'
        );
    }

    public function test_a_declaration_matching_the_core_passes(): void
    {
        $this->assertSame([], PluginPackageContract::validateDeclaration('site.page_type', $this->pageType([
            'since' => PluginPackageContract::CORE_CONTRACT_VERSION,
        ])));
    }

    public function test_a_malformed_since_is_rejected_with_a_named_code(): void
    {
        foreach (['1.6', 'v1.6.0', 'later', ''] as $bad) {
            $codes = array_column(PluginPackageContract::validateDeclaration('site.page_type', $this->pageType([
                'since' => $bad,
            ])), 'code');

            $this->assertContains('site.page_type.core_contract.bad_since', $codes, 'ورودی: '.var_export($bad, true));
        }
    }

    public function test_a_schema_version_newer_than_the_point_is_rejected(): void
    {
        $supported = PluginPackageContract::EXTENSION_POINTS['site.page_type']['schema_version'];

        $codes = array_column(PluginPackageContract::validateDeclaration('site.page_type', $this->pageType([
            'schema_version' => $supported + 1,
        ])), 'code');

        $this->assertContains('site.page_type.core_contract.schema_too_new', $codes);
    }

    public function test_the_supported_schema_version_passes(): void
    {
        $supported = PluginPackageContract::EXTENSION_POINTS['site.page_type']['schema_version'];

        $this->assertSame([], PluginPackageContract::validateDeclaration('site.page_type', $this->pageType([
            'schema_version' => $supported,
        ])));
    }

    /**
     * `since`/`schema_version` فیلدِ «فراداده»‌اند، نه محتوا — پس نباید «فیلد
     * ناشناخه» بخورند، و در عین حال نباید بی‌سروصدا از قاعدهٔ نقطه رد شوند.
     */
    public function test_the_meta_fields_are_not_treated_as_unknown(): void
    {
        $codes = array_column(PluginPackageContract::validateDeclaration('site.header_widget', [
            'type' => 'shop_head',
            'since' => PluginPackageContract::CORE_CONTRACT_VERSION,
            'schema_version' => 1,
        ]), 'code');

        $this->assertSame([], $codes);
    }

    /**
     * ⭐ نگهبانِ خودِ قرارداد: هیچ نقطه‌ای نباید از نسخهٔ هسته تازه‌تر باشد.
     *
     * اگر روزی نقطه‌ای اضافه شد ولی `CORE_CONTRACT_VERSION` بالا نرفت، نصب‌های
     * قدیمی آن نقطه را «می‌شناسند» و اعلامش سبز می‌شود در حالی که رندرری
     * ندارند. `applyVersionRules()` آن را fail-closed می‌کند؛ این تست جلوی
     * به‌exist‌شدنش را می‌گیرد.
     */
    public function test_no_point_is_newer_than_the_core(): void
    {
        foreach (PluginPackageContract::EXTENSION_POINTS as $key => $meta) {
            $this->assertTrue(
                version_compare((string) $meta['since'], PluginPackageContract::CORE_CONTRACT_VERSION, '<='),
                'نقطهٔ «'.$key.'» از '.$meta['since'].' است ولی CORE_CONTRACT_VERSION '
                .PluginPackageContract::CORE_CONTRACT_VERSION.' است. یا نسخهٔ هسته را بالا ببر، '
                .'یا نقطه را از قرارداد بردار.'
            );

            $deprecatedSince = $meta['deprecated']['since'] ?? null;

            $this->assertTrue(
                $deprecatedSince === null || version_compare((string) $deprecatedSince, PluginPackageContract::CORE_CONTRACT_VERSION, '<='),
                'تاریخِ منسوخ‌شدنِ «'.$key.'» از نسخهٔ هسته تازه‌تر است.'
            );
        }
    }

    // ── I7 — رجیستری پویای جستجو ──────────────────────────────────────────

    /**
     * ⭐ افزونه provider می‌آورد و **کنار** provider هسته اجرا می‌شود.
     *
     * نسخهٔ قبلی `config('search.providers')` را مستقیم می‌خواند، یعنی برای
     * افزودن دامنهٔ جستو باید فایل کانفیگ هسته دستی ویرایش می‌شد. و
     * `AdminSearchController` یک *override* تک‌خانه می‌خواست — یعنی دومین
     * افزونه بی‌سروصدا حذف می‌شد. هر دو با این تست بسته می‌شود.
     */
    public function test_a_registered_provider_joins_the_core_ones(): void
    {
        $registry = app(SearchProviderRegistry::class);
        $registry->register(ShopSearchProviderForTest::class, SearchProviderRegistry::CHANNEL_SITE);

        $classes = array_map('get_class', $registry->providers(SearchProviderRegistry::CHANNEL_SITE));

        $this->assertContains(\App\Search\PageSearchProvider::class, $classes, 'پیش‌فرض هسته نباید از بین برود.');
        $this->assertContains(ShopSearchProviderForTest::class, $classes, 'provider ثبت‌شده باید زنده باشد.');
    }

    public function test_the_registry_is_not_just_the_config_list(): void
    {
        config(['search.providers' => []]);

        $registry = app(SearchProviderRegistry::class);
        $this->assertSame([], $registry->providers(SearchProviderRegistry::CHANNEL_SITE));

        $registry->register(ShopSearchProviderForTest::class, SearchProviderRegistry::CHANNEL_SITE);

        $this->assertCount(1, $registry->providers(SearchProviderRegistry::CHANNEL_SITE));
    }

    /** ⛔ کانال‌ها جدا هستند: ثبت در پنل نباید به جستوی عمومی سایت نشت کند. */
    public function test_channels_do_not_leak_into_each_other(): void
    {
        $registry = app(SearchProviderRegistry::class);
        $registry->register(ShopSearchProviderForTest::class, SearchProviderRegistry::CHANNEL_ADMIN);

        $this->assertNotContains(
            ShopSearchProviderForTest::class,
            array_map('get_class', $registry->providers(SearchProviderRegistry::CHANNEL_SITE))
        );
    }

    /** ⛔ کلاسی که رابط را پیاده نمی‌کند، اصلاً ثبت نمی‌شود (نه ساکت، نه مؤثر). */
    public function test_a_class_that_is_not_a_provider_is_rejected(): void
    {
        $registry = app(SearchProviderRegistry::class);
        $registry->register(\stdClass::class, SearchProviderRegistry::CHANNEL_SITE);

        $this->assertNotContains(
            \stdClass::class,
            array_map('get_class', $registry->providers(SearchProviderRegistry::CHANNEL_SITE))
        );
    }

    /**
     * ⭐ اعلانِ مانیفست: provider افزونه‌ای **افزایشی** است، نه جایگزینی.
     *
     * `ServiceProviderRegistry::declaredBindings()` تک‌خانه است — برای درگاه
     * پرداخت درست، برای جستو غلط. این تست همان تفاوت را قفل می‌کند.
     */
    public function test_plugin_search_providers_are_additive_not_an_override(): void
    {
        $this->plugin(['slug' => 'shop', 'panel' => ['extensions' => [
            ['point' => 'core.service_provider', 'interface' => SearchableProvider::class, 'class' => ShopSearchProviderForTest::class],
            ['point' => 'core.service_provider', 'interface' => SearchableProvider::class, 'class' => CouponSearchProviderForTest::class],
        ]]], approved: true);

        $declared = app(ServiceProviderRegistry::class)->declaredSearchProviders();

        $this->assertContains(ShopSearchProviderForTest::class, $declared);
        $this->assertContains(
            CouponSearchProviderForTest::class,
            $declared,
            'دو provider باید هر دو زنده بمانند؛ «یکی برنده» برای جستو یعنی افزونهٔ دوم بی‌سروصدا حذف می‌شود.'
        );

        // و هر دو واقعاً به رجیستری زنده می‌رسند.
        $classes = array_map('get_class', app(SearchProviderRegistry::class)
            ->providers(SearchProviderRegistry::CHANNEL_ADMIN));

        $this->assertContains(ShopSearchProviderForTest::class, $classes);
        $this->assertContains(CouponSearchProviderForTest::class, $classes);
    }

    /** ⛔ افزونهٔ تأییدنشده حق ثبت provider ندارد — مثل override، مثل بقیهٔ نقاط. */
    public function test_an_unapproved_plugin_registers_no_search_provider(): void
    {
        $this->plugin(['slug' => 'shop', 'panel' => ['extensions' => [
            ['point' => 'core.service_provider', 'interface' => SearchableProvider::class, 'class' => ShopSearchProviderForTest::class],
        ]]], approved: false);

        $this->assertSame([], app(ServiceProviderRegistry::class)->declaredSearchProviders());
    }

    /** ⛔ کلاس بیرون از ریشهٔ اجباری افزونه — مال هسته است، نه افزونه. */
    public function test_a_core_class_cannot_be_declared_as_a_plugin_search_provider(): void
    {
        $this->plugin(['slug' => 'shop', 'panel' => ['extensions' => [
            ['point' => 'core.service_provider', 'interface' => SearchableProvider::class, 'class' => \App\Search\PageSearchProvider::class],
        ]]], approved: true);

        $this->assertSame([], app(ServiceProviderRegistry::class)->declaredSearchProviders());
    }
}