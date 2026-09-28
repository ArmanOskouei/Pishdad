<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Pishdad\Plugins\Central\Models\ChabokanDeployment;
use Pishdad\Plugins\Central\Models\ChabokanLog;
use Tests\TestCase;

/** تسک ۳.۳ — DoD: کیف پول/سرویس‌ها (Stub) + اکشن با confirm + لاگ audit + دیپلوی + reconciliation. */
class ChabokanTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::query()->create([
            'name' => 'اپراتور', 'email' => 'op'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'operator',
        ]);
    }

    public function test_wallet_and_services_come_from_stub_driver(): void
    {
        $op = $this->operator();
        $auth = $this->actingAs($op, 'sanctum');

        $auth->getJson('/api/v1/p/central/chabokan/wallet')
            ->assertOk()
            ->assertJsonPath('data.driver', 'stub')
            ->assertJsonPath('data.currency', 'IRT');
        $this->assertIsInt($auth->getJson('/api/v1/p/central/chabokan/wallet')->json('data.balance'));

        $services = $auth->getJson('/api/v1/p/central/chabokan/services')->assertOk()->json('data');
        $this->assertNotEmpty($services);
        $this->assertContains('site-app', collect($services)->pluck('name')->all());
    }

    public function test_start_needs_no_confirm_but_is_audited(): void
    {
        $op = $this->operator();

        $this->actingAs($op, 'sanctum')
            ->postJson('/api/v1/p/central/chabokan/services/site-app/start')
            ->assertOk()
            ->assertJsonPath('message', 'سرویس روشن شد.');

        $this->assertSame(1, ChabokanLog::query()->where('action', 'start')->where('service', 'site-app')->count());
    }

    public function test_destructive_action_requires_confirm(): void
    {
        $op = $this->operator();
        $auth = $this->actingAs($op, 'sanctum');

        $auth->postJson('/api/v1/p/central/chabokan/services/site-app/stop', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.confirm.0', 'برای این عملیات، تأییدیه (confirm:true) الزامی است.');

        $auth->postJson('/api/v1/p/central/chabokan/services/site-app/stop', ['confirm' => true])
            ->assertOk()
            ->assertJsonPath('message', 'سرویس خاموش شد.');

        $this->assertSame(1, ChabokanLog::query()->where('action', 'stop')->count());
    }

    public function test_resize_requires_size_and_confirm(): void
    {
        $op = $this->operator();
        $auth = $this->actingAs($op, 'sanctum');

        $auth->postJson('/api/v1/p/central/chabokan/services/site-app/resize', ['confirm' => true])
            ->assertStatus(422);

        $auth->postJson('/api/v1/p/central/chabokan/services/site-app/resize', ['confirm' => true, 'size' => 'medium'])
            ->assertOk()
            ->assertJsonPath('message', 'سایز سرویس تغییر کرد.');
    }

    public function test_unknown_service_is_rejected(): void
    {
        $op = $this->operator();

        $this->actingAs($op, 'sanctum')
            ->postJson('/api/v1/p/central/chabokan/services/nope/start')
            ->assertStatus(422)
            ->assertJsonPath('message', 'نام سرویس مجاز نیست.');
    }

    public function test_redeploy_creates_record_and_audit(): void
    {
        $op = $this->operator();
        $auth = $this->actingAs($op, 'sanctum');

        $auth->postJson('/api/v1/p/central/chabokan/redeploy', ['service' => 'site-app'])
            ->assertStatus(422);

        $auth->postJson('/api/v1/p/central/chabokan/redeploy', ['service' => 'site-app', 'confirm' => true])
            ->assertCreated()
            ->assertJsonPath('message', 'دیپلوی مجدد ثبت شد.');

        $this->assertSame(1, ChabokanDeployment::query()->where('service', 'site-app')->count());
        $this->assertSame(1, ChabokanLog::query()->where('action', 'redeploy')->count());

        $auth->getJson('/api/v1/p/central/chabokan/deployments')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_reconciliation_lists_mismatches(): void
    {
        $op = $this->operator();
        $auth = $this->actingAs($op, 'sanctum');

        $before = $auth->getJson('/api/v1/p/central/chabokan/reconciliation')->assertOk()->json('data');
        $this->assertContains('site-app', $before['missing_in_db']);

        $auth->postJson('/api/v1/p/central/chabokan/redeploy', ['service' => 'site-app', 'confirm' => true])
            ->assertCreated();

        $after = $auth->getJson('/api/v1/p/central/chabokan/reconciliation')->assertOk()->json('data');
        $this->assertNotContains('site-app', $after['missing_in_db']);
        $this->assertSame([], $after['missing_in_chabokan']);
    }

    public function test_non_operator_is_forbidden(): void
    {
        $admin = User::query()->create([
            'name' => 'مشتری', 'email' => 'c'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'admin',
        ]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/p/central/chabokan/wallet')
            ->assertForbidden();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/p/central/chabokan/services/site-app/start')
            ->assertForbidden();
    }
}
