<?php

namespace Pishdad\Plugins\TestDouble;

use App\Services\Billing\PaymentGatewayInterface;

/**
 * درایور آزمایشی برای تست `core.service_provider`.
 *
 * عمداً در ریشهٔ `Pishdad\Plugins\` زندگی می‌کند، چون `ServiceProviderRegistry`
 * هر کلاسی خارج از این ریشه را رد می‌کند. برای همین این فایل در `tests/`
 * است، نه `app/` — هنوز هیچ autoload واقعی برای `Pishdad\Plugins\` وجود ندارد
 * چون K5.3 نیامده، ولی namespace باید درست باشد تا تست همان چیزی را بسنجد
 * که افزونهٔ واقعی می‌آورد.
 *
 * کلاس‌های این فایل هرگز در مسیر اجرایی استفاده نمی‌شوند.
 */
class StubGatewayForTest implements PaymentGatewayInterface
{
    public function requestPayment(int $amount, string $description, string $callbackUrl, array $meta = []): array
    {
        return [
            'authority' => 'TEST',
            'pay_url' => 'https://example.invalid/pay',
        ];
    }

    public function gatewayName(): string
    {
        return 'stub-for-test';
    }
}
