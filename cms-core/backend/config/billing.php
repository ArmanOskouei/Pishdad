<?php

/*
| تنظیمات ماژول Billing مرکزی (تسک‌های ۱.۲ / ۱.۳).
| درگاه واقعی فقط در پنل مرکزی نصب می‌شود؛ در MVP درایور Stub زرین‌پال
| (sandbox) فعال است و هیچ شارژ واقعی انجام نمی‌دهد.
*/
return [
    // ترتیب مجاز ارتقای تیر (فقط رو به جلو، یک پله).
    'tiers' => ['budget', 'standard', 'enterprise'],

    // راز مشترک HMAC وب‌هوک پرداخت (هدر X-Signature روی بدنه خام).
    'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET', 'change-me-payment-webhook'),

    'gateway' => [
        'driver' => env('PAYMENT_DRIVER', 'zarinpal-stub'),
        'merchant_id' => env('ZARINPAL_MERCHANT_ID', 'stub-merchant'),
        'sandbox' => env('ZARINPAL_SANDBOX', true),
        'callback_url' => env('PAYMENT_CALLBACK_URL', 'http://localhost:8080/api/webhooks/payment'),
    ],

    // مهلت‌ها (روز) — طیف PLAN.md: یادآوری D-7..D-0، تعلیق D-0، حذف D+7.
    'reminder_window_days' => 7,
    'purge_after_suspended_days' => 7,

    // TTL توکن یکبارمصرف سوپرادمین (دقیقه).
    'support_token_ttl_minutes' => 15,
];
