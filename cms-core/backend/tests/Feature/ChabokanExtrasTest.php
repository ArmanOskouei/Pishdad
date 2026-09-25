<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** دسته UIUX — چابکان: شارژ کیف پول + ساخت سرویس آماده (افزودنی). */
class ChabokanExtrasTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::query()->create([
            'name' => 'اپراتور', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Operator!1'), 'role' => 'operator',
        ]);
    }

    public function test_wallet_charge_validates_and_audits(): void
    {
        $auth = $this->actingAs($this->operator(), 'sanctum');

        $auth->postJson('/api/central/chabokan/wallet/charge', ['amount' => 100])
            ->assertStatus(422);

        $auth->postJson('/api/central/chabokan/wallet/charge', ['amount' => 500000])
            ->assertCreated()
            ->assertJsonPath('data.amount', 500000);

        $this->assertDatabaseHas('central_chabokan_logs', [
            'action' => 'wallet_charge', 'service' => 'wallet',
        ]);
    }

    public function test_provision_creates_queued_deployment(): void
    {
        $auth = $this->actingAs($this->operator(), 'sanctum');

        $auth->postJson('/api/central/chabokan/services/provision', ['name' => 'BAD NAME!'])
            ->assertStatus(422);

        $auth->postJson('/api/central/chabokan/services/provision', [
            'name' => 'ready-pool-1', 'size' => 'small',
        ])->assertCreated()->assertJsonPath('message', 'ساخت سرویس آماده ثبت شد.');

        $this->assertDatabaseHas('central_chabokan_deployments', [
            'service' => 'ready-pool-1', 'status' => 'queued',
        ]);
    }
}
