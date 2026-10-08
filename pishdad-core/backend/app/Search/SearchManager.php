<?php

namespace App\Search;

/**
 * جستجوی عمومی سایت — اجرای همه providerهای زنده و ادغام نتایج (I7).
 *
 * ⭐ فهرست providerها دیگر `config('search.providers')` نیست، بلکه
 * `SearchProviderRegistry` است: کانفیگ فقط **پیش‌فرض هسته** است و providerهای
 * افزونه و ثبت‌های زمان اجرا هم به آن اضافه می‌شوند. پس افزودن دامنهٔ جستو
 * (فروشگاه، بلاگ) یعنی ساختن یک پیاده‌سازی `SearchableProvider` و اعلامش —
 * نه ویرایش دستی کانفیگ هسته.
 *
 * هر provider حداکثر $perPage نتیجه می‌دهد؛ خروجی نهایی مرتب
 * updated_at نزولی و محدود به $perPage است.
 */
final class SearchManager
{
    public function __construct(private readonly SearchProviderRegistry $registry) {}

    /**
     * @return array<int, array{title: string, slug: string, snippet: string, type: string, updated_at: ?string}>
     */
    public function search(string $query, int $perPage): array
    {
        $normalized = PersianText::normalize($query);
        // ⭐ I7 — به‌جای `config('search.providers')` مستقیم. رجیستری خودش کانفیگ
        // هسته را به‌عنوان پیش‌فرض می‌خواند و providerهای افزونه و ثبت‌های
        // زمان اجرا را هم اضافه می‌کند؛ پس افزونه دیگر برای افزودن دامنهٔ
        // جستوی خودش مجبور نیست فایل کانفیگ هسته را دستی عوض کند.
        $merged = [];

        foreach ($this->registry->providers(SearchProviderRegistry::CHANNEL_SITE) as $provider) {
            try {
                foreach ($provider->search($query, $normalized, $perPage) as $row) {
                    $merged[] = $row;
                }
            } catch (\Throwable) {
                // provider خراب جستجو را نمی‌شکند (fail-soft)؛ لاگش با خودِ رجیستری
                // است چون ساخت provider آنجا انجام می‌شود.
                continue;
            }
        }

        usort($merged, fn (array $a, array $b) => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));

        return array_values(array_slice($merged, 0, $perPage));
    }
}
