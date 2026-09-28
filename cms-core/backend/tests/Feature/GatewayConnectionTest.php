<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** دسته ۱ (برابری UI/UX) — دکمه «تست اتصال» درگاه (Stub نمایشی، بدون تراکنش). */
class GatewayConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_gateway_test_returns_stub_success(): void
    {
        $op = User::query()->create([
            'name' => 'اپراتور', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'operator',
        ]);

        $res = $this->actingAs($op, 'sanctum')->postJson('/api/v1/p/central/gateway-settings/test');

        $res->assertOk()->assertJsonPath('data.ok', true);
        $this->assertNotEmpty($res->json('data.driver'));
        $this->assertStringContainsString('بدون تراکنش واقعی', $res->json('message'));
    }
}
