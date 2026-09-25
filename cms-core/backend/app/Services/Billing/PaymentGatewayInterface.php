<?php

namespace App\Services\Billing;

/** قرارداد درگاه پرداخت مرکزی — پیاده‌سازی واقعی/سندباکس پشت همین اینترفیس. */
interface PaymentGatewayInterface
{
    /** شروع پرداخت؛ برمی‌گرداند: authority + pay_url (انتقال کاربر به درگاه). */
    public function requestPayment(int $amount, string $description, string $callbackUrl, array $meta = []): array;

    public function gatewayName(): string;
}
