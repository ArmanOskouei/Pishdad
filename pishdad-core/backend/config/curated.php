<?php

return [

    /*
    | مسیرِ فهرستِ curated (F1.2).
    |
    | پیش‌فرض `registry/curated.json` کنارِ خودِ هسته است. دلیلش عملی است و در
    | `CuratedRegistry::path()` هم توضیح داده شده: ظرف `pishdad-app` فقط
    | `pishdad-core/backend` mount است، پس فایلی در ریشهٔ مخزن در محیطی که CI و
    | فرمان‌ها اجرا می‌شوند اصلاً دیده نمی‌شود.
    |
    | بیرون از مخزن نگهداری می‌شود؟ `CURATED_REGISTRY_PATH` را بده.
    */
    'path' => env('CURATED_REGISTRY_PATH', ''),

];
