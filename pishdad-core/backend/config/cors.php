<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | این پروژه بدون این فایل، پیش‌فرضِ لاراول را داشت: `allowed_origins: ['*']`
    | یعنی هر سایتی روی اینترنت می‌توانست پاسخِ API را از مرورگرِ کاربر بخواند.
    |
    | معماریِ معمول: مرورگر → فرانت (Next، same-origin) → API. پس در عمل هیچ
    | درخواستِ cross-origin مرورگری لازم نیست. حالا فهرست از محیط می‌آید تا
    | نصب‌هایی که واقعاً فرانت را روی دامنهٔ دیگری دارند، آن را اعلام کنند.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3100,http://localhost:3000'))),
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With', 'X-Forwarded-For', 'X-Real-IP'],

    'exposed_headers' => [],

    'max_age' => 3600,

    // فقط وقتی یک originِ صریح مجاز است معنا دارد (با `*` بی‌اثر است).
    'supports_credentials' => true,

];
