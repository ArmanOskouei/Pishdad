<?php

namespace Tests\Feature;

use App\Services\Billing\ManualBillingDriver;
use App\Services\Billing\PaymentGatewayInterface;
use App\Services\Billing\ZarinpalGatewayDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * K8.6 — درایور واقعی درگاه: binding مشروط + رفتار زرین‌پال.
 *
 * اصلِ حاکم: در نبودِ پیکربندی یا در خطای درگاه، **هیچ لینکی جعل نمی‌شود**.
 */
class ZarinpalGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function gateway(): PaymentGatewayInterface
    {
        $this->app->forgetInstance(PaymentGatewayInterface::class);

        return $this->app->make(PaymentGatewayInterface::class);
    }

    public function test_default_binding_is_manual(): void
    {
        config(['billing.gateway.driver' => 'manual']);
        $this->assertInstanceOf(ManualBillingDriver::class, $this->gateway());
    }

    public function test_zarinpal_without_merchant_falls_back_to_manual(): void
    {
        config([
            'billing.gateway.driver' => 'zarinpal',
            'billing.gateway.merchant_id' => '',
        ]);
        // بدون merchant_id، درگاه واقعی ساخته نمی‌شود ⇒ همان «دستی» صادقانه.
        $this->assertInstanceOf(ManualBillingDriver::class, $this->gateway());
    }

    public function test_zarinpal_success_returns_real_authority_and_pay_url(): void
    {
        config([
            'billing.gateway.driver' => 'zarinpal',
            'billing.gateway.merchant_id' => 'MID-123',
            'billing.gateway.sandbox' => true,
        ]);
        Http::fake([
            'sandbox.zarinpal.com/pg/v4/payment/request.json' => Http::response([
                'data' => ['code' => 100, 'authority' => 'A00000000000000000000000000000123456'],
                'errors' => [],
            ], 200),
        ]);

        $driver = $this->gateway();
        $this->assertInstanceOf(ZarinpalGatewayDriver::class, $driver);
        $this->assertSame('zarinpal', $driver->gatewayName());

        $result = $driver->requestPayment(100000, 'خرید پلاگین', 'https://cms.example.com/pay/cb', ['currency' => 'IRT']);

        $this->assertFalse($result['manual']);
        $this->assertSame('A00000000000000000000000000000123456', $result['authority']);
        $this->assertSame('https://sandbox.zarinpal.com/pg/StartPay/A00000000000000000000000000000123456', $result['pay_url']);

        // تومان → ریال: مبلغ ارسالی باید ×۱۰ باشد.
        Http::assertSent(fn ($req) => str_contains($req->url(), 'payment/request.json')
            && $req['amount'] === 1000000
            && $req['merchant_id'] === 'MID-123');
    }

    public function test_zarinpal_error_never_fabricates_a_link(): void
    {
        config([
            'billing.gateway.driver' => 'zarinpal',
            'billing.gateway.merchant_id' => 'MID-123',
            'billing.gateway.sandbox' => true,
        ]);
        Http::fake([
            'sandbox.zarinpal.com/*' => Http::response([
                'data' => ['code' => -9, 'message' => 'Validation error'],
                'errors' => ['message' => 'Validation error'],
            ], 200),
        ]);

        $result = $this->gateway()->requestPayment(5000, 'تست', 'https://cms.example.com/pay/cb');

        $this->assertNull($result['authority']);
        $this->assertNull($result['pay_url']);
        $this->assertSame('gateway.request_failed', $result['error']);
    }
}
