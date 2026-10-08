<?php

/*
| رجیستری ویجت‌های هدر/فوتر (تسک ۴.۱ + تسک ۶).
| خروجی GET/PUT /api/v1/admin/layouts/header|footer فقط typeهای همین فایل
| را می‌پذیرد و GET /api/v1/admin/widgets/schema همین schemaها را (به‌علاوه
| ویجت‌های اعلام‌شده در مانیفست پلاگین/قالب فعال) برای SchemaForm می‌دهد.
| افزودن ویجت جدید = افزودن یک ورودی در ناحیه مربوط + تست — بدون تغییر کنترلر.
|
| هر ویجت: title فارسی + description + schema تنظیمات (JSON Schema سبک) +
| ui (ui.labels = برچسب فارسی هر فیلد برای فرم؛ ui.media_field = کلیدی که
| با MediaPicker انتخاب می‌شود). فیلدها دقیقاً همان‌هایی هستند که رندرر
| عمومی سایت ([...path]/page.tsx → Widget) می‌خواند — فیلد نمایشیِ بدون
| مصرف در رندر اضافه نکن (قرارداد تسک ۶).
*/
return [
    'header' => [
        'logo' => [
            'title' => 'لوگو',
            'description' => 'لوگو + عنوان سایت (همیشه از تنظیمات سایت؛ اندازه با قالب).',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'show_title' => ['type' => 'boolean', 'default' => true],
                ],
            ],
            'ui' => [
                'labels' => [
                    'show_title' => 'نمایش عنوان سایت',
                ],
            ],
        ],
        'nav' => [
            'title' => 'منوی ناوبری',
            'description' => 'منوی اصلی (لینک صفحه‌ای یا سفارشی + زیرمنوی کشویی تا عمق ۲؛ خالی = فقط خانه).',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'links' => [
                        'type' => 'array', 'maxItems' => 12,
                        'items' => [
                            'type' => 'object',
                            // سازگار عقب‌رو: {label, href} قدیمی = custom.
                            // اعتبارسنجی معنایی بازگشتی (page_id موجود/منتشرشده/مال خود،
                            // حداکثر ۸ فرزند هر والد، عمق حداکثر ۲) در LinkItems + پیام فارسی.
                            // children = همین مدل (والد custom با فرزند، href اختیاری).
                            'properties' => [
                                'kind' => ['type' => 'string', 'enum' => ['page', 'custom'], 'default' => 'custom', 'description' => 'نوع آیتم: page = صفحه سایت (رزولو زنده)، custom = لینک دستی'],
                                'page_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'شناسه صفحه منتشرشده (فقط وقتی kind=page)'],
                                'label' => ['type' => 'string', 'maxLength' => 80],
                                'href' => ['type' => 'string', 'maxLength' => 2048],
                                'children' => [
                                    'type' => 'array', 'maxItems' => 8,
                                    'description' => 'زیرمنو (همین مدل؛ حداکثر عمق ۲، هر والد حداکثر ۸ فرزند)',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'kind' => ['type' => 'string', 'enum' => ['page', 'custom'], 'default' => 'custom'],
                                            'page_id' => ['type' => 'integer', 'minimum' => 1],
                                            'label' => ['type' => 'string', 'maxLength' => 80],
                                            'href' => ['type' => 'string', 'maxLength' => 2048],
                                            'children' => ['type' => 'array', 'maxItems' => 8, 'description' => 'سطح آخر (نوه) — بدون تودرتویی بیشتر'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'style' => ['type' => 'string', 'enum' => ['horizontal', 'mega'], 'default' => 'horizontal'],
                ],
            ],
            'ui' => [
                'labels' => [
                    'links' => 'پیوندها',
                    'style' => 'سبک نمایش',
                ],
            ],
        ],
        'search' => [
            'title' => 'جستجو',
            'description' => 'فرم جستجوی سایت.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'placeholder' => ['type' => 'string', 'maxLength' => 60],
                ],
            ],
            'ui' => [
                'labels' => [
                    'placeholder' => 'متن راهنما',
                ],
            ],
        ],
        'cta' => [
            'title' => 'دکمه اقدام',
            'description' => 'دکمه برجسته هدر (مثل «تماس» یا «خرید»).',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'label' => ['type' => 'string', 'maxLength' => 40],
                    'href' => ['type' => 'string', 'maxLength' => 2048],
                ],
            ],
            'ui' => [
                'labels' => [
                    'label' => 'متن دکمه',
                    'href' => 'نشانی پیوند',
                ],
            ],
        ],
        'socials' => [
            'title' => 'آیکون شبکه‌ها',
            'description' => 'آیکون شبکه‌های اجتماعی فعال (از تنظیمات socials).',
            'schema' => ['type' => 'object', 'properties' => []],
        ],
    ],

    'footer' => [
        'about' => [
            'title' => 'درباره',
            'description' => 'متن کوتاه درباره + لوگو.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'text' => ['type' => 'string', 'maxLength' => 500],
                ],
            ],
            'ui' => [
                'labels' => [
                    'text' => 'متن درباره',
                ],
            ],
        ],
        'links' => [
            'title' => 'ستون لینک‌ها',
            'description' => 'یک ستون عنوان‌دار از لینک‌های صفحه‌ای یا سفارشی (با زیرگروه کشویی تا عمق ۲) — هر ویجت links یک ستون است.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'heading' => ['type' => 'string', 'maxLength' => 80],
                    'links' => [
                        'type' => 'array', 'maxItems' => 12,
                        'items' => [
                            'type' => 'object',
                            // سازگار عقب‌رو: {label, href} قدیمی = custom.
                            // اعتبارسنجی معنایی بازگشتی در LinkItems + پیام فارسی.
                            // children = همین مدل (حداکثر عمق ۲، هر والد حداکثر ۸ فرزند).
                            'properties' => [
                                'kind' => ['type' => 'string', 'enum' => ['page', 'custom'], 'default' => 'custom', 'description' => 'نوع آیتم: page = صفحه سایت (رزولو زنده)، custom = لینک دستی'],
                                'page_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'شناسه صفحه منتشرشده (فقط وقتی kind=page)'],
                                'label' => ['type' => 'string', 'maxLength' => 80],
                                'href' => ['type' => 'string', 'maxLength' => 2048],
                                'children' => [
                                    'type' => 'array', 'maxItems' => 8,
                                    'description' => 'زیرگروه (همین مدل؛ حداکثر عمق ۲، هر والد حداکثر ۸ فرزند)',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'kind' => ['type' => 'string', 'enum' => ['page', 'custom'], 'default' => 'custom'],
                                            'page_id' => ['type' => 'integer', 'minimum' => 1],
                                            'label' => ['type' => 'string', 'maxLength' => 80],
                                            'href' => ['type' => 'string', 'maxLength' => 2048],
                                            'children' => ['type' => 'array', 'maxItems' => 8, 'description' => 'سطح آخر (نوه) — بدون تودرتویی بیشتر'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'ui' => [
                'labels' => [
                    'heading' => 'عنوان ستون',
                    'links' => 'پیوندها',
                ],
            ],
        ],
        'contact' => [
            'title' => 'اطلاعات تماس',
            'description' => 'تلفن/ایمیل (از تنظیمات سایت).',
            'schema' => ['type' => 'object', 'properties' => []],
        ],
        'newsletter' => [
            'title' => 'خبرنامه',
            'description' => 'متن دعوت به عضویت خبرنامه.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'text' => ['type' => 'string', 'maxLength' => 200],
                ],
            ],
            'ui' => [
                'labels' => [
                    'text' => 'متن دعوت',
                ],
            ],
        ],
        'copyright' => [
            'title' => 'کپی‌رایت',
            'description' => 'نوار کپی‌رایت انتهای فوتر.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'text' => ['type' => 'string', 'maxLength' => 200],
                ],
            ],
            'ui' => [
                'labels' => [
                    'text' => 'متن کپی‌رایت',
                ],
            ],
        ],
    ],
];
