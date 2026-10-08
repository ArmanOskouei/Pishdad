<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** WF-H1 — robots.txt قابل ویرایش + کد تأیید مالکیت Google Search Console. */
class RobotsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مشتری تست',
            'email' => 'robots'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
    }

    public function test_robots_and_gsc_fields_roundtrip(): void
    {
        $auth = $this->actingAs($this->user(), 'sanctum');

        $auth->putJson('/api/v1/admin/settings/site', [
            'title' => 'سایت سئو',
            'robots_txt' => "User-agent: *\nDisallow: /admin",
            'google_site_verification' => 'gsc-verify-token-123',
        ])->assertOk()->assertJsonPath('message', 'تنظیمات سایت ذخیره شد.');

        $auth->getJson('/api/v1/admin/settings/site')
            ->assertOk()
            ->assertJsonPath('data.robots_txt', "User-agent: *\nDisallow: /admin")
            ->assertJsonPath('data.google_site_verification', 'gsc-verify-token-123');
    }

    public function test_robots_and_gsc_are_null_by_default(): void
    {
        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/v1/admin/settings/site')
            ->assertOk()
            ->assertJsonPath('data.robots_txt', null)
            ->assertJsonPath('data.google_site_verification', null);
    }

    public function test_robots_and_gsc_reject_markup(): void
    {
        $res = $this->actingAs($this->user(), 'sanctum')->putJson('/api/v1/admin/settings/site', [
            'title' => 'تست امنیت',
            'robots_txt' => "User-agent: *\nDisallow: /<script>",
            'google_site_verification' => '<script>alert(1)</script>',
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['robots_txt', 'google_site_verification']);
    }

    public function test_robots_and_gsc_are_exposed_on_public_chrome(): void
    {
        $auth = $this->actingAs($this->user(), 'sanctum');
        $auth->putJson('/api/v1/admin/settings/site', [
            'title' => 'سایت عمومی',
            'robots_txt' => "User-agent: *\nAllow: /",
            'google_site_verification' => 'public-verify-token',
        ])->assertOk();

        $this->getJson('/api/v1/site/chrome')
            ->assertOk()
            ->assertJsonPath('data.robots_txt', "User-agent: *\nAllow: /")
            ->assertJsonPath('data.google_site_verification', 'public-verify-token');
    }
}
