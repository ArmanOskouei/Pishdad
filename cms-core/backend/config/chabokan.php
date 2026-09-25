<?php

/*
| تنظیمات اتصال چابکان (تسک ۳.۳ — اسکلت + واقعیِ بی‌خطر).
| در MVP درایور `stub` فعال است: موجودی و سرویس‌ها از همین کانفیگ خوانده
| می‌شوند و هیچ تماس واقعی/شارژی انجام نمی‌شود.
|
| اتصال واقعی (TODO مرحله ۵): پیاده‌سازی ChabokanClientInterface با کلاینت
| HTTP چابکان (توکن از CHABOKAN_API_TOKEN) و تعویض driver به `api` —
| بدون تغییر کنترلرها (فقط binding در AppServiceProvider).
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

    // اتصال واقعی (غیرفعال تا مرحله ۵).
    'api_base' => env('CHABOKAN_API_BASE', 'https://api.chabakan.example/v1'),
    'api_token' => env('CHABOKAN_API_TOKEN'),
];
