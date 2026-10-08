<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * K8.6 — درایور واقعی درگاه زرین‌پال (پشت همان `PaymentGatewayInterface`).
 *
 * ## چرا واقعی و نه stub
 *
 * `ManualBillingDriver` وضعیتِ صادقانهٔ «درگاهی وصل نیست» است. این کلاس همان
 * اینترفیس را با یک درگاهِ **واقعی** پیاده می‌کند: `PaymentRequest` زرین‌پال
 * را صدا می‌زند و در صورت موفقیت `authority` و `pay_url` واقعی برمی‌گرداند.
 * در هر خطا **هیچ لینکی جعل نمی‌شود** — `pay_url` می‌ماند `null` و پیام خطا
 * برگردانده می‌شود تا مصرف‌کننده ادعای جریانی نکند که وجود ندارد.
 *
 * ## فعال‌سازی
 *
 *   PAYMENT_DRIVER=zarinpal
 *   ZARINPAL_MERCHANT_ID=<merchant-id>
 *   ZARINPAL_SANDBOX=true|false
 *
 * اگر `merchant_id` خالی باشد، binding در `AppServiceProvider` به‌جای این کلاس
 * همان `ManualBillingDriver` را می‌دهد (پس مزیتِ «هیچ‌وقت دروغ نگو» حفظ می‌شود).
 *
 * ## واحد پول
 *
 * زرین‌پال مبلغ را **ریال** می‌گیرد. اگر `meta['currency']` برابر `IRT` (تومان)
 * باشد، در همین‌جا ×۱۰ می‌شود. هر واحدِ ناشناخته، بدون تبدیل عبور می‌کند.
 *
 * @see App\Services\Billing\PaymentGatewayInterface
 */
class ZarinpalGatewayDriver implements PaymentGatewayInterface
{
    public function __construct(
        private readonly string $merchantId,
        private readonly bool $sandbox = true,
        private readonly int $timeout = 15,
    ) {}

    public function gatewayName(): string
    {
        return 'zarinpal';
    }

    /**
     * @param  array{currency?: string, mobile?: string, email?: string}  $meta
     * @return array{manual: bool, authority: ?string, pay_url: ?string, callback_url: string, amount: int, description: string, message: string}
     */
    public function requestPayment(int $amount, string $description, string $callbackUrl, array $meta = []): array
    {
        $base = $this->sandbox ? 'https://sandbox.zarinpal.com' : 'https://api.zarinpal.com';

        $unitAmount = $amount;
        if (strtoupper((string) ($meta['currency'] ?? 'IRT')) === 'IRT') {
            $unitAmount = $amount * 10; // تومان → ریال
        }

        $payload = [
            'merchant_id' => $this->merchantId,
            'amount' => $unitAmount,
            'callback_url' => $callbackUrl,
            'description' => $description,
        ];

        $metadata = array_filter([
            'mobile' => $meta['mobile'] ?? null,
            'email' => $meta['email'] ?? null,
        ], fn ($v) => is_string($v) && $v !== '');
        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        try {
            $response = Http::acceptJson()->timeout($this->timeout)
                ->post("{$base}/pg/v4/payment/request.json", $payload);
        } catch (\Throwable $e) {
            Log::warning('billing.zarinpal.request_failed', ['reason' => $e->getMessage()]);

            return $this->failure($callbackUrl, $amount, $description, 'ارتباط با درگاه پرداخت برقرار نشد.');
        }

        $json = $response->json();
        $code = (int) ($json['data']['code'] ?? 0);
        $authority = $json['data']['authority'] ?? null;

        if (! $response->successful() || ! is_string($authority) || $authority === '' || ! in_array($code, [100, 101], true)) {
            $reason = (string) ($json['errors']['message'] ?? $json['data']['message'] ?? 'پاسخ نامعتبر از درگاه.');
            Log::warning('billing.zarinpal.request_rejected', ['code' => $code, 'reason' => $reason]);

            return $this->failure($callbackUrl, $amount, $description, $reason, $code ?: null);
        }

        $payBase = $this->sandbox ? 'https://sandbox.zarinpal.com' : 'https://www.zarinpal.com';

        return [
            'manual' => false,
            'authority' => $authority,
            'pay_url' => "{$payBase}/pg/StartPay/{$authority}",
            'callback_url' => $callbackUrl,
            'amount' => $amount,
            'description' => $description,
            'message' => 'در حال انتقال به درگاه پرداخت زرین‌پال…',
        ];
    }

    /** @return array{manual: bool, authority: null, pay_url: null, callback_url: string, amount: int, description: string, message: string, error: string} */
    private function failure(string $callbackUrl, int $amount, string $description, string $message, ?int $code = null): array
    {
        return [
            'manual' => false,
            'authority' => null,
            'pay_url' => null,
            'callback_url' => $callbackUrl,
            'amount' => $amount,
            'description' => $description,
            'message' => $message,
            'error' => 'gateway.request_failed',
            ...($code ? ['gateway_code' => $code] : []),
        ];
    }
}
