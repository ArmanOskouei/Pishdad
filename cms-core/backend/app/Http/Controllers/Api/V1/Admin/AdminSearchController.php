<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Search\PersianText;
use App\Search\SearchProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * جستجوی پنل — سمت سرور (B5).
 *
 * تا پیش از این، افزونه‌ها با `registerSearchSource({fetchItems})` روی
 * `window.__ADMIN_SEARCH__` ثبت می‌شدند و `fetchItems` از مرورگر به
 * `/api/proxy/v1/admin/...` می‌زد. این یعنی افزونه می‌توانست **هر مسیری
 * از API هسته را صدا بزند** — دقیقاً همان چیزی که K1.5.8 می‌خواست جلویش
 * گرفته شود. ضمناً هر نتیجه یک fetch جدا از مرورگر بود.
 *
 * حالا افزونه فقط **یک کلاس** اعلام می‌کند (`core.service_provider` →
 * `SearchableProvider`) و جستجو اینجا و در سرور اتفاق می‌افتد.
 *
 * ⭐ I7 — providerها از `SearchProviderRegistry` می‌آیند، نه از کانفیگ ثابت و نه
 * از یک override تک‌خانه. برای جستو، اعلان افزونه **افزایشی** است: دو افزونه
 * هر دو در نتایج‌اند (به `ServiceProviderRegistry::declaredSearchProviders()`
 * نگاه کنید) — وگرنه افزونهٔ دوم بی‌سروصدا حذف می‌شد.
 *
 * تفکیک با `SearchController` مهم است: آن یکی جستجوی **عمومی سایت** است
 * (بازدیدکننده، بدون احراز هویت، فقط محتوای منتشرشده). این یکی جستجوی
 * **داخل پنل** است و احراز هویت و پرمیشن می‌خواهد.
 */
class AdminSearchController
{
    public function __invoke(Request $request, SearchProviderRegistry $registry): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:20'],
        ], [
            'q.required' => 'عبارت جستجو الزامی است.',
            'q.min' => 'عبارت جستجو حداقل ۲ نویسه است.',
            'q.max' => 'عبارت جستجو حداکثر ۱۲۰ نویسه است.',
            'per_page.max' => 'حداکثر ۲۰ نتیجه در هر بار.',
        ]);

        $perPage = (int) ($data['per_page'] ?? 8);
        $query = $data['q'];

        $rows = [];
        foreach ($registry->providers(SearchProviderRegistry::CHANNEL_ADMIN) as $provider) {
            // یک provider خراب نباید کل جستجو را از کار بیندازد — ولی باید
            // دیده شود، وگرنه افزونه‌نویس تا ابد دنبالش می‌گردد. لاگش در خودِ
            // `SearchProviderRegistry::make()` است.
            try {
                $rows = array_merge($rows, $provider->search($query, PersianText::normalize($query), $perPage));
            } catch (\Throwable $e) {
                report($e);

                continue;
            }
        }

        return response()->json(['data' => array_slice($rows, 0, $perPage)]);
    }
}
