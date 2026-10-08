<?php

namespace App\Services\External;

use App\Services\Notifications\TelegramSender;
use App\Services\Outbox\Outbox;
use App\Services\Sms\SmsDriverFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * یک منبعِ حقیقت برای وضعیتِ همهٔ وابستگی‌های خارجی.
 *
 * ## چرا یک کلاس و نه هر command خودش چک کند
 *
 * اگر `doctor` خودش چک می‌کرد و `SmsDriverFactory` هم جدا، دو جای حقیقت داشتیم که
 * بعد از یک تغییر با هم نمی‌خوانند. اینجا **یک** جا منطقِ «چه چیزی کم است» هست
 * و:
 *  - `pishdad:doctor` فقط آن را چاپ می‌کند،
 *  - `MissingCredentialException` از همان محاسبه ساخته می‌شود.
 *
 * یعنی «آنچه `doctor` می‌گوید کمه» و «آنچه در لحظهٔ استفاده exception می‌دهد»
 * نمی‌توانند از هم جدا بیفتند.
 *
 * ## چرا تلاشِ شبکه‌ای نمی‌کنیم (به‌جز Redis/MinIO)
 *
 * یک `doctor` که در نصبِ بدونِ اینترنت اجرا شود نباید ۳۰ ثانیه معطل بماند و بعد
 * بگوید «ناموفق». برای هم فقط چیزهایی را واقعاً صدا می‌زنیم که (الف) ارزان‌اند و
 * (ب) تشخیصی‌اند: اتصال Redis و سرِ S3. بقیه — وجودِ کلید، که چیزی را ثابت می‌کند
 * و چیزی برای اثباتِ کذب نمی‌خواهد.
 */
final class DependencyChecker
{
    /**
     * همهٔ وابستگی‌ها، به ترتیبِ اهمیت برای اپراتور.
     *
     * @return list<DependencyStatus>
     */
    public function all(): array
    {
        return [
            $this->mail(),
            $this->sms(),
            $this->telegram(),
            $this->storage(),
            $this->redis(),
            $this->pluginDdl(),
            $this->sealKeys(),
            $this->outbox(),
        ];
    }

