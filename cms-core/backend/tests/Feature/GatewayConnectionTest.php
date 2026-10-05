<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * دکمه «تست اتصال» درگاه.
 *
 * J10 — این تست عمداً عوض شد، ولی **نیتش عوض نشد**: نیتش این است که دکمه هرگز
 * دروغ نگوید. نسخهٔ قبلی `ok: true` را برای درگاهی برمی‌گرداند که وجود نداشت
 * (Stub). حالا برای درایورِ `manual` باید صادقانه بگوید «اتصالی نیست».
 *
 * و هر دو حالت سنجیده می‌شود، چون یکی‌شان کافی نیست: اگر فقط حالتِ `manual`
 * تست می‌شد، یک تغییرِ بعدی می‌توانست دوباره `ok: true` ساختگی برگرداند و سبز
 * بماند.
 */
class GatewayConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_gateway_test_admits_there_is_no_gateway_when_billing_is_manual(): void
    {
        $op = $this->operator();

        $res = $this->actingAs($op, 'sanctum')->postJson('/api/v1/p/central/gateway-settings/test');

        $res->assertOk()
            ->assertJsonPath('data.driver', 'manual')
            ->assertJsonPath('data.ok', false);

        $this->assertStringContainsString(
            'دستی',
            $res->json('message'),
            'پیام باید صریح بگوید صورت‌حساب دستی است — نه اینکه موفقیت نمایشی جا بزند.',
        );
    }

    public function test_gateway_test_stays_explicitly_demo_for_a_configured_driver(): void
    {
        $op = $this->operator();
        $auth = $this->actingAs($op, 'sanctum');

        $auth->putJson('/api/v1/p/central/gateway-settings', ['driver' => 'zarinpal'])->assertOk();

        $res = $auth->postJson('/api/v1/p/central/gateway-settings/test');

        $res->assertOk()->assertJsonPath('data.driver', 'zarinpal');

        // حتی برای درایورِ پیکربندی‌شده هم باید گفتاری باشد: این تست تراکنشِ
        // واقعی نمی‌زند، پس ادعای «اتصال برقرار است» بدون این توضیح دروغ است.
        $this->assertStringContainsString('بدون تراکنش واقعی', $res->json('message'));
    }

    private function operator(): User
    {
        return User::query()->create([
            'name' => 'اپراتور', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'operator',
        ]);
    }
}
