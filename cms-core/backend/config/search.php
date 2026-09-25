<?php

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
        App\Search\PageSearchProvider::class,
    ],
];