    /**
     * @return list<DependencyStatus>
     */
    public function blocking(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (DependencyStatus $s): bool => $s->isBlocking(),
        ));
    }

    // ---------------------------------------------------------------- ایمیل (E2)

    /**
     * E2 — ایمیل.
     *
     * ⭐ تفکیکِ سه حالت که اینجا گمراه‌کننده می‌شود:
     *
     *  - `failover` (پیش‌فرض `.env.example`) ⇒ اگر کلید SMTP نباشد، سالم است ولی
     *    **کاهش‌یافته**: هر پیام به لاگ می‌رود و هیچ‌کس نمی‌خواند ⇒ `warn`.
     *  - `smtp` تنها با کلیدِ ناقص ⇒ هر درخواست تا `MAIL_TIMEOUT` (۱۰ ثانیه)
     *    معطل می‌شود و بعد خطا ⇒ `missing`.
     *  - فقط `log` ⇒ در production یعنی هیچ ایمیلی بیرون نمی‌رود ⇒ `warn`.
     */
    public function mail(): DependencyStatus
    {
        $mailer = (string) config('mail.default', 'failover');
        $offline = ['log', 'array'];

        // failover/roundrobin ⇒ transportهای داخلش.
        $chain = (array) (config("mail.mailers.{$mailer}.mailers") ?? [$mailer]);
        $real = array_values(array_filter(
            $chain,
            static fn (string $t): bool => ! in_array($t, $offline, true) && $t !== 'failover' && $t !== 'roundrobin',
        ));

        if ($real === []) {
            $base = sprintf('MAIL_MAILER=%s است؛ هیچ ایمیلی بیرون نمی‌رود.', $mailer);

            return app()->isProduction()
                ? DependencyStatus::warn(
                    'mail', 'ایمیل (E2)',
                    $base.' در پروداکشن یعنی بازیابیِ رمز عبور و اعلان‌ها هرگز به کاربر نمی‌رسند.',
                    'برای ارسال واقعی MAIL_MAILER=failover بگذارید و کلیدهای MAIL_* را پر کنید.',
                )
                : DependencyStatus::warn('mail', 'ایمیل (E2)', $base.' (محیط غیرپروداکشن — قابل‌قبول).');
        }

        // برای هر transportِ واقعی، کلیدهای لازمش چیست؟
        //
        // ⭐ `config('mail.env_present.X')` و **نه** `env('X')` و نه
        // `config('mail.mailers.smtp.host')`. دلیلش دوگانه است: کانفیگ برای
        // host/port مقدارِ پیش‌فرضِ نامعتبر دارد (`127.0.0.1`/`2525`) که «تنظیم
        // شده» به نظر می‌رسد، و `env()` خام هم بعد از `config:cache` `null`
        // می‌دهد. `env_present` هر دو مشکل را ندارد.
        $unsupported = [];
        $missing = [];
        foreach ($real as $transport) {
            $keys = $this->mailKeysFor($transport);

            if ($keys === null) {
                $unsupported[] = $transport;
                continue;
            }

            foreach ($keys as $key) {
                if ($this->isMailKeyPresent($key)) {
                    continue;
                }
                $missing[$key] = true;
            }
        }

        // transportی که این مخزن پشتیبانی نمی‌کند، «کلیدِ کم» نیست — خودش مشکل است.
        if ($unsupported !== []) {
            return DependencyStatus::missing(
                'mail', 'ایمیل (E2)',
                sprintf('transport %s در این مخزن پیاده‌سازی نشده است (نه کانفیگ دارد، نه کلید محیطی).', implode('، ', $unsupported)),
                [],
                'یا MAIL_MAILER=smtp بگذارید و کلیدهای SMTP را پر کنید، یا MAIL_MAILER=failover.',
            );
        }

        $missingKeys = array_keys($missing);

        if ($missingKeys === []) {
            return DependencyStatus::ok(
                'mail', 'ایمیل (E2)',
                sprintf('MAIL_MAILER=%s و کلیدهایش کامل است (%s).', $mailer, implode('، ', $real)),
            );
        }

        $graceful = $mailer === 'failover' || $mailer === 'roundrobin';

        $payload = sprintf(
            'transport واقعی «%s» انتخاب شده اما %s خالی است.',
            implode('، ', $real),
            implode('، ', array_map(static fn (string $k): string => "`{$k}`", $missingKeys)),
        );

        if ($graceful) {
            return DependencyStatus::warn(
                'mail', 'ایمیل (E2)',
                $payload.' چون زنجیره fallback دارد، ایمیل‌ها به لاگ می‌روند و کسی آن‌ها را نمی‌بیند.',
                'کلید(های) بالا را در .env پر کنید تا ایمیل واقعاً برسد.',
            );
        }

        return DependencyStatus::missing(
            'mail', 'ایمیل (E2)',
            $payload.' چون fallback ندارد، هر ارسال تا '.config('mail.mailers.smtp.timeout').' ثانیه معطل می‌شود و بعد شکست می‌خورد.',
            $missingKeys,
            'کلید(های) بالا را پر کنید، یا MAIL_MAILER=failover بگذارید تا پیام‌ها گم نشوند.',
        );
    }

    /**
     * آیا اپراتور این کلیدِ ایمیل را واقعاً داده است؟
     *
     * اگر کانفیگِ `env_present` کلید را نداشته باشد (یک نصبِ قدیمی که کانفیگش
     * به‌روز نشده)، به مقدارِ واقعی برمی‌گردیم تا false-positive ندهیم.
     */
    private function isMailKeyPresent(string $key): bool
    {
        $present = config('mail.env_present');

        if (! is_array($present) || ! array_key_exists($key, $present)) {
            // مسیرِ پشتیبان: اگر کانفیگ به‌روز نشده، فقط رشتهٔ خالی را «ناموجود»
            // حساب می‌کنیم. عمداً `config('mail.mailers...')` را نمی‌خوانیم چون
            // پیش‌فرضِ نامعتبر دارد و همیشه «پُر» به نظر می‌رسد.
            return trim((string) config('mail.mailers.smtp.host', '')) !== ''
                && trim((string) config('mail.mailers.smtp.username', '')) !== '';
        }

        return (bool) $present[$key];
    }

    /**
     * کلیدهای لازمِ یک transportِ واقعی، یا `null` اگر این transport در این مخزن
     * پیاده‌سازی نشده باشد.
     *
     * ⭐ آن `null` عمداً با یک نامِ ساختگیِ کلید جایگزین نشده. اولین نسخهٔ این
     * متد یک سینتینکسِ `__unsupported_transport__` برمی‌گرداند و آن مستقیم در
     * گزارش می‌آمد — یعنی به اپراتور گفته می‌شد «کلیدِ `__unsupported_transport__`
     * را در .env پر کن»، که هیچ‌کس نمی‌تواند. نبودِ transport یک وضعیتِ متفاوت از
     * نبودِ کلید است و باید جدا گفته شود.
     *
     * @return list<string>|null
     */
    private function mailKeysFor(string $transport): ?array
    {
        return match ($transport) {
            'smtp' => ['MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD'],
            // sendmail به کلیدِ اجباری نیاز ندارد (مسیرِ پیش‌فرض دارد).
            'sendmail' => [],
            // لاروال این‌ها را می‌شناسد ولی **این مخزن** برایشان کانفیگ و
            // کلیدِ محیطی تعریف نکرده ⇒ انتخابشان یعنی تنظیمِ ناتمام.
            'ses', 'ses-v2', 'postmark', 'resend', 'mailgun' => null,
            default => [],
        };
    }

    // ---------------------------------------------------------------- پیامک (E3)

    public function sms(): DependencyStatus
    {
        $driver = strtolower(trim((string) config('sms.driver', 'null')));
        $missing = SmsDriverFactory::missingKeys($driver);

        if ($missing !== []) {
            return DependencyStatus::missing(
                'sms', 'پیامک (E3)',
                sprintf('SMS_DRIVER=panel انتخاب شده اما %s تنظیم نشده است.', implode('، ', $missing)),
                $missing,
                'کلید(های) بالا را در .env پر کنید، یا SMS_DRIVER=null بگذارید تا صادقانه «پیامک نداریم» را بگوید.',
            );
        }

        return match ($driver) {
            'panel' => DependencyStatus::ok('sms', 'پیامک (E3)', 'SMS_DRIVER=panel و کلیدهای درگاه کامل است.'),
            'log' => DependencyStatus::warn(
                'sms', 'پیامک (E3)',
                'SMS_DRIVER=log است؛ متن پیام فقط در لاگ سرور می‌نشیند و به هیچ گوشی‌ای نمی‌رسد.',
                'در production این را به null یا panel تغییر دهید.',
            ),
            // ناشناخته را جدا می‌گیریم تا تایپِ اشتباه پنهان نماند.
            default => in_array($driver, ['null', 'none', 'off', ''], true)
                ? DependencyStatus::warn(
                    'sms', 'پیامک (E3)',
                    'SMS_DRIVER=null است؛ پیامکی بیرون نمی‌رود و «تأیید شماره» در پروداکشن کار نمی‌کند.',
                    'برای فعال‌سازی، درگاه بگیرید و SMS_DRIVER=panel بگذارید.',
                )
                : DependencyStatus::warn(
                    'sms', 'پیامک (E3)',
                    sprintf('SMS_DRIVER=%s ناشناخته است؛ به درایور بی‌خطر `null` برگشت (هیچ پیامکی نمی‌رود).', $driver),
                    'مقادیر معتبر: null، log، panel.',
                ),
        };
    }

    // ------------------------------------------------------------- تلگرام (WF-M15)

    /**
     * WF-M15 — کانالِ تلگرام.
     *
     * خاموشیِ این کانال **مسدودکننده نیست** (fail-soft): نبودِ توکن یعنی هیچ
     * اعلانی از تلگرام نمی‌رود، ولی سایت سالم است. پس `warn` نه `missing`.
     */
    public function telegram(): DependencyStatus
    {
        if (TelegramSender::configured()) {
            return DependencyStatus::ok('telegram', 'تلگرام (WF-M15)', 'ربات تلگرام وصل است؛ ارسال از طریق Bot API فعال است. (توکنِ .env، وگرنه پنل، وگرنه رباتِ پیش‌فرضِ پیشداد.)');
        }

        return DependencyStatus::warn(
            'telegram', 'تلگرام (WF-M15)',
            'توکن ربات تلگرام وجود ندارد؛ هیچ اعلانی از طریق تلگرام فرستاده نمی‌شود. (کانالِ اختیاری — سایت سالم است.)',
            'رباتِ پیش‌فرضِ پیشداد خاموش شده. برای فعال‌سازی، یک ربات با @BotFather بسازید و توکنش را یا در .env (TELEGRAM_BOT_TOKEN) بگذارید یا در پنل، صفحهٔ «ترجیحات اعلان»، بخشِ ربات تلگرام وارد کنید. توجه: تلگرام در ایران فیلتر است — اگر سرور در ایران باشد، ارسال کار نمی‌کند مگر آنکه سرور به اینترنتِ بدون فیلتر دسترسی داشته باشد.',
        );
    }

    // ------------------------------------------------------------- فضای ذخیره‌سازی

    public function storage(): DependencyStatus
    {
        $disk = (string) config('filesystems.default');
        $keys = ['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_BUCKET'];
        $present = (array) config('filesystems.env_present', []);
        $missing = array_values(array_filter(
            $keys,
            static fn (string $k): bool => ! (bool) ($present[$k] ?? false),
        ));

        if ($disk !== 's3') {
            return DependencyStatus::warn(
                'storage', 'فضای ذخیره‌سازی (MinIO/S3)',
                "FILESYSTEM_DISK={$disk} است؛ MinIO/S3 فعال نیست.",
                'برای آپلود واقعی media، FILESYSTEM_DISK=s3 و کلیدهای AWS_* را تنظیم کنید.',
            );
        }

        if ($missing !== []) {
            return DependencyStatus::missing(
                'storage', 'فضای ذخیره‌سازی (MinIO/S3)',
                'FILESYSTEM_DISK=s3 است اما '.implode('، ', $missing).' خالی است — آپلود فایل شکست می‌خورد.',
                $missing,
                'کلید(های) بالا را پر کنید (در compose پیش‌فرض minioadmin/minioadmin123 است).',
            );
        }

        // ارزان است و تشخیصی: آیا سر بالا جواب می‌دهد؟
        try {
            $client = app('aws')->createClient([
                'version' => (string) config('filesystems.disks.s3.version', 'latest'),
                'region' => (string) config('filesystems.disks.s3.region', 'us-east-1'),
                'endpoint' => (string) config('filesystems.disks.s3.endpoint'),
                'credentials' => [
                    'key' => (string) env('AWS_ACCESS_KEY_ID'),
                    'secret' => (string) env('AWS_SECRET_ACCESS_KEY'),
                ],
            ]);
            $client->headBucket(['Bucket' => (string) config('filesystems.disks.s3.bucket')]);

            return DependencyStatus::ok('storage', 'فضای ذخیره‌سازی (MinIO/S3)', 'کلیدها کامل است و سر S3 پاسخ داد.');
        } catch (Throwable $e) {
            return DependencyStatus::warn(
                'storage', 'فضای ذخیره‌سازی (MinIO/S3)',
                'کلیدها کامل است ولی سر S3 پاسخ نداد: '.mb_substr($e->getMessage(), 0, 140),
                'آدرس و دسترسی سرویس MinIO را بررسی کنید.',
            );
        }
    }

    // ------------------------------------------------------------------- Redis

    public function redis(): DependencyStatus
    {
        $host = (string) (config('database.redis.default.host') ?: '127.0.0.1');
        $port = (int) (config('database.redis.default.port') ?: 6379);

        try {
            Redis::connection('default')->ping();

            return DependencyStatus::ok('redis', 'Redis', sprintf('%s:%s پاسخ داد.', $host, $port));
        } catch (Throwable $e) {
            return DependencyStatus::warn(
                'redis', 'Redis',
                sprintf('%s:%s پاسخ نداد (%s). کش روی DB است و صف هم دیتابیسی، پس سایت بالا می‌ماند.', $host, $port, mb_substr($e->getMessage(), 0, 90)),
                'اگر می‌خواهید کش/صف روی Redis باشد، سرویس را بالا بیاورید یا تنظیمات را اصلاح کنید.',
            );
        }
    }

    // --------------------------------------------------- نقشِ DDL افزونه‌ها

    /**
     * نقشِ محدودِ `pishdad_plugin_ddl` — همان چیزی که مانعِ آن است که کدِ افزونه
     * بتواند `DROP TABLE` کند. نبودنش یعنی افزونه‌ها یا نصب نمی‌شوند یا با
     * نقشِ اصلی اجرا می‌شوند (یعنی **بدونِ محدودیت**).
     */
    public function pluginDdl(): DependencyStatus
    {
        $role = \App\Services\Plugins\PluginDdlConnection::ROLE;

        if (! (bool) (config('plugins.env_present.PLUGIN_DDL_PASSWORD') ?? false)) {
            return DependencyStatus::missing(
                'plugin-ddl', 'نقشِ DDL افزونه‌ها',
                'PLUGIN_DDL_PASSWORD تنظیم نشده؛ رمز نقشِ محدودِ افزونه‌ها قابل ساخت/بازنشانی نیست.',
                ['PLUGIN_DDL_PASSWORD'],
                'PLUGIN_DDL_PASSWORD را در .env بگذارید، بعد `php artisan plugin-ddl:reset-password` را اجرا کنید.',
            );
        }

        try {
            $exists = DB::selectOne(
                'select rolsuper, rolcreaterole from pg_roles where rolname = ?',
                [$role]
            );
        } catch (Throwable $e) {
            return DependencyStatus::warn(
                'plugin-ddl', 'نقشِ DDL افزونه‌ها',
                'نتوانست وضعیت نقش را از pg_roles خواند: '.mb_substr($e->getMessage(), 0, 120),
                'دسترسی کاربرِ دیتابیس به pg_roles را بررسی کنید.',
            );
        }

        if ($exists === null) {
            return DependencyStatus::warn(
                'plugin-ddl', 'نقشِ DDL افزونه‌ها',
                sprintf('نقشِ `%s` روی این دیتابیس ساخته نشده است؛ نصب/ارتقای افزونه با نقشِ اصلی اجرا می‌شود (بدونِ محدودیت).', $role),
                'دستور پر کردن نقش را اجرا کنید: `php artisan plugin-ddl:reset-password`.',
            );
        }

        if ($exists->rolsuper) {
            return DependencyStatus::warn(
                'plugin-ddl', 'نقشِ DDL افزونه‌ها',
                sprintf('نقشِ `%s` از نوع superuser است؛ محدودیتِ واقعی ندارد.', $role),
                'این نقش نباید superuser باشد. با دستورالعملِ نصب نقش را دوباره بسازید.',
            );
        }

        return DependencyStatus::ok('plugin-ddl', 'نقشِ DDL افزونه‌ها', sprintf('نقشِ `%s` موجود و محدود است.', $role));
    }

    // ------------------------------------------------------------- کلیدهای مهر

    /**
     * کلیدهایی که «امضا» می‌کنند و نبودشان یعنی یک چیز بی‌صدا باطل می‌شود:
     * مهرِ یکپارچگی افزونه‌ها و توکنِ بازنویسیِ ISR.
     */
    public function sealKeys(): DependencyStatus
    {
        $weak = [];
        // `PLUGIN_PUBLISHER_SECRET_KEY` از `env_present` می‌آید (نه از `env()` خام)
        // تا بعد از `config:cache` هم درست گزارش شود. `REVALIDATE_SECRET`
        // مقدارِ پیش‌فرضِ دارد، پس «حضور» مهم نیست — «ضعیف بودن» مهم است.
        if (! (bool) (config('plugins.env_present.PLUGIN_PUBLISHER_SECRET_KEY') ?? false)) {
            $weak[] = 'PLUGIN_PUBLISHER_SECRET_KEY';
        }

        $revalidate = (string) config('revalidate.secret', '');
        if (trim($revalidate) === '') {
            $weak[] = 'REVALIDATE_SECRET';
        } elseif (str_contains($revalidate, 'change-me')) {
            // پیش‌فرضِ کانفیگ عمداً همین است — در production یعنی هرکس URL را
            // حدس بزند می‌تواند کشِ سایت را باطال کند.
            $weak[] = 'REVALIDATE_SECRET';
        }

        if ($weak === []) {
            return DependencyStatus::ok('seal-keys', 'کلیدهای مهر', 'کلید ناشر و توکنِ revalidate مقدار دارند.');
        }

        return DependencyStatus::warn(
            'seal-keys', 'کلیدهای مهر',
            'تنظیم نشده یا هنوز مقدارِ پیش‌فرض است: '.implode('، ', array_map(static fn (string $k): string => "`{$k}`", $weak))
            .'. کارکردشان بدونِ کلیدِ واقعی «بی‌صدا از کار افتاده» است.',
            'در production حتماً این دو را با مقادیر تصادفیِ بلند پر کنید.',
        );
    }

    // --------------------------------------------------------------- صفِ خروجی

    /**
     * صفِ تحویل (outbox). اینجا مهم‌ترین بخشِ `doctor` است: اگر cron نرود، پیام‌ها
     * **بی‌سروصدا** در صف می‌مانند و هیچ نشانه‌ای هم نیست.
     */
    public function outbox(): DependencyStatus
    {
        try {
            $pending = DB::table('notification_deliveries')->where('status', Outbox::STATUS_PENDING)->count();
            $failed = DB::table('notification_deliveries')->where('status', Outbox::STATUS_FAILED)->count();
            $stuck = DB::table('notification_deliveries')->where('status', Outbox::STATUS_PROCESSING)->count();
        } catch (Throwable $e) {
            return DependencyStatus::warn(
                'outbox', 'صفِ تحویل (outbox)',
                'نتوانستم جدول `notification_deliveries` را بخوانم: '.mb_substr($e->getMessage(), 0, 110),
                'مهاجرت‌ها را اجرا کنید: php artisan migrate.',
            );
        }

        $detail = sprintf('در انتظار: %d · ناموفق: %d · گیرکرده در processing: %d', $pending, $failed, $stuck);

        if ($failed > 0 || $stuck > 0) {
            return DependencyStatus::warn(
                'outbox', 'صفِ تحویل (outbox)',
                $detail.' — پیام‌هایی هست که تحویل نشده‌اند. اگر cron کار کند خودشان retry می‌شوند.',
                '`php artisan outbox:drain` را دستی اجرا کنید؛ اگر باز هم ماند، جزئیات را در لاگ ببینید.',
            );
        }

        if ($pending > 0) {
            return DependencyStatus::warn(
                'outbox', 'صفِ تحویل (outbox)',
                $detail.' — منتظرِ tick بعدی هستند. اگر این عدد زیاد شد یعنی cron نمی‌دود.',
                'بررسی کنید که `php artisan schedule:run` هر دقیقه در cron هست.',
            );
        }

        return DependencyStatus::ok('outbox', 'صفِ تحویل (outbox)', 'صف خالی است؛ چیزی گیر نکرده.');
    }
}
