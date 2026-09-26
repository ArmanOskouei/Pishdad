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

    private function assertNoCollisionLogged(string $kind): void
    {
        $notCalled = Log::shouldNotHaveReceived('warning');
        if ($notCalled === null) {
            return;
        }
        $notCalled->withArgs(fn (string $m, array $c): bool => $m === 'plugin.manifest_collision'
            && ($c['kind'] ?? null) === $kind);
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
    public function test_two_plugins_declaring_same_permission_module_share_one_row_and_report_the_loser(): void
    {
        $this->activePlugin('alpha', ['permissions' => [['module' => 'mr_shared', 'title_fa' => 'الف']]]);
        $this->activePlugin('beta', ['permissions' => [['module' => 'mr_shared', 'title_fa' => 'ب']]]);

        Log::spy();

        $modules = ManifestRegistry::permissionModules();

        $this->assertCollisionLogged('permission', 'mr_shared', 'plugin:alpha', 'plugin:beta');
        $this->assertCount(1, ManifestRegistry::collisions('permission'));

        $names = array_column($modules, 'name');
        $this->assertSame(1, count(array_keys($names, 'mr_shared', true)));
        $this->assertSame('plugin:alpha', collect($modules)->firstWhere('name', 'mr_shared')['source']);
    }

    public function test_distinct_permission_modules_from_two_plugins_are_separate_rows(): void
    {
        $this->activePlugin('alpha', ['permissions' => [['module' => 'mr_alpha_mod', 'title_fa' => 'الف']]]);
        $this->activePlugin('beta', ['permissions' => [['module' => 'mr_beta_mod', 'title_fa' => 'ب']]]);

        Log::spy();

        $modules = ManifestRegistry::permissionModules();

        $this->assertNoCollisionLogged('permission');

        $byName = collect($modules)->keyBy('name');
        $this->assertSame('plugin:alpha', $byName['mr_alpha_mod']['source']);
        $this->assertSame('plugin:beta', $byName['mr_beta_mod']['source']);
    }

    public function test_permission_module_colliding_with_core_is_reported_as_dropped(): void
    {
        $this->activePlugin('alpha', ['permissions' => [['module' => 'pages', 'title_fa' => 'مزاحم']]]);

        Log::spy();

        $modules = ManifestRegistry::permissionModules();

        $this->assertCollisionLogged('permission', 'pages', 'core', 'plugin:alpha');
        $this->assertSame('core', collect($modules)->firstWhere('name', 'pages')['source']);
    }
}
