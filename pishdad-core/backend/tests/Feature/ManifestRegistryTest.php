<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Services\Plugins\ManifestRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * B30 — برخورد `type`/`module` بی‌صدا.
 *
 * هر ادغامی که ورودی تکراری را رد می‌کند باید گزارش دهد؛ رد خام یعنی پلاگین
 * نصب می‌شود ولی هیچ‌جا دیده نمی‌شود و کاربر فکر می‌کند کار می‌کند.
 */
class ManifestRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function activePlugin(string $slug, array $manifest): Plugin
    {
        return Plugin::create([
            'name' => $slug,
            'slug' => $slug,
            'active' => true,
            'manifest' => $manifest,
        ]);
    }

    private function assertCollisionLogged(string $kind, string $key, string $kept, string $dropped): void
    {
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($kind, $key, $kept, $dropped): bool {
                return $message === 'plugin.manifest_collision'
                    && ($context['kind'] ?? null) === $kind
                    && ($context['key'] ?? null) === $key
                    && ($context['kept'] ?? null) === $kept
                    && ($context['dropped'] ?? null) === $dropped;
            })
            ->atLeast()->once();
    }

    private function assertNoCollisionLogged(string $kind, string $because = ''): void
    {
        $notCalled = Log::shouldNotHaveReceived('warning');
        if ($notCalled === null) {
            return;
        }
        $notCalled->withArgs(fn (string $m, array $c): bool => $m === 'plugin.manifest_collision'
            && ($c['kind'] ?? null) === $kind);
        $this->assertTrue(true, $because);
    }

    // ------------------------------------------------------- K6.8 / panel.extensions

    /**
     * K6.8 — اعلان نوع صفحه از راه `panel.extensions` باید واقعاً به فهرست برسد.
     *
     * این مهم‌ترین ادعای این تسک است. تا پیش از آن، `pageTypes()` فقط کلید سطح‌بالای
     * `manifest.page_types` را می‌خواند، درحالی که `PluginPackageValidator`
     * اعلان‌های `panel.extensions` را بررسی می‌کند. یعنی نویسنده اعلانش
     * اعتبارسنجی می‌شد و **هیچ خطایی هم نمی‌گرفت**، ولی نوع صفحه هرگز در فهرست
     * ظاهر نمی‌شد — همان «نصب شد ولی دیده نشد».
     */
    public function test_page_type_declared_via_panel_extensions_reaches_the_list(): void
    {
        $this->activePlugin('alpha', [
            'panel' => [
                'extensions' => [
                    ['point' => 'site.page_type', 'type' => 'glossary', 'title' => 'واژه‌نامه'],
                ],
            ],
        ]);

        $types = ManifestRegistry::pageTypes();

        $this->assertArrayHasKey(
            'glossary',
            $types,
            'اعلان از راه panel.extensions باید در فهرست انواع صفحه باشد.'
        );
        $this->assertSame('واژه‌نامه', $types['glossary']['title'] ?? null);
        $this->assertSame('plugin:alpha', $types['glossary']['source'] ?? null);
    }

    /**
     * `point` و `path` فیلدهای داخلی اعلان‌اند، نه چیزی که بقیهٔ کد باید ببیند.
     */
    public function test_internal_declaration_fields_are_stripped_from_the_merged_page_type(): void
    {
        $this->activePlugin('alpha', [
            'panel' => [
                'extensions' => [
                    ['point' => 'site.page_type', 'type' => 'glossary', 'title' => 'واژه‌نامه'],
                ],
            ],
        ]);

        $def = ManifestRegistry::pageTypes()['glossary'];

        $this->assertArrayNotHasKey('point', $def);
        $this->assertArrayNotHasKey('path', $def);
    }

    /**
     * اعلان نقطهٔ دیگر نباید به‌اشتباه نوع صفحه شود.
     */
    public function test_other_extension_points_do_not_leak_into_page_types(): void
    {
        $this->activePlugin('alpha', [
            'panel' => [
                'extensions' => [
                    ['point' => 'admin.menu', 'type' => 'not_a_page_type'],
                    ['point' => 'site.page_type', 'type' => 'glossary'],
                ],
            ],
        ]);

        $types = ManifestRegistry::pageTypes();

        $this->assertArrayNotHasKey('not_a_page_type', $types);
        $this->assertArrayHasKey('glossary', $types);
    }

    /**
     * برخورد بین دو کانال نباید بی‌صدا باشد.
     *
     * کانال مستقیم‌تر (`page_types`) برنده است چون `$adopt` اولین پذیرنده را نگه
     * می‌دارد و ترتیب merge طوری چیده شده که این کانال اول بیاید. بدون گزارش،
     * نویسنده نمی‌فهمد چرا اعلانش بی‌اثر بود.
     */
    public function test_collision_between_the_two_page_type_channels_is_reported(): void
    {
        $this->activePlugin('alpha', [
            'panel' => [
                'extensions' => [
                    ['point' => 'site.page_type', 'type' => 'faq', 'title' => 'از کانال اعلان'],
                ],
            ],
        ]);
        $this->activePlugin('beta', ['page_types' => ['faq' => ['title' => 'از کانال کلید']]]);

        Log::spy();

        $types = ManifestRegistry::pageTypes();

        $this->assertSame(
            'از کانال کلید',
            $types['faq']['title'] ?? null,
            'کانال کلید سطح‌بالا باید برنده باشد.'
        );
        $this->assertCollisionLogged('page_type', 'faq', 'plugin:beta', 'plugin:alpha');
    }

    /**
     * برخورد با نام هسته هم باید گزارش شود.
     *
     * `archive` یکی از سه نوع صفحهٔ واقعی هسته است (`home`, `single`, `archive`) —
     * تست عمداً از نامی استفاده می‌کند که واقعاً در `config/page_types.php` هست،
     * وگرنه به‌جای «برخورد با هسته» عملاً «برخورد با هیچ‌کس» را می‌سنجید.
     */
    public function test_page_type_colliding_with_core_is_reported(): void
    {
        $this->assertArrayHasKey(
            'archive',
            config('page_types', []),
            'فرض این تست این است که archive نوع صفحهٔ هسته است.'
        );
        $coreTitle = config('page_types.archive.title', null);

        $this->activePlugin('alpha', [
            'panel' => [
                'extensions' => [
                    ['point' => 'site.page_type', 'type' => 'archive', 'title' => 'تله'],
                ],
            ],
        ]);

        Log::spy();

        $types = ManifestRegistry::pageTypes();

        $this->assertSame('core', $types['archive']['source'] ?? null, 'هسته باید برنده باشد.');
        $this->assertSame(
            $coreTitle,
            $types['archive']['title'] ?? null,
            'عنوان اعلان پلاگین نباید روی تعریف هسته بنشیند.'
        );
        $this->assertCollisionLogged('page_type', 'archive', 'core', 'plugin:alpha');
    }

    /**
     * `enabled === false` باید مثل بقیهٔ انواع صفحه فیلتر شود.
     */
    public function test_a_disabled_page_type_from_panel_extensions_is_hidden(): void
    {
        $this->activePlugin('alpha', [
            'panel' => [
                'extensions' => [
                    ['point' => 'site.page_type', 'type' => 'hidden', 'enabled' => false],
                ],
            ],
        ]);

        $this->assertArrayNotHasKey('hidden', ManifestRegistry::pageTypes());
    }

    /**
     * اعلان خراب نباید کل ادغام را از کار بیندازد.
     */
    public function test_malformed_page_type_declarations_are_skipped(): void
    {
        $this->activePlugin('alpha', [
            'panel' => [
                'extensions' => [
                    ['point' => 'site.page_type'],
                    ['point' => 'site.page_type', 'type' => ''],
                    ['point' => 'site.page_type', 'type' => 123],
                    ['point' => 'site.page_type', 'type' => 'good'],
                ],
            ],
        ]);

        $types = ManifestRegistry::pageTypes();

        $this->assertArrayHasKey('good', $types);
        $this->assertCount(1, array_filter($types, fn ($d) => ($d['source'] ?? '') === 'plugin:alpha'));
    }

    // ---------------------------------------------------------------- B30 / page_types

    public function test_duplicate_page_type_from_two_plugins_is_not_silently_dropped(): void
    {
        $this->activePlugin('alpha', ['page_types' => ['faq' => ['title' => 'پرسش‌های الف']]]);
        $this->activePlugin('beta', ['page_types' => ['faq' => ['title' => 'پرسش‌های ب']]]);

        Log::spy();

        ManifestRegistry::pageTypes();

        $this->assertCollisionLogged('page_type', 'faq', 'plugin:alpha', 'plugin:beta');

        $collisions = ManifestRegistry::collisions('page_type');
        $this->assertCount(1, $collisions);
        $this->assertSame('faq', $collisions[0]['key']);
        $this->assertSame('plugin:alpha', $collisions[0]['kept']);
        $this->assertSame('plugin:beta', $collisions[0]['dropped']);
        $this->assertNotSame('', $collisions[0]['message']);
    }

    public function test_unique_page_types_from_two_plugins_merge_without_collision(): void
    {
        $this->activePlugin('alpha', ['page_types' => ['faq' => ['title' => 'پرسش‌های الف']]]);
        $this->activePlugin('beta', ['page_types' => ['glossary' => ['title' => 'واژه‌نامه ب']]]);

        Log::spy();

        $types = ManifestRegistry::pageTypes();

        $this->assertArrayHasKey('faq', $types);
        $this->assertArrayHasKey('glossary', $types);
        $this->assertSame('plugin:alpha', $types['faq']['source']);
        $this->assertSame('plugin:beta', $types['glossary']['source']);
        $this->assertNoCollisionLogged('page_type');
        $this->assertSame([], ManifestRegistry::collisions('page_type'));
    }

    public function test_page_type_colliding_with_core_is_reported_as_dropped(): void
    {
        $this->activePlugin('alpha', ['page_types' => ['home' => ['title' => 'خانه الف']]]);

        Log::spy();

        $types = ManifestRegistry::pageTypes();

        $this->assertSame('core', $types['home']['source']);
        $this->assertCollisionLogged('page_type', 'home', 'core', 'plugin:alpha');
    }

    // --------------------------------------------------------------- B30 / widgets

    public function test_duplicate_widget_type_from_two_plugins_is_not_silently_dropped(): void
    {
        $this->activePlugin('alpha', ['widgets' => ['header' => ['promo' => ['title' => 'بنر الف']]]]);
        $this->activePlugin('beta', ['widgets' => ['header' => ['promo' => ['title' => 'بنر ب']]]]);

        Log::spy();

        $schemas = ManifestRegistry::widgetSchemas();

        $this->assertCollisionLogged('widget', 'header:promo', 'plugin:alpha', 'plugin:beta');

        $collisions = ManifestRegistry::collisions('widget');
        $this->assertCount(1, $collisions);
        $this->assertSame('header:promo', $collisions[0]['key']);

        $promo = array_values(array_filter($schemas, fn (array $w) => $w['type'] === 'promo'));
        $this->assertCount(1, $promo);
        $this->assertSame('plugin:alpha', $promo[0]['source']);
    }

    public function test_unique_widget_types_from_two_plugins_merge_without_collision(): void
    {
        $this->activePlugin('alpha', ['widgets' => ['header' => ['promo' => ['title' => 'بنر الف']]]]);
        $this->activePlugin('beta', ['widgets' => ['footer' => ['promo' => ['title' => 'بنر ب']]]]);

        Log::spy();

        $schemas = ManifestRegistry::widgetSchemas();

        $this->assertNoCollisionLogged('widget');
        $this->assertSame([], ManifestRegistry::collisions('widget'));

        $promo = collect($schemas)->where('type', 'promo')->pluck('area', 'source')->all();
        $this->assertSame(['plugin:alpha' => 'header', 'plugin:beta' => 'footer'], $promo);
    }

    // --------------------------------------------------------------------- B16

    /**
     * رفتار کشف‌شده: `module` عیناً (بدون namespace اسلاگ) نام ماژول می‌شود،
     * پس ماژول هم‌نام دو پلاگین یک ردیف مشترک می‌سازد و مالک دوم گم می‌شود.
     */
    /**
     * B16 — دو افزونه با `module` هم‌نام دیگر **نمی‌توانند** یک ردیف بسازند.
     *
     * رفتار قبلی (که این تست آن را قفل کرده بود) باگ بود: `module` عیناً نام
     * ماژول می‌شد، پس هر دو به `mr_shared.view` می‌رسیدند و تیک‌زدن دسترسی یکی
     * به دیگری هم می‌داد. حالا نام namespaced است.
     */
    public function test_two_plugins_declaring_same_permission_module_get_separate_namespaced_rows(): void
    {
        $this->activePlugin('alpha', ['permissions' => [['module' => 'mr_shared', 'title_fa' => 'الف']]]);
        $this->activePlugin('beta', ['permissions' => [['module' => 'mr_shared', 'title_fa' => 'ب']]]);

        Log::spy();

        $modules = ManifestRegistry::permissionModules();

        $this->assertNoCollisionLogged('permission', 'ماژول هم‌نام دیگر تداخل نیست، چون نامش namespaced شده.');

        $byName = collect($modules)->keyBy('name');
        $this->assertSame('plugin:alpha', $byName['plugin:alpha:mr_shared']['source']);
        $this->assertSame('plugin:beta', $byName['plugin:beta:mr_shared']['source']);
        $this->assertSame(2, $byName->filter(fn ($m) => $m['source'] !== 'core' && str_ends_with((string) $m['name'], ':mr_shared'))->count());
    }

    public function test_distinct_permission_modules_from_two_plugins_are_separate_rows(): void
    {
        $this->activePlugin('alpha', ['permissions' => [['module' => 'mr_alpha_mod', 'title_fa' => 'الف']]]);
        $this->activePlugin('beta', ['permissions' => [['module' => 'mr_beta_mod', 'title_fa' => 'ب']]]);

        Log::spy();

        $modules = ManifestRegistry::permissionModules();

        $this->assertNoCollisionLogged('permission');

        $byName = collect($modules)->keyBy('name');
        $this->assertSame('plugin:alpha', $byName['plugin:alpha:mr_alpha_mod']['source']);
        $this->assertSame('plugin:beta', $byName['plugin:beta:mr_beta_mod']['source']);
    }

    /**
     * برخورد با **هسته** هنوز واقعی است: نام namespaced هست ولی هرسته namespaced
     * نیست، پس `pages.view` هسته با `plugin:alpha:pages.view` افزونه فرق دارد.
     * یعنی افزونه دیگر نمی‌تواند خود را جای ماژول هسته جا بزند — و این دقیقاً
     * همان چیزی است که قبلاً با `continue` بی‌صدا انجام می‌شد.
     */
    public function test_plugin_cannot_shadow_a_core_module_anymore(): void
    {
        $this->activePlugin('alpha', ['permissions' => [['module' => 'pages', 'title_fa' => 'مزاحم']]]);

        Log::spy();

        $modules = ManifestRegistry::permissionModules();

        $this->assertNoCollisionLogged('permission', 'نام افزونه namespaced است، پس با هسته تداخل نمی‌کند.');
        $this->assertSame('core', collect($modules)->firstWhere('name', 'pages')['source'], 'ردیف هسته باید دست‌نخورده بماند.');
        $this->assertSame('plugin:alpha', collect($modules)->firstWhere('name', 'plugin:alpha:pages')['source']);
    }
}
