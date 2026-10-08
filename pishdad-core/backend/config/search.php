<?php

use App\Search\PageSearchProvider;

/*
| رجیستری providerهای جستجوی عمومی سایت (معماری پلاگین‌محور).
| هر provider یک کلاس پیاده‌ساز App\Search\SearchableProvider است که
| در یک دامنه (صفحات، محصولات فروشگاه آینده، نوشته‌های بلاگ آینده) جستجو
| می‌کند. SearchManager همه providerهای همین لیست را اجرا و نتایج را
| ادغام می‌کند (مرتب انتشار/به‌روزرسانی نزولی، محدود به per_page).
|
| افزودن دامنه جدید (مثلاً پلاگین فروشگاه):
|   ۱. کلاس `App\Search\ShopProductProvider implements SearchableProvider`
|      بساز (الگو: App\Search\PageSearchProvider).
|   ۲. همین‌جا ثبت کن: providers[] = ShopProductProvider::class.
|   ۳. تست Feature: q فارسی، snippet، عدم درز draft.
| قرارداد آینده: پلاگین‌ها می‌توانند از همین الگو با کلید
| `search_providers` مانیفست provider اعلام کنند (فعلاً فقط همین کانفیگ).
*/
return [
    'providers' => [
        PageSearchProvider::class,
    ],

    /*
     | providerهای **جستجوی پنل** — نه جستجوی عمومی سایت (B5).
     |
     | افزونه‌ها اینجا ثبت نمی‌شوند: از راه `core.service_provider` یک
     | پیاده‌سازی `SearchableProvider` اعلام می‌کنند و `AdminSearchController`
     | رجیستری را می‌خواند. این فهرست فقط برای provider داخلیِ خود هسته است.
     |
     | تفاوت با `providers`: جستجوی عمومی برای بازدیدکننده و بدون احراز
     | هویت است؛ جستجوی پنل احراز هویت و پرمیشن می‌خواهد.
     */
    'admin_providers' => [
    ],
];
