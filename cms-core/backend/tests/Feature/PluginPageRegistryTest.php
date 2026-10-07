<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\User;
use App\Services\Plugins\ManifestRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * K6.5 — رجیستری صفحه‌های اختصاصی افزونه‌ها.
 *
 * این مسیر تعیین می‌کند کدام مسیر زیر `/admin/` متعلق به افزونه است. پیش از
 * این، چنین مسیری به رندرکنندهٔ **سایت عمومی** می‌افتاد.
 */
class PluginPageRegistryTest extends TestCase
{
    /**
     * `RefreshDatabase` عمدی است و چیزی که اول از همه اضافه شد.
     *
     * بدون آن، رکورد افزونه‌های این تست‌ها در دیتابیس **می‌ماند** و تست‌های
     * دیگری که انتظار جدول خالی دارند می‌شکنند — `PluginsTest:141` با
     * `assertDatabaseCount('plugins', 0)` دقیقاً همین‌جا قرمز شد. یعنی یک تست
     * که «جدول خالی» می‌خواست، با اجرای تست دیگری خراب می‌شد.
     *
     * دستی پاک‌کردن در `setUp` هم کافی نبود، چون تراکنشِ بازِ تست دیگری را
     * نمی‌بیند.
     */
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // `RefreshDatabase` جدول را خالی می‌کند ولی **کش** مانیفست‌ها را نه.
        // بدون این، تست بعدی مانیفست تست قبلی را می‌خواند.
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

