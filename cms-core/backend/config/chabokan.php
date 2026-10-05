<?php

/*
| تنظیمات اتصال چابکان (تسک ۳.۳ — اسکلت + واقعیِ بی‌خطر).
| در MVP درایور `stub` فعال است: موجودی و سرویس‌ها از همین کانفیگ خوانده
| می‌شوند و هیچ تماس واقعی/شارژی انجام نمی‌شود.
|
| اتصال واقعی (E4): `ApiChabokanDriver` پیاده شده و `CHABOKAN_DRIVER=api`
| با کلاینت HTTP چابکان کار می‌کند. ولی **بدون کلید، fail-closed است**: اگر
| `CHABOKAN_DRIVER=api` باشد و `CHABOKAN_API_TOKEN`/`CHABOKAN_API_BASE`
| خالی یا placeholder (`.example`) باشند، `ChabokanDriverFactory` پیامِ فارسی
| با نامِ دقیقِ کلیدِ جاافتاده می‌دهد و اصلاً ساخته نمی‌شود.
|
| ⭐ `api_base` دیگر مقدارِ پیش‌فرض ندارد. پیش‌فرضِ قبلی یک آدرسِ `*.example`
| بود که فقط شبیه تنظیم بود — DNS به جای ناموجود می‌رفت و خطای گمراه‌کننده
| می‌داد. خالی بودن یعنی «هنوز آدرس نداده‌ای»، که صادقانه است.
*/
return [
    'driver' => env('CHABOKAN_DRIVER', 'stub'),

    // موجودی نمایشی کیف پول (تومان) — فقط درایور stub.
    'wallet_balance' => (int) env('CHABOKAN_WALLET_BALANCE', 2500000),
    'currency' => env('CHABOKAN_CURRENCY', 'IRT'),
    'low_balance_threshold' => (int) env('CHABOKAN_LOW_BALANCE_THRESHOLD', 500000),

    // نام‌های مجاز سرویس (اعتبارسنجی name در اکشن‌ها + reconciliation).
    'allowed_services' => ['site-app', 'site-worker', 'postgres', 'redis'],

    // وضعیت‌های نمایشی درایور stub.
    'stub_statuses' => [
        'site-app' => 'running',
        'site-worker' => 'running',
        'postgres' => 'running',
        'redis' => 'running',
    ],

    // اتصال واقعی (E4) — تا وقتی این‌ها خالی‌اند `driver=api` خطا می‌دهد.
    'api_base' => env('CHABOKAN_API_BASE'),
    'api_token' => env('CHABOKAN_API_TOKEN'),
];
