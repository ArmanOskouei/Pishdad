<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\User;
use App\Services\Plugins\ManifestRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * K6.4 — ویجت‌های header/footer اعلام‌شده در `panel.extensions`.
 *
 * نقطهٔ `site.header_widget` و `site.footer_widget` تا این **اعتبارسنجی می‌شد ولی
 * هرگز خوانده نمی‌شد** — یعنی افزونه ویجت اعلام می‌کرد، هیچ خطایی هم نمی‌گرفت،
 * و ویجت ظاهر نمی‌شد. بدتر از نبودِ قابلیت است، چون نویسنده فکر می‌کند کار کرده.
 */
class PluginWidgetExtensionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ManifestRegistry::flushCache();
    }

    private function plugin(?array $manifest = null, ?string $slug = null): Plugin
    {
        $p = Plugin::query()->create([
            'slug' => $slug ?? ('p'.substr(md5(uniqid('', true)), 0, 12)),
            'name' => 'P',
            'author' => 'test',
            'version' => '1.0.0',
            'signature' => 'x',
            'public_key' => 'x',
            'manifest' => $manifest ?? [],
            'active' => true,
        ]);

        ManifestRegistry::flushCache();

        return $p;
    }

    private function manager(): User
    {
        return User::query()->create([
            'name' => 'مدیر',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Pass!1234'),
            'role' => 'editor',
        ])->fresh();
    }

    /** یک اعلان ویجت در کانال `panel.extensions`. */
    private function extension(string $point, string $type, array $extra = []): array
    {
        return array_merge([
            'point' => $point,
            'type' => $type,
            'title' => 'ویجت تست',
        ], $extra);
    }

    // ── کانال دوم زنده شد ─────────────────────────────────────────────────

    public function test_header_widget_from_panel_extensions_reaches_the_registry(): void
    {
        $this->plugin(['panel' => ['extensions' => [
            $this->extension('site.header_widget', 'shop-cart'),
        ]]]);

        $types = collect(ManifestRegistry::widgetSchemas())
            ->where('area', 'header')
            ->pluck('type')
            ->all();

        $this->assertContains('shop-cart', $types, 'ویجت اعلام‌شده باید در رجیستری بیاید.');
    }

    public function test_footer_widget_from_panel_extensions_reaches_the_registry(): void
    {
        $this->plugin(['panel' => ['extensions' => [
            $this->extension('site.footer_widget', 'newsletter'),
        ]]]);

        $types = collect(ManifestRegistry::widgetSchemas())
            ->where('area', 'footer')
            ->pluck('type')
            ->all();

        $this->assertContains('newsletter', $types);
    }

    public function test_other_extension_points_are_not_treated_as_widgets(): void
    {
        $this->plugin(['panel' => ['extensions' => [
            $this->extension('admin.menu', 'x'),
            $this->extension('site.page_type', 'y'),
        ]]]);

        $sources = collect(ManifestRegistry::widgetSchemas())->pluck('source')->all();
        $this->assertNotContains('plugin:', $sources ? $sources : ['plugin:'], 'نقاط دیگر نباید ویجت شوند.');
    }

    public function test_extension_without_a_type_is_dropped(): void
    {
        // `fields` خالی است پس اعلان بدون `type` اصلاً کاربردی نیست.
        $this->plugin(['panel' => ['extensions' => [
            ['point' => 'site.header_widget', 'title' => 'بدون type'],
        ]]]);

        $sources = collect(ManifestRegistry::widgetSchemas())->pluck('source');
        $this->assertSame(0, $sources->filter(fn ($s) => is_string($s) && str_starts_with($s, 'plugin:'))->count());
    }

    public function test_extension_area_is_taken_from_the_point_not_the_manifest(): void
    {
        // نقطهٔ `site.header_widget` در ناحیهٔ فوتر هم نباید جا بیفتد.
        $this->plugin(['panel' => ['extensions' => [
            $this->extension('site.header_widget', 'in-header'),
        ]]]);

        $rows = collect(ManifestRegistry::widgetSchemas())
            ->filter(fn ($w) => ($w['source'] ?? '') !== 'core');

        $this->assertSame('header', $rows->first()['area']);
    }

    public function test_widget_endpoint_returns_the_extension_channel(): void
    {
        $this->plugin(['panel' => ['extensions' => [
            $this->extension('site.header_widget', 'shop-cart'),
        ]]]);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $types = collect($auth->getJson('/api/v1/admin/widgets/schema')->json('data'))
            ->pluck('type')
            ->all();

        $this->assertContains('shop-cart', $types, 'مسیر زنده باید کانال دوم را هم ببرد.');
    }

    public function test_inactive_plugin_widgets_are_absent(): void
    {
        $p = $this->plugin(['panel' => ['extensions' => [
            $this->extension('site.header_widget', 'off-widget'),
        ]]]);
        $p->forceFill(['active' => false])->save();
        ManifestRegistry::flushCache();

        $sources = collect(ManifestRegistry::widgetSchemas())->pluck('source');
        $this->assertSame(0, $sources->filter(fn ($s) => is_string($s) && str_starts_with($s, 'plugin:'))->count());
    }

    // ── precedence و برخورد ───────────────────────────────────────────────

    public function test_top_level_widgets_win_over_panel_extensions(): void
    {
        // همان قاعدهٔ K6.8 برای `page_types`: کانال مستقیم‌تر برنده است.
        $this->plugin([
            'panel' => ['extensions' => [
                $this->extension('site.header_widget', 'same', ['title' => 'از پنل']),
            ]],
            'widgets' => ['header' => [
                'same' => ['title' => 'از سطح‌بالا'],
            ]],
        ]);

        $row = collect(ManifestRegistry::widgetSchemas())
            ->firstWhere('type', 'same');

        $this->assertNotNull($row);
        $this->assertSame('از سطح‌بالا', $row['title']);
    }

    public function test_duplicate_type_across_plugins_keeps_one(): void
    {
        $this->plugin(['panel' => ['extensions' => [
            $this->extension('site.header_widget', 'dup'),
        ]]], 'a-first');
        $this->plugin(['panel' => ['extensions' => [
            $this->extension('site.header_widget', 'dup'),
        ]]], 'z-second');

        $rows = collect(ManifestRegistry::widgetSchemas())->where('type', 'dup');
        $this->assertSame(1, $rows->count(), 'type تکراری باید یکی شود.');
    }

    public function test_plugin_cannot_override_a_core_widget_type(): void
    {
        // هسته در `$owner` ثبت می‌شود **قبل** از افزونه‌ها، پس افزونه نمی‌تواند
        // جای ویجت هسته را بگیرد — حتی با نام دقیقاً یکسان.
        $coreTypes = collect(ManifestRegistry::widgetSchemas())
            ->where('source', 'core')
            ->pluck('type')
            ->all();

        if ($coreTypes === []) {
            $this->markTestSkipped('config/widgets.php خالی است — قاعده قابل آزمون نیست.');
        }

        $this->plugin(['panel' => ['extensions' => [
            $this->extension('site.header_widget', $coreTypes[0], ['title' => 'ربوده‌شده']),
        ]]]);

        $row = collect(ManifestRegistry::widgetSchemas())
            ->firstWhere('type', $coreTypes[0]);

        $this->assertSame('core', $row['source'], 'ویجت هسته نباید ربوده شود.');
    }

    public function test_a_widget_in_one_area_does_not_block_the_other(): void
    {
        // کلید مالکیت `area:type` است، پس `header:x` و `footer:x` دو ویجت‌اند.
        $this->plugin(['panel' => ['extensions' => [
            $this->extension('site.header_widget', 'twin'),
            $this->extension('site.footer_widget', 'twin'),
        ]]]);

        $rows = collect(ManifestRegistry::widgetSchemas())->where('type', 'twin');
        $this->assertSame(2, $rows->count());
    }

    public function test_no_plugin_declarations_leaves_core_widgets_untouched(): void
    {
        $this->plugin(['name' => 'Quiet']);

        $sources = collect(ManifestRegistry::widgetSchemas())->pluck('source');
        $this->assertSame(0, $sources->filter(fn ($s) => is_string($s) && str_starts_with($s, 'plugin:'))->count());
    }
}
