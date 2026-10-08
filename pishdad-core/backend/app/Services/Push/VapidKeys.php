<?php

namespace App\Services\Push;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * کلیدهای VAPID و امضای JWT — RFC 8292.
 *
 * ## VAPID چه می‌کند
 *
 * سرویس‌های push (Edge، موزیلا، FCM) می‌خواهند بدانند پیام از کجا آمده. بدون
 * هویت، هر سایتی می‌توانست به مرورگرِ تو اعلانِ جعلی بفرستد.
 *
 * VAPID این هویت را با یک جفت‌کلید ECDSA روی P-256 می‌سازد و در هر درخواست
 * به‌شکل یک JWT امضاشده می‌فرستد:
 *
 * ‎`Authorization: vapid t=<JWT>,k=<publicKey>`
 *
 * ## چرا ترکیبی از openssl و کد خودمان
 *
 * • **تولید کلید و امضا** با `openssl` — چون `openssl_sign` روی این محیط
 *   سالم است و استاندارد، و نیازی به بازنویسی ECDSA نیست.
/// • **ECDH** با `Curve` خودمان — چون `openssl_pkey_derive` اینجا خراب است.
///
/// تنها نقطهٔ اتصال، «اسکالر» است: از کلیدِ openssl اسکالر ۳۲ بایتی را
 * درمی‌آوریم تا بتوانیم نقطهٔ عمومی را با `Curve` هم حساب کنیم و مطمئن
 * شویم هر دو نمایش یک کلید‌اند.
 */
class VapidKeys
{
    private const CURVE = 'prime256v1';

    /** طول اسکالر P-256. */
    private const SCALAR_SIZE = 32;

    private const KEY_PUBLIC = 'vapid.public_key';

    private const KEY_PRIVATE = 'vapid.private_key';

    /**
     * کلیدِ عددیِ `pg_advisory_xact_lock` برای ساختِ جفتِ کلید.
     *
     * ⚠️ چرا این قفل لازم است: `lockForUpdate` روی ردیفِ **غایب** در
     * PostgreSQL هیچ چیزی قفل نمی‌کند. رقابتِ واقعی هم دقیقاً روی ردیفِ
     * غایب است — «اولین درخواستِ» `GET push/public-key`. پس سطری به‌تنهایی
     * رقابت را نمی‌بندد و قفلِ سراسریِ تراکنشی تنها چیزی است که می‌بندد.
     *
     * ⚠️ خودِ عدد مهم نیست، **ثابت بودنش** مهم است: عوض شدنش یعنی دو فراخوان،
     * دو قفلِ متفاوت، و رقابتی که دوباره برمی‌گردد. با نسخهٔ کلیدِ VAPID هم
     * ربطی ندارد و باید مستقل بماند.
     */
    private const ADVISORY_LOCK_KEY = 2_026_100_601;

    /**
     * جای نگه‌داریِ کلیدِ حل‌شده در کانتینر، نه در `static`.
     *
     * ## چرا کانتینر و نه `private static array $memo`
     *
     * حافظهٔ ثابت در یک پروسه‌ی **بلندمدت** (Octane، worker صف، تست) هیچ‌وقت
     * پاک نمی‌شود و آن‌وقت `get()` کلیدِ نسلِ قبلی را برمی‌گرداند در حالی که
     * دیتابیس به‌تازگی پاک شده — یعنی تستی که «کلید در اولین استفاده ساخته
     * می‌شود» سبز می‌ماند در حالی که اصلاً چیزی در دیتابیس نوشته نشده.
     *
     * کانتینر در `tearDown`_flush می‌شود و در هر درخواست/تست تازه ساخته می‌شود
     * ⇒ عمرِ حافظه دقیقاً «عمرِ درخواست» می‌شود، بدون نشت بین تست‌ها.
     */
    private const MEMO_BINDING = 'pishdad.push.vapid.keypair';