    private function plugin(?array $manifest = null): Plugin
    {
        $p = Plugin::query()->create([
            'slug' => 'p'.substr(md5(uniqid('', true)), 0, 12),
            'name' => 'p',
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

    public function test_pages_endpoint_returns_registered_entries(): void
    {
        $this->plugin(['pages' => [
            'orders' => ['path' => '/admin/shop/orders', 'title_fa' => 'سفارش‌ها', 'permission' => 'shop:orders.view'],
        ]]);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $res = $auth->getJson('/api/v1/admin/plugins/pages');

        $res->assertOk();
        $res->assertJsonPath('data.pages.0.path', '/admin/shop/orders');
        $res->assertJsonPath('data.pages.0.title_fa', 'سفارش‌ها');
        $res->assertJsonPath('data.pages.0.permission', 'shop:orders.view');
    }

    public function test_pages_endpoint_needs_no_plugin_permission(): void
    {
        // catch-all باید بداند مسیر ثبت شده تا «دسترسی ندارید» بدهد، نه
        // «پیدا نشد» — پس این مسیر عمداً `perm:*` ندارد.
        $this->plugin(['pages' => ['a' => ['path' => '/admin/a', 'title_fa' => 'الف']]]);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $auth->getJson('/api/v1/admin/plugins/pages')->assertOk();
    }

    public function test_pages_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/plugins/pages')->assertUnauthorized();
    }

    public function test_path_outside_admin_is_rejected(): void
    {
        // بدون این قاعده یک افزونه می‌توانست مسیری بیرون از `/admin/` را ثبت کند و
        // خودش را جای بخش دیگری جا بزند.
        $this->plugin(['pages' => [
            'bad' => ['path' => '/outside/evil', 'title_fa' => 'بد'],
            'bad2' => ['path' => 'admin/relative', 'title_fa' => 'نسبی'],
        ]]);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $this->assertSame([], $auth->getJson('/api/v1/admin/plugins/pages')->json('data.pages'));
    }

    public function test_traversal_in_path_is_rejected(): void
    {
        $this->plugin(['pages' => [
            'x' => ['path' => '/admin/../outside/evil', 'title_fa' => 'Traversal'],
        ]]);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $this->assertSame([], $auth->getJson('/api/v1/admin/plugins/pages')->json('data.pages'));
    }

    public function test_double_slash_in_path_is_rejected(): void
    {
        $this->plugin(['pages' => ['x' => ['path' => '/admin//a//b', 'title_fa' => 'دوبل']]]);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $this->assertSame([], $auth->getJson('/api/v1/admin/plugins/pages')->json('data.pages'));
    }

    public function test_trailing_slash_is_normalized_away(): void
    {
        // `/admin/a` و `/admin/a/` باید یک مسیر باشند، وگرنه در Next.js
        // دو رندر متفاوت برای یک صفحه می‌ساخت.
        $this->plugin(['pages' => ['a' => ['path' => '/admin/a/', 'title_fa' => 'الف']]]);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $this->assertSame('/admin/a', $auth->getJson('/api/v1/admin/plugins/pages')->json('data.pages.0.path'));
    }

    public function test_path_defaults_to_admin_slash_key(): void
    {
        $p = $this->plugin(['pages' => ['billing' => ['title_fa' => 'صورتحساب']]]);

        // اگر manifest در دیتابیس شکل دیگری برگشته باشد، تست باید **پیام
        // روشن** بدهد نه اینکه صرفاً «فهرست خالی بود» بگوید و ما حدس بزنیم
        // مشکل از کجاست.
        //
        // `manifest` روی مدل cast آرایه دارد، پس همین‌جا آرایه است و نیازی به
        // `json_decode` نیست. (تلاش اول آن را string فرض کرده بود و خطای
        // «Array to string conversion» داد — که خودش یک تست منفی بود: اگر
        // cast عوض می‌شد، اینجا لو می‌رفت.)
        $stored = $p->fresh()->manifest;
        $this->assertIsArray($stored, 'مانیفست باید به‌صورت آرایه برگردد.');
        $this->assertArrayHasKey('pages', $stored, 'کلید pages باید در مانیفست ذخیره شده باشد.');
        $this->assertIsArray($stored['pages'], 'pages باید object باشد نه فهرست.');
        $this->assertArrayHasKey('billing', $stored['pages'], 'کلید billing باید حفظ شود.');

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $body = $auth->getJson('/api/v1/admin/plugins/pages')->json('data.pages');

        $this->assertIsArray($body);
        $this->assertCount(1, $body);
        $this->assertSame('/admin/billing', $body[0]['path']);
    }

    public function test_duplicate_path_across_plugins_keeps_one(): void
    {
        $this->plugin(['pages' => ['shared' => ['path' => '/admin/shared', 'title_fa' => 'الف']]]);
        $this->plugin(['pages' => ['shared' => ['path' => '/admin/shared', 'title_fa' => 'ب']]]);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $pages = $auth->getJson('/api/v1/admin/plugins/pages')->json('data.pages');

        $this->assertCount(1, $pages, 'دو افزونه نمی‌توانند یک مسیر را بگیرند.');
    }

    public function test_permission_is_null_when_not_declared(): void
    {
        // نبودن `permission` یعنی «برای هر مدیر وارد‌شده باز» — عمداً متفاوت از
        // منو، چون دروازهٔ منو روی خودِ آیتم است و اینجا روی نبودِ پرمیشن نیست.
        $this->plugin(['pages' => ['open' => ['path' => '/admin/open', 'title_fa' => 'باز']]]);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $auth->getJson('/api/v1/admin/plugins/pages')->assertJsonPath('data.pages.0.permission', null);
    }

    public function test_inactive_plugin_pages_are_absent(): void
    {
        $p = $this->plugin(['pages' => ['a' => ['path' => '/admin/a', 'title_fa' => 'الف']]]);
        $p->forceFill(['active' => false])->save();
        ManifestRegistry::flushCache();

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $this->assertSame([], $auth->getJson('/api/v1/admin/plugins/pages')->json('data.pages'));
    }

    public function test_response_does_not_leak_manifest_secrets(): void
    {
        $this->plugin([
            'pages' => ['a' => ['path' => '/admin/a', 'title_fa' => 'الف']],
            'signing' => ['public_key' => 'PUBLIC-KEY-LEAK'],
            'db' => ['tables' => ['secret_table']],
        ]);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $body = $auth->getJson('/api/v1/admin/plugins/pages')->getContent();

        $this->assertStringNotContainsString('PUBLIC-KEY-LEAK', $body);
        $this->assertStringNotContainsString('secret_table', $body);
    }

    public function test_empty_registry_when_nothing_declared(): void
    {
        $this->plugin(['name' => 'Quiet']);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $this->assertSame([], $auth->getJson('/api/v1/admin/plugins/pages')->json('data.pages'));
    }
}
