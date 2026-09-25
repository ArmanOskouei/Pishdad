<?php

namespace App\Search;

/**
 * اجرای همه providerهای `config/search.php` و ادغام نتایج.
 *
 * افزودن دامنه جدید برای پلاگین آینده (فروشگاه/بلاگ):
 *   providers[] = ShopProductProvider::class (پیاده‌ساز SearchableProvider)
 * هر provider حداکثر $perPage نتیجه می‌دهد؛ خروجی نهایی مرتب
 * updated_at نزولی و محدود به $perPage است.
 */
final class SearchManager
{
    /**
     * @return array<int, array{title: string, slug: string, snippet: string, type: string, updated_at: ?string}>
     */
    public function search(string $query, int $perPage): array
    {
        $normalized = PersianText::normalize($query);
        $providers = (array) config('search.providers', []);
        $merged = [];

        foreach ($providers as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }
            try {
                $provider = app($class);
            } catch (\Throwable) {
                continue;
            }
            if (! $provider instanceof SearchableProvider) {
                continue;
            }
            try {
                foreach ($provider->search($query, $normalized, $perPage) as $row) {
                    $merged[] = $row;
                }
            } catch (\Throwable) {
                // provider خراب جستجو را نمی‌شکند (fail-soft).
                continue;
            }
        }

        usort($merged, fn (array $a, array $b) => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));

        return array_values(array_slice($merged, 0, $perPage));
    }
}
