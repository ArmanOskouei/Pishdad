<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\User;
use App\Services\Plugins\ManifestRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * K6.2 — ابزارهای افزونه در drawer هدر.
 *
 * نقطهٔ `admin.plugin_tools` قبلاً `deferred` بود و هیچ‌جا خوانده نمی‌شد.
 * تصمیم محصول: کلید سطح‌بالای `manifest.tools`، چون میکرو-اسکیما سقف عمق ۱ دارد
 * و اسکیمای JSON تودرتو است — همان استدلال K6.4 و K6.7.
 */
class PluginToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ManifestRegistry::flushCache();
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

    /** یک ابزار در کانال `panel.extensions`. */
    private function extension(string $key, array $extra = []): array
    {
        return array_merge([
            'point' => 'admin.plugin_tools',
            'key' => $key,
            'title_fa' => 'ابزار '.$key,
        ], $extra);
    }

    // ── کانال اول زنده شد ────────────────────────────────────────────────

    public function test_top_level_tools_reach_the_registry(): void
    {
        $p = $this->plugin(['tools' => [
            'sync' => ['title_fa' => 'همگام‌سازی', 'icon' => '↻', 'notification_id' => 12],
        ]]);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $res = $auth->getJson('/api/v1/admin/plugins/tools');

        $res->assertOk();
        $res->assertJsonPath('data.tools.0.slug', $p->slug);
        $res->assertJsonPath('data.tools.0.key', 'sync');
        $res->assertJsonPath('data.tools.0.title_fa', 'همگام‌سازی');
        $res->assertJsonPath('data.tools.0.notification_id', 12);
    }

    public function test_panel_extension_channel_is_read_when_no_top_level_key(): void
    {
        $this->plugin(['panel' => ['extensions' => [
            $this->extension('billing', ['title_fa' => 'صورتحساب']),
        ]]]);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $auth->getJson('/api/v1/admin/plugins/tools')->assertJsonPath('data.tools.0.key', 'billing');
    }

    public function test_top_level_tools_win_over_panel_extensions(): void
    {
        // همان قاعدهٔ K6.4: `$owner` اولین ثبت‌کننده را نگه می‌دارد، پس کانال
        // سطح‌بالا باید اول merge شود تا برنده باشد.
        $this->plugin([
            'tools' => ['same' => ['title_fa' => 'از سطح‌بالا']],
            'panel' => ['extensions' => [
                $this->extension('same', ['title_fa' => 'از پنل']),
            ]],
        ]);

        $tools = ManifestRegistry::pluginTools();
        $this->assertCount(1, $tools);
        $this->assertSame('از سطح‌بالا', $tools[0]['title_fa']);
    }

    public function test_other_extension_points_are_not_tools(): void
    {
        // هر دو اعلان از نقطه‌های دیگری می‌آیند و هیچ‌کدام نباید ابزار شوند.
        $this->plugin(['panel' => ['extensions' => [
            ['point' => 'admin.menu', 'key' => 'y', 'title_fa' => 'منو'],
            ['point' => 'site.page_type', 'key' => 'z', 'title_fa' => 'نوع صفحه'],
        ]]]);

        $this->assertSame([], ManifestRegistry::pluginTools());
    }

    public function test_tool_without_a_title_is_dropped(): void
    {
        // بدون عنوان، کارت در drawer چیزی برای نمایش ندارد.
        $this->plugin(['panel' => ['extensions' => [
            ['point' => 'admin.plugin_tools', 'key' => 'notitle'],
        ]]]);

        $this->assertSame([], ManifestRegistry::pluginTools());
    }

    public function test_non_integer_notification_id_becomes_null(): void
    {
        // رشتهٔ عددی رد می‌شود چون بعداً به مسیر تبدیل می‌شود و نوعش باید قطعی
        // باشد. «۱۲» و «12» هر دو غیرقابل‌قبول‌اند.
        $this->plugin(['tools' => [
            'a' => ['title_fa' => 'الف', 'notification_id' => '12'],
            'b' => ['title_fa' => 'ب', 'notification_id' => 0],
            'c' => ['title_fa' => 'ج', 'notification_id' => 7],
        ]]);

        $byKey = collect(ManifestRegistry::pluginTools())->keyBy('key');
        $this->assertNull($byKey['a']['notification_id']);
        $this->assertNull($byKey['b']['notification_id']);
        $this->assertSame(7, $byKey['c']['notification_id']);
    }

    public function test_tool_without_notification_id_is_allowed(): void
    {
        // ابزار بی‌مقصد مجاز است — drawer آن را غیرقابل‌کلیک نشان می‌دهد.
        $this->plugin(['tools' => ['plain' => ['title_fa' => 'ساده']]]);

        $tools = ManifestRegistry::pluginTools();
        $this->assertCount(1, $tools);
        $this->assertNull($tools[0]['notification_id']);
    }

    public function test_duplicate_key_across_plugins_keeps_one(): void
    {
        $this->plugin(['tools' => ['shared' => ['title_fa' => 'الف']]], 'a-first');
        $this->plugin(['tools' => ['shared' => ['title_fa' => 'ب']]], 'z-second');

        $tools = ManifestRegistry::pluginTools();
        $this->assertCount(1, $tools);
        $this->assertSame('a-first', $tools[0]['slug'], 'ترتیب slug قطعی است چون activeManifests مرتب شده.');
    }

    public function test_inactive_plugin_tools_are_absent(): void
    {
        $p = $this->plugin(['tools' => ['x' => ['title_fa' => 'الف']]]);
        $p->forceFill(['active' => false])->save();
        ManifestRegistry::flushCache();

        $this->assertSame([], ManifestRegistry::pluginTools());
    }

    public function test_endpoint_requires_no_permission_and_does_authenticate(): void
    {
        // مثل منو و صفحه: بدون `perm:*`. فیلتر نهایی در فرانت و هنگام اجرای
        // route افزونه است — اگر اینجا گیت بود، منوی ابزارها بی‌صدا خالی می‌شد
        // و دروازهٔ فرانت هرگز امتحان نمی‌شد.
        $this->plugin(['tools' => ['x' => ['title_fa' => 'الف']]]);

        $this->getJson('/api/v1/admin/plugins/tools')->assertUnauthorized();
        $this->actingAs($this->manager(), 'sanctum')
            ->getJson('/api/v1/admin/plugins/tools')->assertOk();
    }

    public function test_response_never_carries_an_href(): void
    {
        // دلیل `no_href` در قرارداد: مسیر باید از رجیستری صفحه‌ها بیاید، نه از
        // اعلان افزونه. اگر `href` در پاسخ بود، این تست باید قرمز می‌شد.
        $this->plugin(['tools' => [
            'x' => ['title_fa' => 'الف', 'href' => '/outside/steal', 'notification_id' => 5],
        ]]);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $body = $auth->getJson('/api/v1/admin/plugins/tools')->getContent();

        $this->assertStringNotContainsString('/outside/steal', $body, 'href افزونه نباید به فرانت برسد.');
        $this->assertStringNotContainsString('"href"', $body);
    }

    public function test_empty_registry_when_nothing_declared(): void
    {
        $this->plugin(['name' => 'Quiet']);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $this->assertSame([], $auth->getJson('/api/v1/admin/plugins/tools')->json('data.tools'));
    }
}
