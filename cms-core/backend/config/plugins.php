<?php

/*
| تسک ۵.۴ — کلید عمومی Ed25519 برای راستی‌آزمایی امضای پلاگین/قالب.
| مقدار base64 از کلید ۳۲ بایتی (sodium_crypto_sign_publickey).
| اگر خالی باشد، پلاگین جدید fail-closed است (422) و قالب قدیمی
| unverified می‌ماند تا مسیر مهاجرت مشخص شود.
*/
return [
    'public_key' => env('PLUGIN_PUBLIC_KEY', ''),
];
