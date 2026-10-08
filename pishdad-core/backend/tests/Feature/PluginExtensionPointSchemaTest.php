<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Services\Plugins\ManifestRegistry;
use App\Services\Plugins\PluginPackageContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ K3.11 + K3.9 — قراردادِ نقطهٔ اتصال و **مصرف‌کنندهٔ واقعی** باید یک حرف بزنند.
 *
 * ## چرا فقط «مثال معتبر است» کافی نیست
 *
 * `PluginPackageValidatorTest::test_every_point_has_a_valid_example` تضمین می‌کند
 * مثالِ قرارداد از validator رد نشود. ولی این فقط **نیمهٔ** قرارداد است: ممکن است
 * فیلدی در schema اعلام شود که هیچ رندرری نخواند، و آن‌وقت دقیقاً همان چیزی
 * می‌شود که K3.8 برایش بسته شد — «قولِ ساختاریافته‌ای که سه ماه بعد دروغ می‌گوید».
 *
 * پس این تست از سمت دیگر هم نگاه می‌کند: یک اعلانِ **واقعاً معتبر** را از راه
 * validator رد می‌کند، به رجیستری می‌دهد و می‌پرسد آیا فیلدهایش همان‌طور که اعلام
 * شده‌اند به خروجی رسیدند. اگر کسی فیلدی به قرارداد اضافه کند که رجیستری
 * نمی‌شناسد، یا نامِ فیلدِ مصرف‌شده را عوض کند، همین‌جا قرمز می‌شود.
 *
 * این آزمون عملاً **mutation-verified** است: با حذفِ هر قاعدهٔ زیر، یکی از
 * تست‌های این فایل می‌افتد.
 */
class PluginExtensionPointSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ManifestRegistry::flushCache();
    }

    private function plugin(array $extensions): Plugin
    {
        $p = Plugin::query()->create([
            'slug' => 'shop',
            'name' => 'Shop',
            'author' => 'test',
            'version' => '1.0.0',
            'signature' => 'x',
            'public_key' => 'x',
            'manifest' => ['panel' => ['extensions' => $extensions]],
            'active' => true,
        ]);

        ManifestRegistry::flushCache();

        return $p;
    }

    /**
     * @return list<array{severity: string, code: string, path: string, message: string}>
     */
    private function issues(string $point, array $decl): array
    {
        return PluginPackageContract::validateDeclaration($point, $decl);
    }

    /** @return list<string> */
    private function codes(string $point, array $decl): array
    {
        return array_values(array_unique(array_column($this->issues($point, $decl), 'code')));
    }

    // ── K3.11 — `admin.plugin_tools` ─────────────────────────────────────────

    /**
     * مثالِ معتبرِ خودِ قرارداد باید واقعاً معتبر باشد — و این با تست‌های موجود
     * پوشش دارد، ولی اینجا یک چیزِ دیگر هم اضافه می‌شود: **همان اعلان** باید
     * بی‌خطا از رجیستری هم رد شود.
     */
    public function test_the_tools_example_survives_the_registry(): void
    {
        $example = PluginPackageContract::EXTENSION_POINTS['admin.plugin_tools']['example_ok'][0];

        $this->assertSame([], $this->codes('admin.plugin_tools', $example));

        $this->plugin([array_merge(['point' => 'admin.plugin_tools'], $example)]);

        $tools = collect(ManifestRegistry::pluginTools())->values()->all();

        $this->assertCount(1, $tools);
        $this->assertSame('sync', $tools[0]['key']);
        $this->assertSame('همگام‌سازی', $tools[0]['title_fa']);
        $this->assertSame('↻', $tools[0]['icon']);
        $this->assertSame('کشورها و موجودی را با مرکز هم‌گام کن', $tools[0]['description']);
        $this->assertSame(12, $tools[0]['notification_id']);
        $this->assertSame('plugin:shop:sync.run', $tools[0]['permission']);
    }

    /**
     * ⭐ فیلدِ قراردادی که رجیستری نمی‌شناسد، یعنی دروغِ ساختاریافته.
     *
     * `pluginTools()` این کلیدها را برمی‌گرداند و `slug` مالِ خودِ افزونه است نه
     * چیزی که اعلام می‌شود. پس هر فیلدِ دیگری در قرارداد باید دقیقاً یکی از این‌ها
     * باشد. اگر کسی `order` یا `body` اضافه کند — همان دو چیزی که K3.11 عمداً
     * ردشان کرد — همین تست می‌افتد و دلیلش را هم می‌گوید.
     */
    public function test_every_tools_field_is_one_the_registry_actually_carries(): void
    {
        $fields = array_keys(PluginPackageContract::EXTENSION_POINTS['admin.plugin_tools']['schema']['fields']);

        $this->plugin([[
            'point' => 'admin.plugin_tools',
            'key' => 'probe', 'title_fa' => 'پروب', 'icon' => 'i', 'description' => 'd',
            'notification_id' => 3, 'permission' => 'plugin:shop:probe.view',
        ]]);

        $carried = array_keys(collect(ManifestRegistry::pluginTools())->values()->all()[0]);
        $carried = array_values(array_diff($carried, ['slug']));

        sort($fields);
        sort($carried);

        $this->assertSame(
            $carried,
            $fields,
            'فیلدهای schema و خروجی رجیستری یکی نیستند. فیلدی که رجیستری نبرد = '
            .'بسته سبز می‌شود و آن فیلد هرگز خوانده نمی‌شود؛ فیلدی که رجیستری می‌آورد '
            .'ولی در قرارداد نیست = نویسنده نمی‌تواند آن را اعلام کند.'
        );
    }

    /** ⛔ `href`: قاعده‌ای که از روز اول زنده بوده و حالا واقعاً قابل دور زدن است. */
    public function test_a_tool_may_not_carry_an_href(): void
    {
        $this->assertSame(
            ['admin.plugin_tools.bad_field'],
            $this->codes('admin.plugin_tools', [
                'key' => 'sync', 'title_fa' => 'همگام‌سازی', 'href' => '/outside/steal',
            ])
        );
    }

    /** ⛔ `order`/`body` — تلهٔ K3.11. این‌ها در هیچ رندرری خوانده نمی‌شوند. */
    public function test_order_and_body_are_not_accepted(): void
    {
        $this->assertSame(
            ['admin.plugin_tools.bad_field'],
            $this->codes('admin.plugin_tools', [
                'key' => 'sync', 'title_fa' => 'همگام‌سازی', 'order' => 10, 'body' => ['t' => 'x'],
            ])
        );

        $this->assertArrayNotHasKey(
            'order',
            PluginPackageContract::EXTENSION_POINTS['admin.plugin_tools']['schema']['fields']
        );
        $this->assertArrayNotHasKey(
            'body',
            PluginPackageContract::EXTENSION_POINTS['admin.plugin_tools']['schema']['fields']
        );
    }

    /**
     * `notification_id` در URL می‌رود، پس رشته نباید پذیرفته شود. رجیستری رشتهٔ
     * عددی را بی‌صدا `null` می‌کرد و ابزار «کار می‌کرد ولی به جایی نمی‌رسید».
     */
    public function test_notification_id_must_be_a_positive_integer(): void
    {
        $this->assertSame(['admin.plugin_tools.bad_type'], $this->codes('admin.plugin_tools', [
            'key' => 'sync', 'title_fa' => 'همگام‌سازی', 'notification_id' => '12',
        ]));

        $this->assertSame(['admin.plugin_tools.out_of_range'], $this->codes('admin.plugin_tools', [
            'key' => 'sync', 'title_fa' => 'همگام‌سازی', 'notification_id' => 0,
        ]));
    }

    public function test_a_tool_without_a_title_is_rejected_before_the_registry_can_drop_it(): void
    {
        $this->assertSame(
            ['admin.plugin_tools.missing_field'],
            $this->codes('admin.plugin_tools', ['key' => 'sync', 'icon' => '↻'])
        );
    }

    public function test_a_tool_permission_must_carry_the_plugin_prefix(): void
    {
        $this->assertSame(['admin.plugin_tools.bad_permission'], $this->codes('admin.plugin_tools', [
            'key' => 'sync', 'title_fa' => 'همگام‌سازی', 'permission' => 'sync.run',
        ]));
    }

    public function test_a_tool_key_must_survive_as_a_dom_and_react_key(): void
    {
        $this->assertSame(['admin.plugin_tools.bad_pattern'], $this->codes('admin.plugin_tools', [
            'key' => 'Sync Jobs', 'title_fa' => 'همگام‌سازی',
        ]));
    }

    // ── K3.9 — `site.header_widget` / `site.footer_widget` ────────────────────

    /**
     * @dataProvider widgetPoints
     */
    public function test_a_widget_example_survives_the_registry(string $point, string $area): void
    {
        $example = PluginPackageContract::EXTENSION_POINTS[$point]['example_ok'][0];

        $this->assertSame([], $this->codes($point, $example));

        $this->plugin([array_merge(['point' => $point], $example)]);

        // `values()` لازم است: خروجی رجیستری با ویجت‌های هسته شروع می‌شود، پس
        // کلیدِ سطرِ افزونه شمارهٔ صفر نیست و `$rows[0]` اصلاً وجود ندارد.
        $rows = collect(ManifestRegistry::widgetSchemas())
            ->where('source', 'plugin:shop')
            ->values()
            ->all();

        $this->assertCount(1, $rows);
        $this->assertSame($area, $rows[0]['area'], 'ناحیه باید از خودِ نقطه بیاید، نه از اعلان.');
        $this->assertSame($example['type'], $rows[0]['type']);
        $this->assertSame($example['title'], $rows[0]['title']);
        $this->assertSame($example['description'], $rows[0]['description']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function widgetPoints(): array
    {
        return [
            'header' => ['site.header_widget', 'header'],
            'footer' => ['site.footer_widget', 'footer'],
        ];
    }

    /**
     * ⛔ فیلدهایی که رجیستری می‌خواند ولی micro-grammar **نمی‌تواند** بیانشان کند.
     *
     * `schema` و `ui` آبجکت JSON تودرتو هستند و `GRAMMAR_TYPES` هیچ نوعِ آبجکتی
     * ندارد. این تست آن‌ها را در قرارداد **نمی‌خواهد** و به‌جایش صریح می‌گوید
     * چرا: اگر روزی کسی `depth` را بالا ببرد و این‌ها را اضافه کند، بدون تصمیم
     * معماری دربارهٔ سقف عمقِ همهٔ نقاط خواهد شد — و این تست باید جلویش را بگیرد.
     */
    public function test_widgets_cannot_claim_the_nested_fields_the_grammar_cannot_express(): void
    {
        foreach (['site.header_widget', 'site.footer_widget'] as $point) {
            $fields = PluginPackageContract::EXTENSION_POINTS[$point]['schema']['fields'];

            foreach (['schema', 'ui'] as $nested) {
                $this->assertArrayNotHasKey(
                    $nested,
                    $fields,
                    '«'.$nested.'» آبجکت تودرتو است و در micro-grammar جایی ندارد. اگر اضافه شد، '
                    .'یعنی سقف عمق برای همهٔ نقاط عوض شده — که تصمیم معماری است، نه کارِ این ردیف.'
                );
            }

            // و اعلانِ حاملِ آن‌ها هم باید رد شود، نه اینکه بی‌سروصدا نادیده بماند.
            $this->assertSame(
                [$point.'.bad_field'],
                $this->codes($point, [
                    'type' => 'shop_cart',
                    'schema' => ['type' => 'object', 'properties' => ['coupon' => ['type' => 'string']]],
                ])
            );
        }
    }

    public function test_a_widget_type_colliding_with_a_core_widget_is_an_error_not_a_silent_loss(): void
    {
        // رجیستری هسته را اول ثبت می‌کند و بازنده را بی‌صدا حذف می‌کند. پس اینجا
        // باید **خطا** بدهد، نه اینکه بسته سبز شود و ویجت نیاید.
        $this->assertSame(['site.header_widget.reserved_name'], $this->codes('site.header_widget', ['type' => 'nav']));
        $this->assertSame(['site.footer_widget.reserved_name'], $this->codes('site.footer_widget', ['type' => 'copyright']));
    }

    public function test_a_widget_without_a_type_is_rejected(): void
    {
        $this->assertSame(['site.header_widget.missing_field'], $this->codes('site.header_widget', ['title' => 'بی‌نوع']));
    }

    // ── نقطه‌هایی که K3.9 **عمداً** به آن‌ها schema نداد ──────────────────────

    /**
     * ⭐ فهرستِ «schema نگرفت» یک **تصمیم** است، نه یک فراموشی — پس باید نام‌برده بماند.
     *
     * K3.9 هفت نقطه را بررسی کرد و به سه تایی schema داد. این پنج تا عمداً نگرفتند:
     * یا رندرر ندارند، یا کانالِ زنده‌شان جای دیگری است، یا `deferred` هستند. دلیل
     * هر کدام بالای خودش در `PluginPackageContract` نوشته شده و این تست همان سه
     * شرط را نگه می‌دارد: **بدون schema، بدون مثالِ معتبر، و با دلیلِ مکتوب.**
     *
     * اگر روزی برای یکی از این‌ها schema نوشته شد، همین تست می‌افتد و مجبور
     * می‌شود دلیل را عوض کند — که دقیقاً همان لحظه‌ای است که باید رندرر ثابت شده باشد.
     *
     * @dataProvider schemalessPoints
     */
    public function test_a_schemaless_point_is_deliberate(string $point, string $status): void
    {
        $this->assertArrayHasKey(
            $point,
            PluginPackageContract::EXTENSION_POINTS,
            'نقطهٔ «'.$point.'» از قرارداد حذف شده. حذفِ کامل یک تصمیمِ جداگانه است، نه نتیجهٔ K3.9.'
        );

        $meta = PluginPackageContract::EXTENSION_POINTS[$point];

        $this->assertSame([], $meta['schema']['fields'], '«'.$point.'» نباید schema داشته باشد.');
        $this->assertFalse($meta['open_schema'], '«'.$point.'» نباید واژگان باز داشته باشد.');
        $this->assertSame([], $meta['example_ok'] ?? null, '«'.$point.'» نباید مثالِ معتبر بدهد.');
        $this->assertSame(
            $status,
            $meta['status'] ?? null,
            'وضعیت «'.$point.'» عوض شده — یا باید schema بیاورد یا دلیلش را به‌روز کند.'
        );

        $this->assertGreaterThan(
            30,
            mb_strlen((string) ($meta['openness_why'] ?? '')),
            '«'.$point.'» دلیلش را توضیح نداده. خالی بودنِ `fields` بدون دلیل، بی‌دقتی به نظر می‌رسد.'
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function schemalessPoints(): array
    {
        return [
            // رندرر ندارد و پذیرفته‌شده به‌عنوان توانایی هم نیست (X2).
            'admin.dashboard_slot' => ['admin.dashboard_slot', 'deferred'],
            // `deprecated`: به `manifest.settings` منتقل شد (K6.7).
            'admin.settings_schema' => ['admin.settings_schema', 'deprecated'],
            // رندرر دارد ولی نقطه را نمی‌بیند؛ کانال زنده `manifest.pages` است.
            'admin.data_collection' => ['admin.data_collection', 'declared_only'],
            // عمداً رندر نمی‌شود: `lib/block-type.ts` برای type ناشناخته چیزی نمی‌کشد.
            'site.block_type' => ['site.block_type', 'declared_only'],
            // رندرر دارد ولی فقط از `manifest.pages` می‌خواند، نه از این نقطه.
            'admin.page_registry' => ['admin.page_registry', 'deferred'],
        ];
    }

    /**
     * خودِ نگهبان: اگر یکی از این پنج نقطه از قرارداد حذف شود، یعنی کسی تصمیمِ
     * جداگانه‌ای گرفته که این تست هنوز از آن خبر ندارد. حذف بی‌صدا نباید بگذرد.
     */
    public function test_the_schemaless_list_is_not_a_place_to_hide_a_point(): void
    {
        $schemaless = [];
        foreach (PluginPackageContract::EXTENSION_POINTS as $key => $meta) {
            if (($meta['schema']['fields'] ?? []) === [] && ($meta['open_schema'] ?? false) === false) {
                $schemaless[] = $key;
            }
        }

        $this->assertSame(
            array_keys(self::schemalessPoints()),
            $schemaless,
            'فهرست نقاطِ بدون schema عوض شده. اگر نقطه‌ای schema گرفت، دلیلش را بنویس؛ '
            .'اگر حذف شد، تصمیمِ جداگانه‌ی خودِ مال محصول است.'
        );
    }
}
