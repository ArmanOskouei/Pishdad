<?php

/*
| رجیستری بلوک‌های فعال ویرایشگر (تسک ۱.۱).
| خروجی GET /api/v1/admin/blocks/schema از همین فایل ساخته می‌شود تا
| فرانت (SchemaForm) همیشه با بک‌اند هم‌قرارداد بماند.
| افزودن بلوک جدید = افزودن یک ورودی + تست — بدون نیاز به تغییر کنترلر.
*/
return [
    'hero' => [
        'title' => 'هیرو',
        'description' => 'سربرگ اصلی صفحه: تیتر، زیرتیتر و تصویر پس‌زمینه.',
        'active' => true,
        'schema' => [
            'type' => 'object',
            'required' => ['title'],
            'properties' => [
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
                'subtitle' => ['type' => 'string', 'maxLength' => 300],
                'image_id' => ['type' => 'integer', 'minimum' => 1],
                'align' => ['type' => 'string', 'enum' => ['right', 'center', 'left'], 'default' => 'right'],
            ],
        ],
    ],
    'text' => [
        'title' => 'متن',
        'description' => 'بلوک متنی ریچ‌تکست (Jodit در فرانت).',
        'active' => true,
        'schema' => [
            'type' => 'object',
            'required' => ['body'],
            'properties' => [
                'body' => ['type' => 'string', 'minLength' => 1],
            ],
        ],
    ],
    'image' => [
        'title' => 'تصویر',
        'description' => 'تک‌تصویر با alt اجباری (دسترس‌پذیری).',
        'active' => true,
        'schema' => [
            'type' => 'object',
            'required' => ['media_id'],
            'properties' => [
                'media_id' => ['type' => 'integer', 'minimum' => 1],
                'alt' => ['type' => 'string', 'maxLength' => 200],
                'caption' => ['type' => 'string', 'maxLength' => 300],
            ],
        ],
    ],
    'cta' => [
        'title' => 'دعوت به اقدام',
        'description' => 'دکمه/بنر دعوت به اقدام.',
        'active' => true,
        'schema' => [
            'type' => 'object',
            'required' => ['label', 'href'],
            'properties' => [
                'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                'href' => ['type' => 'string', 'maxLength' => 2048],
                'style' => ['type' => 'string', 'enum' => ['primary', 'secondary', 'ghost'], 'default' => 'primary'],
            ],
        ],
    ],
    'gallery' => [
        'title' => 'گالری',
        'description' => 'شبکه چندتصویره.',
        'active' => true,
        'schema' => [
            'type' => 'object',
            'required' => ['media_ids'],
            'properties' => [
                'media_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 24, 'items' => ['type' => 'integer', 'minimum' => 1]],
                'columns' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 6, 'default' => 3],
            ],
        ],
    ],
    'quote' => [
        'title' => 'نقل‌قول',
        'description' => 'نقل‌قول متنی با گوینده اختیاری.',
        'active' => true,
        'schema' => [
            'type' => 'object',
            'required' => ['text'],
            'properties' => [
                'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
                'author' => ['type' => 'string', 'maxLength' => 120],
            ],
        ],
    ],
    'video' => [
        'title' => 'ویدیو',
        'description' => 'جاسازی ویدیو با آدرس؛ mp4 مستقیم پخش می‌شود، بقیه به‌صورت کارت-لینک.',
        'active' => true,
        'schema' => [
            'type' => 'object',
            'required' => ['url'],
            'properties' => [
                'url' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2048],
                'caption' => ['type' => 'string', 'maxLength' => 300],
            ],
        ],
    ],
    'form' => [
        'title' => 'فرم',
        'description' => 'یک فرم ساخته‌شده در فرم‌ساز (WF-H10) را با اسلاگ نمایش می‌دهد و پاسخ‌ها را ثبت می‌کند.',
        'active' => true,
        'schema' => [
            'type' => 'object',
            'required' => ['slug'],
            'properties' => [
                'slug' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                'title' => ['type' => 'string', 'maxLength' => 120],
            ],
        ],
    ],
    'contact-form' => [
        'title' => 'فرم تماس',
        'description' => 'فرم عمومی؛ پیش‌فرض از فرم تماس (اسلاگ contact) استفاده می‌کند و با form_slug قابل تغییر است.',
        'active' => true,
        'schema' => [
            'type' => 'object',
            'required' => ['title'],
            'properties' => [
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 200],
                'show_phone' => ['type' => 'boolean', 'default' => true],
                // WF-H10 — ارجاع اختیاری به فرم‌ساز؛ خالی = فرم تماس پیش‌فرض (contact).
                'form_slug' => ['type' => 'string', 'maxLength' => 120],
            ],
        ],
    ],
    'faq' => [
        'title' => 'سوالات متداول',
        'description' => 'آکاردئون پرسش و پاسخ (حداکثر ۲۰ آیتم).',
        'active' => true,
        'schema' => [
            'type' => 'object',
            'required' => ['items'],
            'properties' => [
                'items' => [
                    'type' => 'array', 'minItems' => 1, 'maxItems' => 20,
                    'items' => [
                        'type' => 'object',
                        'required' => ['q', 'a'],
                        'properties' => [
                            'q' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                            'a' => ['type' => 'string', 'minLength' => 1],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
