<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * WF-M13 — تبِ «کارایی»: دامنهٔ دارایی/CDN + توکن پاک‌سازی + lazy/preload.
 *
 * ادعاهای کلیدی: توکن پاک‌سازی در حالت سکون رمزنگاری می‌شود و هرگز به کلاینت
 * برنمی‌گردد؛ توکنِ خالی «بدون تغییر» است؛ دامنه فقط http/https می‌پذیرد؛ و
 * تنظیمات بدون توکن در کروم عمومی افشا می‌شوند (همراستا با CDN-ASSESSMENT).
 */
class PerformanceSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    private function user(array $perms = ['settings.view', 'settings.edit']): User
    {
        $user = User::query()->create([
            'name' => 'مدیر کارایی',
            'email' => 'perf'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
        $user->givePermissionTo(...$perms);

        return $user->fresh();
    }

    public function test_show_returns_defaults_without_token(): void
    {
        $res = $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/v1/admin/settings/performance');

        $res->assertOk()
            ->assertJsonPath('data.asset_domain', null)
            ->assertJsonPath('data.cdn_purge_token_set', false)
            ->assertJsonPath('data.lazy_load_enabled', true)
            ->assertJsonPath('data.font_preload_enabled', true);

        $this->assertArrayNotHasKey('cdn_purge_token', $res->json('data'));
        $this->assertArrayNotHasKey('cdn_purge_token_encrypted', $res->json('data'));
    }

    public function test_update_persists_and_normalizes_domain(): void
    {
        $auth = $this->actingAs($this->user(), 'sanctum');

        $auth->putJson('/api/v1/admin/settings/performance', [
            'asset_domain' => 'https://cdn.example.ir/',
            'lazy_load_enabled' => false,
            'font_preload_enabled' => false,
        ])->assertOk()->assertJsonPath('message', 'تنظیمات کارایی ذخیره شد.');

        $auth->getJson('/api/v1/admin/settings/performance')
            ->assertOk()
            ->assertJsonPath('data.asset_domain', 'https://cdn.example.ir')
            ->assertJsonPath('data.lazy_load_enabled', false)
            ->assertJsonPath('data.font_preload_enabled', false);
    }

    public function test_cdn_purge_token_is_encrypted_at_rest_and_never_returned(): void
    {
        $auth = $this->actingAs($this->user(), 'sanctum');

        $res = $auth->putJson('/api/v1/admin/settings/performance', [
            'asset_domain' => 'https://cdn.example.ir',
            'cdn_purge_token' => 'ArvanPurge!Secret',
        ]);

        $res->assertOk()->assertJsonPath('data.cdn_purge_token_set', true);
        $this->assertArrayNotHasKey('cdn_purge_token', $res->json('data'));

        $stored = Setting::get('performance', 'global');
        $this->assertNotSame('ArvanPurge!Secret', $stored['cdn_purge_token_encrypted']);
        $this->assertSame('ArvanPurge!Secret', Crypt::decryptString($stored['cdn_purge_token_encrypted']));

        $show = $auth->getJson('/api/v1/admin/settings/performance')->assertOk();
        $this->assertArrayNotHasKey('cdn_purge_token', $show->json('data'));
        $this->assertArrayNotHasKey('cdn_purge_token_encrypted', $show->json('data'));
        $this->assertTrue($show->json('data.cdn_purge_token_set'));
    }

    public function test_empty_token_keeps_the_existing_one_and_clear_removes_it(): void
    {
        $auth = $this->actingAs($this->user(), 'sanctum');

        $auth->putJson('/api/v1/admin/settings/performance', [
            'cdn_purge_token' => 'KeepMe!123',
        ])->assertOk()->assertJsonPath('data.cdn_purge_token_set', true);

        // توکن خالی = بدون تغییر.
        $auth->putJson('/api/v1/admin/settings/performance', [
            'asset_domain' => 'https://cdn2.example.ir',
            'cdn_purge_token' => '',
        ])->assertOk()->assertJsonPath('data.cdn_purge_token_set', true);

        // پاک‌کردن صریح.
        $auth->putJson('/api/v1/admin/settings/performance', [
            'clear_cdn_purge_token' => true,
        ])->assertOk()->assertJsonPath('data.cdn_purge_token_set', false);
    }

    public function test_rejects_invalid_asset_domain(): void
    {
        $auth = $this->actingAs($this->user(), 'sanctum');

        $auth->putJson('/api/v1/admin/settings/performance', ['asset_domain' => 'notaurl'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['asset_domain']);

        $auth->putJson('/api/v1/admin/settings/performance', ['asset_domain' => 'ftp://cdn.example.ir'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['asset_domain']);
    }

    public function test_public_chrome_exposes_performance_flags_but_not_the_token(): void
    {
        $auth = $this->actingAs($this->user(), 'sanctum');
        $auth->putJson('/api/v1/admin/settings/performance', [
            'asset_domain' => 'https://cdn.example.ir',
            'cdn_purge_token' => 'SecretToken',
            'lazy_load_enabled' => false,
            'font_preload_enabled' => false,
        ])->assertOk();

        $chrome = $this->getJson('/api/v1/site/chrome')->assertOk();
        $chrome->assertJsonPath('data.asset_domain', 'https://cdn.example.ir')
            ->assertJsonPath('data.lazy_load_enabled', false)
            ->assertJsonPath('data.font_preload_enabled', false);
        $this->assertArrayNotHasKey('cdn_purge_token', $chrome->json('data'));
        $this->assertArrayNotHasKey('cdn_purge_token_encrypted', $chrome->json('data'));
    }

    public function test_routes_require_permission(): void
    {
        $viewer = $this->user(['settings.view']);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/admin/settings/performance')
            ->assertOk();

        $this->actingAs($viewer, 'sanctum')
            ->putJson('/api/v1/admin/settings/performance', ['lazy_load_enabled' => false])
            ->assertForbidden();

        $this->actingAs($this->user([]), 'sanctum')
            ->getJson('/api/v1/admin/settings/performance')
            ->assertForbidden();
    }
}