    /**
     * کلیدها را می‌خواند یا می‌سازد.
     *
     * نگه‌داشتن در جدول `settings` یعنی کلید همراه بکاپ‌های دیتابیس
     * جابه‌جا می‌شود، و این **ضروری** است: با عوض شدن کلید، همهٔ
     * اشتراک‌های ذخیره‌شده بی‌اعتبار می‌شوند و هیچ اعلانی دیگر نمی‌رسد.
     *
     * ## سه تصمیمِ این نسخه
     *
     * ۱. **حافظهٔ کوتاه‌عمر.** `broadcast()` به‌ازای هر پیام `send()` می‌زند و
     *    هر `send()` به `get()` می‌رسد. بدون حافظه، یک `broadcast(…, 500)`
     *    یعنی ۱۰۰۰ کوئریِ تنظیمات + ۵۰۰ ضربِ اسکالرِ P-256 (که ارزان نیست:
     *    `openssl` + یک ضربِ منحنیِ خودمان). جفتِ حل‌شده یک‌بار در کانتینرِ
     *    همان درخواست می‌نشیند.
     *
     * ۲. **ساختِ جفت اتمی است.** `GET push/public-key` **عمومی** است، پس دو
     *    درخواستِ اولِ هم‌زمان هر دو می‌توانستند جفت بسازند؛ هر تماس‌گیرنده یک
     *    کلید عمومیِ متفاوت می‌گرفت و «آخرین نویسنده» برنده می‌شد — یعنی
     *    بعضی مرورگرها تا ابد کلیدِ اشتباه نگه می‌داشتند و ۴۰۱ می‌خوردند،
     *    بی‌سروصدا. حالا ساخت داخل `DB::transaction()` با قفل انجام می‌شود و
     *    اگر تا لحظهٔ نوشتن جفتی وجود داشته باشد، **همان** برگردانده می‌شود.
     *
     * ۳. **کلیدِ خراب، خراب می‌ماند.** رمزگشاییِ ناموفق (مثلاً بعد از چرخشِ
     *    `APP_KEY`) دیگر به «بی‌صدا جفتِ تازه ساختن» تبدیل نمی‌شود، چون آن
     *    کار **همهٔ** اشتراک‌های موجود را بی‌اعتبار می‌کند. به‌جایش لاگ و
     *    استثنا.
     *
     * @return array{publicKey: string, privateKey: string}
     *
     * @throws \RuntimeException اگر کلیدِ خصوصیِ ذخیره‌شده قابل رمزگشایی نباشد
     */
    public static function get(): array
    {
        $memo = self::memo();

        if ($memo !== null) {
            return $memo;
        }

        $stored = self::resolveStoredPair();

        if ($stored !== null) {
            return self::remember($stored);
        }

        return self::remember(self::generateAtomically());
    }

    /**
     * جفتِ کلیدِ معتبرِ موجود در دیتابیس را برمی‌گرداند، یا `null`.
     *
     * `null` یعنی «باید ساخته شود» — نه «خراب است». خرابی با استثنا گزارش
     * می‌شود تا با نبودِ کلید اشتباه نشود.
     *
     * @return array{publicKey: string, privateKey: string}|null
     */
    private static function resolveStoredPair(): ?array
    {
        $publicKey = (string) self::setting(self::KEY_PUBLIC, '');
        $stored = (string) self::setting(self::KEY_PRIVATE, '');

        if ($publicKey === '' || $stored === '') {
            return null;
        }

        // ⚠️ این می‌تواند استثنا بدهد (رمزگشایی ناموفق) و **عمداً** همین کار را
        // می‌کند؛ `get()` نباید بعد از آن جفتِ تازه بسازد.
        $resolved = self::resolveStoredPrivateKey($stored);

        // کلیدِ خصوصیِ نصبِ قدیمی **رمزنشده** روی دیسک بود. حالا که خوانده
        // شد، همان را رمز می‌کنیم و **برمی‌گردانیم**.
        //
        // نسخهٔ قبل اینجا فقط `return $stored` می‌کرد، یعنی plaintext را
        // تحویل می‌داد و ذخیره‌اش دست‌نخورده می‌ماند ⇒ invariantِ «کلیدِ خصوصی
        // هیچ‌وقت رمزنشده نمی‌ماند» برای ردیف‌های قدیمی هیچ‌وقت برقرار نشد و
        // یک کپی از دیتابیس/بکاپ، کلیدِ امضای push را لو می‌داد.
        if ($resolved['plaintextAtRest']) {
            self::putSetting(self::KEY_PRIVATE, Crypt::encryptString($resolved['plaintext']));
        }

        $derived = self::publicKeyFor($resolved['plaintext']);

        // اگر کلیدِ خوانده‌شده با عمومیِ ذخیره‌شده جور نبود، ذخیرهٔ قبلی
        // ناسازگار است — یک جفتِ تازه می‌سازیم تا امضا اصلاً شکست نخورد.
        // (اشتراک‌های قبلی در این حالت بی‌اعتبار می‌شوند که با تکلیفِ خود
        // `get()` هم یکسان است.) این مسیر **لاگ** می‌شود چون تنها جایی است که
        // هنوز عمداً کلید را دور می‌ریزیم.
        if ($derived !== $publicKey) {
            Log::error('push vapid: کلید عمومی با کلید خصوصی جور نیست — جفتِ تازه ساخته می‌شود', [
                'stored_public_key' => substr($publicKey, 0, 12).'…',
                'derived_public_key' => substr($derived, 0, 12).'…',
                'hint' => 'همهٔ اشتراک‌های ذخیره‌شده با این جفت بی‌اعتبار می‌شوند.',
            ]);

            return null;
        }

        return ['publicKey' => $publicKey, 'privateKey' => $resolved['plaintext']];
    }

