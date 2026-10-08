<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\User;
use App\Search\SearchableProvider;
use App\Services\Plugins\ManifestRegistry;
use App\Services\Plugins\ServiceProviderRegistry;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Pishdad\Plugins\TestDouble\StubPageProvider;
use Tests\TestCase;

/**
 * B5 — جستجوی پنل باید از سرور بیاید.
 *
 * ریشهٔ باگ: افزونه‌ها با `registerSearchSource({fetchItems})` روی
 * `window.__ADMIN_SEARCH__` ثبت می‌شدند و `fetchItems` از مرورگر به
 * `/api/proxy/v1/admin/...` می‌زد. یعنی افزونه می‌توانست **هر مسیر دلخواهی**
 * از API هسته را صدا بزند — همان چیزی که K1.5.8 می‌خواست جلویش گرفته شود.
 */
class AdminSearchTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/search?q=test')->assertUnauthorized();
    }

    public function test_it_rejects_a_too_short_query(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $auth->getJson('/api/v1/admin/search?q=a')->assertStatus(422)
            ->assertJsonPath('errors.q.0', 'عبارت جستجو حداقل ۲ نویسه است.');
    }

    public function test_it_caps_per_page(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $auth->getJson('/api/v1/admin/search?q=test&per_page=500')->assertStatus(422);
    }

    public function test_it_returns_a_list_shape_even_with_no_providers(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $auth->getJson('/api/v1/admin/search?q=test')->assertOk()
            ->assertJsonPath('data', []);
    }

    /**
     * مسیر داده‌ای باید کار کند: provider افزونه در **سرور** اجرا می‌شود.
     * پیش از این، هیچ مسیری برای افزونه وجود نداشت و تنها راه، `fetchItems`
     * مرورگر بود.
     */
    public function test_a_registered_plugin_provider_runs_server_side(): void
    {
        $this->installPluginProvider();
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $res = $auth->getJson('/api/v1/admin/search?q=بلاگ')->assertOk();

        $this->assertNotEmpty(
            $res->json('data'),
            'پاسخ: '.json_encode($res->json(), JSON_UNESCAPED_UNICODE)
            .' | registry: '.json_encode(
                array_keys(app(ServiceProviderRegistry::class)->declaredBindings())
            )
        );

        $titles = array_column($res->json('data'), 'title');
        $this->assertContains('نوشتهٔ آزمایشی', $titles, 'provider افزونه باید در سرور اجرا شود.');
    }

    /** نصب افزونه‌ای که SearchableProvider اعلام می‌کند. */
    private function installPluginProvider(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'p@example.test'],
            ['name' => 'p', 'password' => 'x']
        );

        Plugin::query()->create([
            'user_id' => $user->id,
            'name' => 'Blog',
            'slug' => 'blog',
            'version' => '1.0.0',
            'active' => true,
            'review_status' => Plugin::REVIEW_APPROVED,
            'manifest' => [
                'name' => 'Blog', 'slug' => 'blog', 'version' => '1.0.0',
                'panel' => ['extensions' => [[
                    'point' => 'core.service_provider',
                    'interface' => SearchableProvider::class,
                    'class' => StubPageProvider::class,
                ]]],
            ],
        ]);

        // رجیستری مانیفست‌های فعال را کش می‌کند؛ بدون این، افزونه‌ای که
        // همین حالا ساخته شده تا پایان TTL نامرئی می‌ماند.
        ManifestRegistry::flushCache();
    }
}
