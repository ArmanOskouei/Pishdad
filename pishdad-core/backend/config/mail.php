<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | F0.10 — پیش‌فرض `failover` است (smtp → log)، نه `log` و نه `smtp` تنها:
    |
    |  - `log` به‌تنهایی یعنی ایمیل هرگز بیرون نمی‌رود ولی هیچ‌کس هم خبردار
    |    نمی‌شود که اصلاً فرستاده نشد.
    |  - `smtp` به‌تنهایی یعنی یک SMTP مرده هر درخواست را تا `timeout` (۱۰ ثانیه)
    |    نگه می‌دارد و بعد خطا می‌دهد.
    |  - `failover` اول تلاش می‌کند واقعاً بفرستد، و اگر نشد همان پیام را در لاگ
    |    می‌گذارد. یعنی هیچ پیامی بی‌ردّ نمی‌شود و هیچ صفی هم روی SMTP مرده قفل
    |    نمی‌شود.
    |
    | برای اینکه کاملاً آفلاین بمانید (پیش‌فرض توسعه) کافی است
    | `MAIL_MAILER=log` بگذارید؛ برای ارسال واقعی `failover` را نگه دارید و فقط
    | کلیدهای `MAIL_*` زیر را پر کنید. اعتبارنامهٔ واقعی در این مخزن نیست (E2).
    |
    */

    'default' => env('MAIL_MAILER', 'failover'),

    /*
    |--------------------------------------------------------------------------
    | وضعیتِ «اپراتور واقعاً این کلید را داده یا نه» (E2)
    |--------------------------------------------------------------------------
    |
    | چرا یک بلوکِ جدا، وقتی `config/mail.php` خودش هم همین کلیدها را می‌خواند؟
    |
    | چون مقدارِ **پیش‌فرض** با «تنظیم‌شده» فرق دارد. `MAIL_HOST` پیش‌فرضِ
    | `127.0.0.1` دارد و `MAIL_PORT` پیش‌فرضِ `2525`. یعنی خواندنِ
    | `config('mail.mailers.smtp.host')` همیشه یک رشتهٔ پُر می‌دهد و
    | `pishdad:doctor` می‌گفت «همه‌چیز کامل است» در حالی که اپراتور هیچ‌چیز
    | ننوشته — دقیقاً همان دروغی که این فایل جلویش را می‌گیرد.
    |
    | `env('X')` بدونِ مقدارِ پیش‌فرض `null` می‌دهد، پس `!== null` یعنی «واقعاً
    | از .env آمده». و چون این نتیجه **در فایل کانفیگ** ذخیره می‌شود، بعد از
    | `config:cache` هم درست کار می‌کند (برخلاف `env()` خام).
    |
    */
    'env_present' => [
        'MAIL_MAILER' => env('MAIL_MAILER') !== null,
        'MAIL_HOST' => env('MAIL_HOST') !== null,
        'MAIL_PORT' => env('MAIL_PORT') !== null,
        'MAIL_USERNAME' => env('MAIL_USERNAME') !== null,
        'MAIL_PASSWORD' => env('MAIL_PASSWORD') !== null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            // F0.10 — SMTP config-only: سقف ۱۰ ثانیه تا worker روی SMTP مرده قفل نکند.
            // failover (smtp → log) پایین همین فایل، هیچ ایمیلی را گم نمی‌کند.
            'timeout' => (int) env('MAIL_TIMEOUT', 10),
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],

    /*
    |--------------------------------------------------------------------------
    | گیرندهٔ اعلان‌های مدیریتی (E6)
    |--------------------------------------------------------------------------
    |
    | آدرسِ صندوقی که ایمیلِ «پیام جدید فرم تماس» به آن می‌رود. اگر خالی باشد
    | (پیش‌فرض)، `ContactTicketController` به ایمیلِ سوپرادمین‌ها می‌فرستد.
    | یک مقدارِ نامعتبر هم مثلِ خالی رفتار می‌شود تا اشتباهِ تایپی به آدرسِ
    | بی‌صاحب ارسال نشود.
    |
    */
    'admin_address' => env('MAIL_ADMIN_ADDRESS'),

];
