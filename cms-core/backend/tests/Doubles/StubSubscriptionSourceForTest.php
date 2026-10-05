<?php

namespace Pishdad\Plugins\TestDouble;

use App\Services\Subscription\SubscriptionSource;

/**
 * منبعِ آزمایشیِ وضعیتِ اشتراک، برای تستِ سرتاسریِ `core.service_provider`
 * (K7.2/K7.3).
 *
 * عمداً در `Pishdad\Plugins\TestDouble\` زندگی می‌کند: `ServiceProviderRegistry`
 * هر کلاسی خارج از ریشهٔ `Pishdad\Plugins\` را رد می‌کند، پس کلاسِ آزمایشی باید
 * دقیقاً همان جایی باشد که یک بستهٔ واقعی کلاسش را می‌گذارد.
 *
 * تنها قراردادِ `SubscriptionSource` دو چیز است — وضعیت و پیام — و همین دو
 * چیز را برمی‌گرداند تا تست بتواند فرقِ «منبعِ افزونه» و «پیش‌فرضِ همیشه
 * فعال» را ببیند.
 */
class StubSubscriptionSourceForTest implements SubscriptionSource
{
    /**
     * J5 — این stub فقط قرارداد را پر می‌کند تا تست‌های binding کار کنند؛
     * `null` یعنی «کارت ندارد» که همان چیزی است که این تست‌ها انتظار دارند.
     */
    public function forUser(?int $ownerUserId): ?array
    {
        return null;
    }

    public function statusForSite(?string $siteId = null): ?array
    {
        return $this->status();
    }

    public function status(?int $ownerUserId = null): ?array
    {
        return [
            'status' => 'suspended',
            'message' => 'وضعیت از افزونه آمد (تست).',
        ];
    }
}