    /**
     * ساختِ جفتِ تازه، اتمی.
     *
     * ترتیب قفل‌ها عمدی است و **نباید** عوض شود:
     *
     *  ۱. `pg_advisory_xact_lock` — قفلِ سراسریِ تراکنشی. تنها قفلی که وقتی
     *     ردیف هنوز وجود ندارد هم کار می‌کند، پس رقابتِ «دو درخواستِ اولِ
     *     هم‌زمان» را می‌بندد.
     *  ۲. `lockForUpdate` روی خودِ ردیف‌ها — با ترتیبِ ثابت (`orderBy key`)
     *     تا دو فراخوان قفل‌ها را به یک ترتیب بگیرند و deadlock ندهند.
     *
     * قفلِ سراسری **اول** می‌آید چون اگر بعد از قفلِ سطری بیاید، فراخوانِ
     * دوم هم سطر را نگه می‌دارد و هم منتظر قفلِ سراسریِ اول می‌ماند ⇒ بن‌بست.
     *
     * بعد از گرفتن قفل، دوباره خوانده می‌شود: اگر فراخوانِ دیگری در این فاصله
     * جفت را ساخته باشد، **همان** برگردانده می‌شود و هیچ کلیدِ دومی ساخته
     * نمی‌شود.
     *
     * @return array{publicKey: string, privateKey: string}
     */
    private static function generateAtomically(): array
    {
        // آرگومانِ دوم: تلاشِ دوباره روی deadlock. رقابت روی همین دو ردیف
        // طبیعی است و باید بن‌بستِ گذرا تلقی شود، نه خطای سراسری.
        return DB::transaction(function (): array {
            self::acquireExclusiveLock();

            $existing = self::resolveStoredPair();

            if ($existing !== null) {
                return $existing;
            }

            $fresh = self::generate();

            self::putSetting(self::KEY_PUBLIC, $fresh['publicKey']);
            self::putSetting(self::KEY_PRIVATE, Crypt::encryptString($fresh['privateKey']));

            return $fresh;
        }, 3);
    }

    /** قفلِ سراسری + قفلِ سطری. باید **داخل** تراکنش صدا زده شود. */
    private static function acquireExclusiveLock(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::selectOne('select pg_advisory_xact_lock(?) as vapid_lock', [self::ADVISORY_LOCK_KEY]);
        }

