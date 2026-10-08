<?php

namespace Pishdad\Plugins\TestDouble;

use App\Search\SearchableProvider;

/**
 * provider جستجوی آزمایشی برای تست `AdminSearch` (B5).
 *
 * عمداً در ریشهٔ `Pishdad\Plugins\` است، چون `ServiceProviderRegistry` هر کلاس
 * بیرون از این ریشه را رد می‌کند — وگرنه تست دقیقاً همان چیزی را می‌سنجید که
 * رجیستری رد می‌کند.
 *
 * هرگز در مسیر اجرایی استفاده نمی‌شود.
 */
class StubPageProvider implements SearchableProvider
{
    public function key(): string
    {
        return 'stub';
    }

    public function search(string $query, string $normalizedQuery, int $limit): array
    {
        return [[
            'title' => 'نوشتهٔ آزمایشی',
            'slug' => 'stub-post',
            'snippet' => 'نتیجهٔ provider آزمایشی',
            'type' => 'stub',
            'updated_at' => '2026-01-01T00:00:00+00:00',
            'path' => '/admin/blog',
        ]];
    }
}
