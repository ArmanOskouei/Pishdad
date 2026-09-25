<?php

namespace App\Services\Billing;

use Illuminate\Support\Str;

/**
 * درایور Stub زرین‌پال (sandbox) — تسک ۱.۲/۱.۳.
 * هیچ شارژ واقعی انجام نمی‌دهد؛ فقط session پرداخت می‌سازد تا جریان
 * «فاکتور → درگاه → وب‌هوک → paid → رفع تعلیق» end-to-end تست شود.
 * جایگزینی با درگاه واقعی = پیاده‌سازی PaymentGatewayInterface + یک خط در provider.
 */
class ZarinpalStubDriver implements PaymentGatewayInterface
{
    public function gatewayName(): string
    {
        return 'zarinpal-stub';
    }

    public function requestPayment(int $amount, string $description, string $callbackUrl, array $meta = []): array
    {
        $authority = 'STUB-'.Str::upper(Str::random(16));

        return [
            'authority' => $authority,
            // آدرس سندباکس زرین‌پال (نمایشی — در Stub فراخوانی نمی‌شود).
            'pay_url' => 'https://sandbox.zarinpal.com/pg/StartPay/'.$authority,
            'callback_url' => $callbackUrl,
            'amount' => $amount,
            'description' => $description,
        ];
    }
}
