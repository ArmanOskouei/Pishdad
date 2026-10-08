<?php

/*
| تسک ۵.۴ — کلید عمومی Ed25519 برای راستی‌آزمایی امضای پلاگین/قالب.
| مقدار base64 از کلید ۳۲ بایتی (sodium_crypto_sign_publickey).
| اگر خالی باشد، پلاگین جدید fail-closed است (422) و قالب قدیمی
| unverified می‌ماند تا مسیر مهاجرت مشخص شود.
|
| تسک ۵.۱۲ — رمز نقش `pishdad_plugin_ddl`.
|
| نقشی که migration افزونه با آن اجرا می‌شود. عمداً **جدا** از `DB_PASSWORD` است،
| چون این دو نقش متفاوت‌اند: `cms` مالک هسته است و آزاد است، این نقش فقط
| `CREATE` روی شِما و `SELECT` روی رجیستری دارد و به بقیه چیزی نرسد.
|
| اگر خالی باشد، `PluginDdlConnection` عمداً **fail-closed** است: migration افزونه
| اجرا نمی‌شود. اجرای بی‌نگهبان از رد کردن بدتر است.
*/
return [
    'public_key' => env('PLUGIN_PUBLIC_KEY', ''),

    'plugin_ddl_password' => env('PLUGIN_DDL_PASSWORD', ''),

    /*
    | «اپراتور واقعاً این کلید را داده یا نه» — برای `pishdad:doctor`.
    |
    | دلیلِ وجودش مثل `mail.env_present` است: `env()` خام بعد از `config:cache`
    | بیرون از این فایل‌ها `null` می‌دهد، پس `doctor` روی یک نصبِ کش‌شده
    | «کلید نیست» می‌گفت — یا بدتر، اگر به `config()` تکیه می‌کرد و آن را
    | با پیش‌فرضِ خالی مقایسه می‌کرد، «هست» می‌گفت. این بلوک هر دو خطا را
    | از بین می‌برد چون نتیجه‌اش داخل خودِ کانفیگ ذخیره می‌شود.
    */
    'env_present' => [
        'PLUGIN_DDL_PASSWORD' => env('PLUGIN_DDL_PASSWORD') !== null,
        'PLUGIN_PUBLISHER_SECRET_KEY' => env('PLUGIN_PUBLISHER_SECRET_KEY') !== null,
    ],
];
