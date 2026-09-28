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
];
