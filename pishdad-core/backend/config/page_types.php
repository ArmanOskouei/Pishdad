<?php

/*
| انواع صفحه + بلوک‌های پیش‌فرض هر نوع (تسک ۴.۱).
| خروجی GET /api/v1/admin/layouts/page-types از همین فایل ساخته می‌شود؛
| PUT .../page-type/{type}/blocks فقط typeهای همین فایل و بلوک‌های
| config/blocks.php را می‌پذیرد. افزودن نوع جدید = یک ورودی + تست.
*/
return [
    'home' => [
        'title' => 'خانه',
        'description' => 'صفحه اصلی سایت.',
        'default_blocks' => [
            ['type' => 'hero', 'data' => ['title' => 'به وب‌سایت ما خوش آمدید']],
            ['type' => 'text', 'data' => ['body' => 'معرفی کوتاه سایت...']],
            ['type' => 'cta', 'data' => ['label' => 'تماس با ما', 'href' => '/contact']],
        ],
    ],
    'single' => [
        'title' => 'تک‌صفحه',
        'description' => 'صفحه داخلی عمومی (درباره، تماس، ...).',
        'default_blocks' => [
            ['type' => 'hero', 'data' => ['title' => 'عنوان صفحه']],
            ['type' => 'text', 'data' => ['body' => 'متن صفحه...']],
        ],
    ],
    'archive' => [
        'title' => 'آرشیو',
        'description' => 'آرشیو دسته‌بندی/برچسب.',
        'default_blocks' => [
            ['type' => 'hero', 'data' => ['title' => 'آرشیو']],
        ],
    ],
];
