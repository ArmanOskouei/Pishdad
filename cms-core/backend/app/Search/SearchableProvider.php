<?php

namespace App\Search;

/**
 * قرارداد provider جستجوی عمومی سایت (معماری پلاگین‌محور).
 *
 * هر دامنه قابل‌جستجو (صفحات هسته، محصولات فروشگاه آینده، نوشته‌های بلاگ
 * آینده) یک پیاده‌سازی از همین اینترفیس است و در `config/search.php`
 * ثبت می‌شود. SearchManager همه را اجرا و نتایج را ادغام می‌کند.
 *
 * شکل هر نتیجه:
 *   ['title' => string, 'slug' => string, 'snippet' => string,
 *    'type' => 'page'|..., 'updated_at' => ?string (ISO8601)]
 *
 * قوانین مشترک همه providerها:
 * - فقط محتوای منتشرشده/عمومی (draft هرگز درز نمی‌کند).
 * - تطابق فارسی‌دوست: ورودی‌ها از قبل با PersianText::normalize نرمال
 *   شده‌اند (ي/ك عربی → ی/ک + نیم‌فاصله → فاصله)؛ provider باید هم
 *   کوئری و هم متن را نرمال مقایسه کند.
 * - snippet هوشمند ~۱۶۰ نویسه با PersianText::snippet.
 */
interface SearchableProvider
{
    /** کلید دامنه (مثل 'page') — در فیلد type خروجی می‌آید. */
    public function key(): string;

    /**
     * @param  string  $query  کوئری خام کاربر (trim شده)
     * @param  string  $normalizedQuery  کوئری نرمال‌شده فارسی‌دوست
     * @param  int  $limit  سقف نتایج این provider
     * @return array<int, array{title: string, slug: string, snippet: string, type: string, updated_at: ?string}>
     */
    public function search(string $query, string $normalizedQuery, int $limit): array;
}
