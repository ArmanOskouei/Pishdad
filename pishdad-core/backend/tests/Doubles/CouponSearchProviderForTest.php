<?php

namespace Pishdad\Plugins\TestDouble;

use App\Search\SearchableProvider;

/**
 * provider جستوی آزمایشی شمارهٔ ۲ — برای اثباتِ **افزایشی بودن** رجیستری (I7).
 *
 * وجودِ دو کلاسِ جدا عمدی است: با یک کلاس، «دو اعلان» و «یک اعلانِ دوبار
 * ثبت‌شده» از هم قابل تشخیص نیستند و تستِ افزایشی‌بودن عملاً چیزی نمی‌سنجد.
 */
class CouponSearchProviderForTest implements SearchableProvider
{
    public function key(): string
    {
        return 'coupon';
    }

    public function search(string $query, string $normalizedQuery, int $limit): array
    {
        return [[
            'title' => 'کد تخفیف آزمایشی', 'slug' => 'probe-2', 'snippet' => '',
            'type' => 'coupon', 'updated_at' => null,
        ]];
    }
}