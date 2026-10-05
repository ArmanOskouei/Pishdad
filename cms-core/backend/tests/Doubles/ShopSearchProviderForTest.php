<?php

namespace Pishdad\Plugins\TestDouble;

use App\Search\SearchableProvider;

/**
 * provider جستوی آزمایشی شمارهٔ ۱ — برای تستِ I7 (رجیستری پویای جستو).
 *
 * عمداً زیر `Pishdad\Plugins\` زندگی می‌کند: `ServiceProviderRegistry::judge()`
 * هر کلاسی بیرون از این ریشه را رد می‌کند، پس یک کلاسِ آزمایشیِ داخل
 * `Tests\` هرگز از فیلتر رجیستری رد نمی‌شد و تست درستی‌اش را از دست می‌داد.
 */
class ShopSearchProviderForTest implements SearchableProvider
{
    public function key(): string
    {
        return 'shop';
    }

    public function search(string $query, string $normalizedQuery, int $limit): array
    {
        return [[
            'title' => 'محصول آزمایشی', 'slug' => 'probe-1', 'snippet' => '',
            'type' => 'shop', 'updated_at' => null,
        ]];
    }
}