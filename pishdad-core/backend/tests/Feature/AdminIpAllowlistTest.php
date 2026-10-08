<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Settings\SecuritySettings;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * WF-M7 — فهرست IPهای مجاز پنل + جلوگیری از قفل‌شدن مدیر هنگام ذخیره.
 *
 * نقطهٔ حساس: تنظیمِ خالی نباید هیچ تست/ورودِ عادی را ببندد، ولی وقتی فهرست
 * پُر است باید نشانی‌های بیرونِ فهرست ۴۰۳ بگیرند. ذخیره‌کردن فهرستی که نشانیِ
 * فعلی را ندارد هم باید خودکار آن را اضافه کند.
 */
class AdminIpAllowlistTest extends TestCase
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
            'name' => 'مدیر تست',
            'email' => 'ip'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
        $user->givePermissionTo(...$perms);

        return $user->fresh();
    }

    public function test_empty_allowlist_does_not_block_admin_requests(): void
    {
        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/v1/admin/settings/site')
            ->assertOk();
    }

    public function test_allowlist_matching_current_ip_allows_request(): void
    {
        SecuritySettings::save(['allowed_admin_ips' => ['127.0.0.1'], 'login_attempt_cap' => 20]);

        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/v1/admin/settings/site')
            ->assertOk();
    }

    public function test_allowlist_excluding_current_ip_returns_403(): void
    {
        SecuritySettings::save(['allowed_admin_ips' => ['10.0.0.1'], 'login_attempt_cap' => 20]);

        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/v1/admin/settings/site')
            ->assertStatus(403)
            ->assertJsonPath('message', 'دسترسی از این نشانی شبکه مجاز نیست.');
    }

    public function test_cidr_range_matches_and_blocks_correctly(): void
    {
        SecuritySettings::save(['allowed_admin_ips' => ['10.0.0.0/8'], 'login_attempt_cap' => 20]);

        // بیرون از محدوده → ۴۰۳.
        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/v1/admin/settings/site')
            ->assertStatus(403);

        // داخل محدوده → مجاز.
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
            ->actingAs($this->user(), 'sanctum')
            ->getJson('/api/v1/admin/settings/site')
            ->assertOk();
    }

    public function test_ipv6_cidr_matches(): void
    {
        SecuritySettings::save(['allowed_admin_ips' => ['2001:db8::/32'], 'login_attempt_cap' => 20]);

        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1::5'])
            ->actingAs($this->user(), 'sanctum')
            ->getJson('/api/v1/admin/settings/site')
            ->assertOk();
    }

    public function test_show_returns_defaults_with_current_ip(): void
    {
        $res = $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/v1/admin/settings/security');

        $res->assertOk()
            ->assertJsonPath('data.allowed_admin_ips', [])
            ->assertJsonPath('data.login_attempt_cap', 20)
            ->assertJsonPath('data.current_ip', '127.0.0.1');
    }

    public function test_saving_allowlist_excluding_current_ip_auto_adds_it_to_avoid_lockout(): void
    {
        $auth = $this->actingAs($this->user(), 'sanctum');

        $res = $auth->putJson('/api/v1/admin/settings/security', [
            'allowed_admin_ips' => ['10.0.0.1'],
            'login_attempt_cap' => 15,
        ]);

        $res->assertOk()
            ->assertJsonPath('data.current_ip_added', true)
            ->assertJsonPath('data.login_attempt_cap', 15);

        // نشانیِ فعلی در فهرستِ ذخیره‌شده هست…
        $this->assertContains('127.0.0.1', $res->json('data.allowed_admin_ips'));

        // …و درخواست بعدی قفل نمی‌شود.
        $auth->getJson('/api/v1/admin/settings/site')->assertOk();
    }

    public function test_saving_allowlist_that_already_contains_current_ip_does_not_mark_added(): void
    {
        $this->actingAs($this->user(), 'sanctum')
            ->putJson('/api/v1/admin/settings/security', [
                'allowed_admin_ips' => ['127.0.0.1'],
                'login_attempt_cap' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('data.current_ip_added', false)
            ->assertJsonPath('data.allowed_admin_ips.0', '127.0.0.1');
    }

    public function test_invalid_ip_or_cidr_is_rejected(): void
    {
        $this->actingAs($this->user(), 'sanctum')
            ->putJson('/api/v1/admin/settings/security', [
                'allowed_admin_ips' => ['not-an-ip', '999.1.1.1', '10.0.0.0/99'],
                'login_attempt_cap' => 20,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['allowed_admin_ips.0', 'allowed_admin_ips.1', 'allowed_admin_ips.2']);
    }

    public function test_login_attempt_cap_out_of_range_is_rejected(): void
    {
        $this->actingAs($this->user(), 'sanctum')
            ->putJson('/api/v1/admin/settings/security', [
                'allowed_admin_ips' => [],
                'login_attempt_cap' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['login_attempt_cap']);
    }

    public function test_clearing_the_allowlist_restores_full_access(): void
    {
        SecuritySettings::save(['allowed_admin_ips' => ['10.0.0.1'], 'login_attempt_cap' => 20]);

        // با فهرستِ پُر و نشانیِ ناهمخوان، ذخیرهٔ فهرستِ خالی باید رد شود چون
        // خودِ درخواست از میدل‌ور رد نمی‌شود. پس مستقیم پاک می‌کنیم و بعد می‌سنجیم.
        Setting::set('security', 'global', ['allowed_admin_ips' => [], 'login_attempt_cap' => 20]);
        \App\Services\Settings\CachedSettings::forget('security', 'global');

        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/v1/admin/settings/site')
            ->assertOk();
    }
}