        DB::table('settings')
            ->where('group', 'push')
            ->whereIn('key', [self::KEY_PUBLIC, self::KEY_PRIVATE])
            ->orderBy('key')
            ->lockForUpdate()
            ->get();
    }

    /**
     * کلیدِ خصوصیِ ذخیره‌شده را به شکل **رمزگشایی‌شده** برمی‌گرداند.
     *
     * نسخهٔ اول `get()` فقط `Crypt::encryptString()` را هنگام ذخیره صدا
     * می‌زد و بعد همان رشتهٔ ciphertext را به امضاکننده می‌داد؛
     * `openssl_pkey_get_private()` بی‌صدا `false` برمی‌گرداند و فقط
     * فراخوانیِ *دوم* و به بعد شکست می‌خورد. (تست‌ها هم هر بار کلید
     * تازه می‌ساختند و متوجه نمی‌شدند.)
     *
     * اینجا سه حالتِ ممکنِ ذخیره‌سازی را می‌پذیریم:
     *
     *  ۱. plaintext (نصبِ قدیمی که اصلاً رمز نمی‌کرد) — رمز می‌شود و
     *     **برگردانده می‌شود**
     *  ۲. ciphertext یک‌بار رمزشده (حالت فعلیِ درست)
     *  ۳. ciphertext دوباره رمزشده (اگر خواندنِ ناقص و ذخیرهٔ مجدد
     *     اتفاق افتاده باشد) — با یک decrypt دیگر به plaintext می‌رسیم
     *
     * ## چرا رمزگشاییِ ناموفق استثنا می‌دهد
     *
     * نسخهٔ قبل `catch (\Throwable) { return ''; }` داشت و `get()` آن رشتهٔ
     * خالی را «جفتِ ناسازگار» می‌خواند و یک جفتِ **تازه** می‌ساخت.
     *
     * یعنی یک `APP_KEY` عوض‌شده — یا حتی یک ردیفِ ناقص — کلیدِ امضا را عوض
     * می‌کرد و **تمامِ** اشتراک‌های ذخیره‌شده را بی‌اعتبار می‌کرد، بدونِ یک
     * خط در لاگ. این بدترین نوع خرابی است: نه اعلانی می‌رسد، نه کسی می‌فهمد
     * چرا. حالا `failLoudly()` لاگ می‌کند و استثنا می‌دهد؛ تصمیمِ «کلید را
     * دور بریزیم» باید آگاهانه و توسط اپراتور گرفته شود.
     *
     * @return array{plaintext: string, plaintextAtRest: bool}
     */
    private static function resolveStoredPrivateKey(string $stored): array
    {
        if (self::looksLikeBase64PrivateKey($stored)) {
            return ['plaintext' => $stored, 'plaintextAtRest' => true];
        }

        $once = self::decryptOrFail($stored, 'first');

        if (self::looksLikeBase64PrivateKey($once)) {
            return ['plaintext' => $once, 'plaintextAtRest' => false];
        }

        // رمزگشاییِ اول **موفق** بوده ولی آنچه بیرون آمده کلید نبوده ⇒ این
        // ردیف خراب است، نه مشکلِ `APP_KEY`. (حالتِ «دوباره رمز شده».)
        $twice = self::decryptOrFail($once, 'nested');

        if (self::looksLikeBase64PrivateKey($twice)) {
            return ['plaintext' => $twice, 'plaintextAtRest' => false];
        }

        self::failLoudly('رمزگشایی موفق بود ولی محتوا یک کلید خصوصی VAPID نبود (ردیف خراب)');
    }

    /**
     * رمزگشایی، یا شکستِ بلند.
     *
     * `$stage` علتِ محتمل را جدا می‌کند تا اپراتور بداند دنبال چه بگردد:
     * `first` ⇒ تقریباً همیشه `APP_KEY` عوض شده؛ `nested` ⇒ ردیف خراب.
     */
    private static function decryptOrFail(string $payload, string $stage): string
    {
        try {
            return Crypt::decryptString($payload);
        } catch (\Throwable $e) {
            self::failLoudly(
                $stage === 'first'
                    ? 'رمزگشاییِ کلیدِ خصوصیِ ذخیره‌شده ناموفق بود (به احتمال زیاد APP_KEY عوض شده)'
                    : 'رمزگشاییِ دوم ناموفق بود (ردیفِ تنظیمات خراب است)',
                $e,
            );
        }
    }

    /**
     * گزارشِ خرابیِ کلیدِ خصوصی و توقفِ سرویس push.
     *
     * ⚠️ **هیچ مادهٔ محرمانه‌ای در لاگ نمی‌رود**: نه ciphertext، نه کلیدِ
     * خصوصی. فقط نامِ تنظیم، مرحله و *کلاسِ* استثنا — و پیامِ استثنا هم عمداً
     * حذف شده، چون قراردادش «متنِ ثابت» است و تکیه بر آن برای نشت‌نکردن
     * ریسکِ بی‌فایده‌ای می‌افزاید.
     *
     * @throws \RuntimeException همیشه
     */
    private static function failLoudly(string $reason, ?\Throwable $e = null): never
    {
        Log::error('push vapid: کلید خصوصیِ ذخیره‌شده قابل بازیابی نیست — عمداً جفتِ تازه ساخته نشد', [
            'setting' => 'push/'.self::KEY_PRIVATE,
            'reason' => $reason,
            'exception' => $e === null ? null : $e::class,
            'hint' => 'بازتولیدِ خودکار، همهٔ اشتراک‌های موجود را بی‌اعتبار می‌کرد؛ APP_KEY را برگردانید یا اشتراک‌ها را دوباره ثبت کنید.',
        ]);

        throw new \RuntimeException(
            'کلید خصوصی VAPID ذخیره‌شده قابل رمزگشایی نیست؛ عمداً جفتِ تازه ساخته نشد.'
        );
    }

    /**
     * جفتِ حل‌شده برای عمرِ همین درخواست/پروسه.
     *
     * @return array{publicKey: string, privateKey: string}|null
     */
    private static function memo(): ?array
    {
        $container = Container::getInstance();

        if (! $container->bound(self::MEMO_BINDING)) {
            return null;
        }

        $memo = $container->make(self::MEMO_BINDING);

        return is_array($memo) ? $memo : null;
    }

    /**
     * @param  array{publicKey: string, privateKey: string}  $keys
     * @return array{publicKey: string, privateKey: string}
     */
    private static function remember(array $keys): array
    {
        Container::getInstance()->instance(self::MEMO_BINDING, $keys);

        return $keys;
    }

    /**
     * حافظهٔ کلید را پاک می‌کند.
     *
     * ⚠️ فقط برای پروسه‌های بلندمدت لازم است (Octane، worker صف) که در آن‌ها
     * یک نصب **در میانهٔ کار** کلیدش عوض می‌شود. در چرخهٔ عادیِ درخواست لازم
     * نیست، چون کانتینر هر بار تازه ساخته می‌شود.
     */
    public static function flush(): void
    {
        Container::getInstance()->forgetInstance(self::MEMO_BINDING);
    }

    /**
     * کلیدِ خصوصیِ P-256 به‌صورت DER/base64 حدود ۱۳۸ کاراکتر است و فقط
     * از `A–Z a–z 0–9 + / =` ساخته می‌شود؛ ciphertext لاراول base64
     * طولانی‌تر و معمولاً شامل `_` و `-` دارد. این تفکیک، تشخیصِ
     * «رمز شده» از «رمز نشده» است.
     */
    private static function looksLikeBase64PrivateKey(string $value): bool
    {
        return $value !== ''
            && strlen($value) < 200
            && preg_match('/^[A-Za-z0-9+\/=]+$/', $value) === 1;
    }

    public static function exists(): bool
    {
        return (string) self::setting(self::KEY_PUBLIC, '') !== '';
    }

    /**
     * یک جفت‌کلید تازه روی P-256.
     *
     * @return array{publicKey: string, privateKey: string}
     */
    public static function generate(): array
    {
        $resource = openssl_pkey_new([
            'curve_name' => self::CURVE,
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($resource === false) {
            throw new \RuntimeException('ساخت کلید VAPID ناموفق بود: '.openssl_error_string());
        }

        // ⚠️ کلید خصوصی را **صریح** export کن. `$details['key']` روی
        // OpenSSL 3.x یک PEM با هدر `BEGIN PUBLIC KEY` برمی‌گرداند، نه
        // خصوصی؛ اگر همان را نگه داریم، امضای ES256 همیشه شکست می‌خورد.
        $privatePem = '';
        openssl_pkey_export($resource, $privatePem);

        if ($privatePem === '' || ! str_contains($privatePem, 'PRIVATE KEY')) {
            throw new \RuntimeException('خواندن کلید خصوصی VAPID ناموفق بود.');
        }

        $details = openssl_pkey_get_details($resource);

        if ($details === false || ! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new \RuntimeException('خواندن کلید عمومی VAPID ناموفق بود.');
        }


        // نقطهٔ عمومی غیرفشرده: `0x04 ‖ x(32) ‖ y(32)`
        $x = self::padLeft($details['ec']['x']);
        $y = self::padLeft($details['ec']['y']);

        $publicKey = Ecdh::b64u("\x04".$x.$y);

        // ‏راستی‌آزمایی: اسکالرِ همین کلید باید همان نقطهٔ عمومی را بدهد.
        // اگر ناهماهنگ باشند، JWTهای ما امضا می‌شوند ولی سرویس push
        // کلید را نمی‌شناسد — خطایی که فقط در زمان اجرا خودش را نشان می‌دهد.
        self::assertScalarMatchesPoint($privatePem, "\x04".$x.$y);

        return [
            'publicKey' => $publicKey,
            'privateKey' => self::pemToDer($privatePem),
        ];
    }

    /**
     * اسکالرِ ۳۲ بایتی را از کلید خصوصیِ ذخیره‌شده بیرون می‌دهد، به شکل
     * base64url — همان قالبی که `Ecdh` می‌شناسد.
 *
     * عمداً عمومی است تا بتوان در تست ثابت کرد که کلید عمومی واقعاً از
     * اسکالرِ کلید خصوصی به‌دست می‌آید.
 */
    public static function base64ToScalar(string $b64Der): string
    {
        $der = base64_decode(trim($b64Der), true);

        if ($der === false) {
            throw new \RuntimeException('کلید خصوصی VAPID قابل خواندن نبود.');
        }

        $needle = "\x04".chr(self::SCALAR_SIZE);
        $at = strpos($der, $needle);

        if ($at === false) {
            throw new \RuntimeException('اسکالر در کلید خصوصی پیدا نشد.');
        }

        return Ecdh::b64u(substr($der, $at + 2, self::SCALAR_SIZE));
    }

    /**
     * کلید عمومیِ متناظر با یک کلید خصوصیِ ذخیره‌شده.
     *
     * برای این است که `get()` بتواند تشخیص دهد جفتِ کلیدِ داخل دیتابیس
     * با هم جور است یا نه: اگر عمومیِ ذخیره‌شده با عمومیِ محاسبه‌شده از
     * خصوصی نخواند، یکی از آن‌ها دستکاری/ناقص است و باید جفت تازه ساخته شود.
     *
     * @return string base64url نقطهٔ فشردهٔ ۶۵ بایتی ( uncompressed )
     */
    private static function publicKeyFor(string $privateKey): string
    {
        try {
            return Ecdh::publicFromPrivate(self::base64ToScalar($privateKey));
        } catch (\Throwable) {
            // کلید خصوصی خراب است ⇒ جفت ناسازگار تلقی می‌شود.
            return '';
        }
    }

    /**
     * هدر `Authorization` برای یک درخواست push (RFC 8292 §2).
     *
     * JWT عمرِ کوتاهی دارد (۱۲ ساعت) چون سرور push تاریخ انقضا را چک می‌کند.
     */
    public static function authorizationHeader(string $audience, string $subject): string
    {
        ['publicKey' => $publicKey, 'privateKey' => $privateKey] = self::get();

        $header = ['typ' => 'JWT', 'alg' => 'ES256'];
        $payload = [
            'aud' => $audience,
            'exp' => time() + 12 * 3600,
            'sub' => $subject,
        ];

        $signingInput = Ecdh::b64u((string) json_encode($header, JSON_UNESCAPED_SLASHES))
            .'.'.Ecdh::b64u((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        return 'vapid t='.$signingInput.'.'.self::signEs256($signingInput, $privateKey).',k='.$publicKey;
    }

    /**
     * امضای ES256 (ECDSA + SHA-256).
     *
     * عمومی است تا تست بتواند **همین** مسیر تولیدی را بسنجد، نه یک
     * بازسازیِ موازی که ممکن است باگِ کد را بازتولید نکند.
     *
     * ## نکتهٔ حیاتی
     *
     * `openssl_sign` خروجیِ **DER** می‌دهد: `SEQUENCE { r, s }`.
     * ولی JWS نسخهٔ ES256 دو عددِ **خامِ ۳۲ بایتی** `r ‖ s` می‌خواهد.
     *
     * تفاوت این دو در حدود ۲۰٪ موارد خودش را نشان می‌دهد — یعنی یک باگِ
     * کاملاً بی‌سروصدا که فقط گاهی JWT را نامعتبر می‌کند.
     *
     * ‎⚠️ در نسخهٔ اولِ این کد، `s` اصلاً به امضا اضافه نمی‌شد — فقط `r`.
     * یعنی امضا **همیشه** غلط بود و هیچ تستی هم نبود که بگیرد.
     */
    public static function signEs256(string $input, string $b64Der): string
    {
        // ‎⚠️ این DER **base64 معمولی** است (همان چیزی که PEM دارد)، نه
        // base64url. اگر با `b64d()` بخوانیم، base64url به base64 معمولی
        // برنمی‌گردد و `openssl_pkey_get_private` بی‌صدا `false` می‌دهد.
        $key = openssl_pkey_get_private(self::derToPem($b64Der));

        if ($key === false) {
            throw new \RuntimeException('کلید خصوصی VAPID قابل خواندن نبود.');
        }

        $signature = '';

        if (! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('امضای VAPID ناموفق بود.');
        }


        [$r, $s] = self::derToRawSignature($signature);

        return Ecdh::b64u(self::padLeft($r).self::padLeft($s));
    }

    /**
     * ‎`SEQUENCE { INTEGER r, INTEGER s }` ⇒ `[r, s]`.
     *
     * دستی نوشته شده چون API ساده‌ای برای استخراجِ r/s از DER در `openssl`
     * وجود ندارد و بک‌دیپ‌بندی یک ASN.1 کامل برای همین یک ساختار،
     * پرخطرتر است.
     *
     * @return array{0: string, 1: string}
     */
    private static function derToRawSignature(string $der): array
    {
        $offset = 0;

        self::expectTag($der, $offset, 0x30);
        $offset = self::skipLength($der, $offset);

        self::expectTag($der, $offset, 0x02);
        $rLength = self::readLength($der, $offset);
        $offset = self::skipLength($der, $offset);
        $r = substr($der, $offset, $rLength);
        $offset += $rLength;

        self::expectTag($der, $offset, 0x02);
        $sLength = self::readLength($der, $offset);
        $offset = self::skipLength($der, $offset);
        $s = substr($der, $offset, $sLength);

        // ASN.1 INTEGER علامت‌دار است: بیتِ بالا باید صفر باشد، وگرنه یک
        // بایت `0x00` جلوش گذاشته شده. `padLeft` هر دو حالت را پوشش می‌دهد.
        return [ltrim($r, "\x00"), ltrim($s, "\x00")];
    }

    private static function expectTag(string $der, int $offset, int $tag): void
    {
        if (($der[$offset] ?? null) !== chr($tag)) {
            throw new \RuntimeException('ساختار امضای ECDSA نامعتبر است (DER).');
        }
    }

    private static function readLength(string $der, int $offset): int
    {
        $byte = ord($der[$offset + 1] ?? "\x00");

        if ($byte < 0x80) {
            return $byte;
        }

        $count = $byte & 0x7F;
        $length = 0;

        for ($i = 0; $i < $count; $i++) {
            $length = ($length << 8) | ord($der[$offset + 2 + $i] ?? "\x00");
        }

        return $length;
    }

    private static function skipLength(string $der, int $offset): int
    {
        $byte = ord($der[$offset + 1] ?? "\x00");

        if ($byte < 0x80) {
            return $offset + 2;
        }

        return $offset + 2 + ($byte & 0x7F);
    }

    /**
     * راستی‌آزمایی: اسکالرِ کلید خصوصی باید همان نقطهٔ عمومی را تولید کند.
     *
     * اگر روزی `openssl` و `Curve` نمایش متفاوتی از یک کلید بدهند، اینجا
     * لو می‌رود — به‌جای اینکه در تولید، هزاران اعلان با خطای ۴۰۱ بشکند.
     */
    private static function assertScalarMatchesPoint(string $privatePem, string $expectedPublicPoint): void
    {
        $scalar = self::scalarFromPem($privatePem);

        $computed = Curve::multiply(
            Curve::generator(),
            \App\Services\Push\BigNum::of('0x'.bin2hex($scalar)),
        );

        if ($computed->isInfinity() || Ecdh::encodePoint($computed) !== $expectedPublicPoint) {
            throw new \RuntimeException('نقطهٔ عمومی VAPID با کلید خصوصی هم‌خوانی ندارد.');
        }
    }

    /** اسکالر ۳۲ بایتی را از PEM کلید خصوصی PKCS#8 بیرون می‌کشد. */
    private static function scalarFromPem(string $pem): string
    {
        $lines = preg_split('/\R/', trim($pem)) ?: [];
        $body = '';

        foreach ($lines as $line) {
            if (! str_starts_with(trim($line), '-----')) {
                $body .= trim($line);
            }
        }

        $der = base64_decode($body, true);

        if ($der === false) {
            throw new \RuntimeException('PEM کلید خصوصی قابل خواندن نبود.');
        }

        // ‎`MIGHAgEAMBMGByq...` ⇒ ساختار `04 20 <32 بایت>` در PKCS#8.
        $needle = "\x04".chr(self::SCALAR_SIZE);
        $at = strpos($der, $needle);

        if ($at === false) {
            throw new \RuntimeException('اسکالر در کلید خصوصی پیدا نشد.');
        }

        $scalar = substr($der, $at + 2, self::SCALAR_SIZE);

        if (strlen($scalar) !== self::SCALAR_SIZE) {
            throw new \RuntimeException('اسکالر کلید خصوصی ناقص است.');
        }

        return $scalar;
    }

    private static function padLeft(string $binary): string
    {
        return str_pad(substr($binary, -self::SCALAR_SIZE), self::SCALAR_SIZE, "\x00", STR_PAD_LEFT);
    }

    /**
     * PEM ⇒ base64 **معمولی** (نه base64url).
     *
     * ‎⚠️ این با `Ecdh::b64u()` فرق دارد. PEM خودش base64 استاندارد دارد و
     * تبدیلش به base64url بعداً قابل برگشت نیست مگر اینکه دوباره به شکل
     * PEM دربیاید — که `authorizationHeader` همین کار را می‌کند.
     */
    private static function pemToDer(string $pem): string
    {
        $lines = preg_split('/\R/', trim($pem)) ?: [];
        $body = '';

        foreach ($lines as $line) {
            if (! str_starts_with(trim($line), '-----')) {
                $body .= trim($line);
            }
        }

        return $body;
    }

    private static function derToPem(string $b64): string
    {
        $body = chunk_split($b64, 64, "\n");

        return "-----BEGIN PRIVATE KEY-----\n{$body}-----END PRIVATE KEY-----\n";
    }

    /**
 * خواندن یک تنظیم از گروه `push`.
 *
 * ‎⚠️ ستون `settings.value` نوع **JSON** دارد (مشترکِ همهٔ تنظیمات سایت)،
 * پس مقدار همیشه باید JSON-encoded ذخیره و JSON-decoded خوانده شود.
 * ذخیرهٔ متنِ خام باعث `invalid input syntax for type json` می‌شود.
 */
private static function setting(string $key, ?string $default = null): ?string
    {
        $raw = DB::table('settings')
            ->where('group', 'push')
            ->where('key', $key)
            ->value('value');

        if ($raw === null) {
            return $default;
        }

        // اگر از قبل به‌صورت JSON نوشته شده بود، برش می‌داریم؛ در غیر این
        // صورت همان رشتهٔ خام است.
        if (is_string($raw) && str_starts_with(trim($raw), '"')) {
            $decoded = json_decode($raw, true);

            return is_string($decoded) ? $decoded : $default;
        }

        return (string) $raw;
    }

    private static function putSetting(string $key, string $value): void
    {
        DB::table('settings')->updateOrInsert(
            ['group' => 'push', 'key' => $key],
            [
                // ‏JSON encode لازم است — ستون `value` از نوع json است.
                'value' => json_encode($value, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ],
        );
    }
}