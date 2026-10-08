<?php

namespace Tests\Unit;

use App\Services\Push\BigNum;
use App\Services\Push\Curve;
use App\Services\Push\Ecdh;
use App\Services\Push\Point;
use App\Services\Push\PushEncryptor;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

/**
 * تست round-trip رمزنگاری Web Push.
 *
 * ## چرا رمزگشایی را دستی نوشته‌ایم
 *
 * کتابخانه‌های «تقریباً درست» Web Push وجود دارند که در تست خودشان پاس
 * می‌شوند ولی سرویس push ردشان می‌کند. اینجا دو پیاده‌سازیِ **مستقل** روبه‌روی
 * هم قرار می‌گیرند:
 *
 * • `PushEncryptor` — آنچه سرور ما می‌نویسد.
 * • `decryptAsBrowser` — آنچه کتابخانهٔ سمت مرورگر می‌خواند.
 *
 * اگر این دو روی هم بخوانند، هر دو باید با RFC 8291 یکی باشند.
 *
 * ## ⚠️ دامِ «تست دایره‌ای»
 *
 * نسخهٔ اولِ این فایل **حلقوی** بود: هر دو طرف — سرور و «مرورگرِ تقلیدی» —
 * یک اشتباهِ یکسان را تکرار می‌کردند، پس تست سبز می‌شد در حالی که مرورگر
 * واقعی پیام را دور می‌ریخت. نمونه‌اش همین جداکنندهٔ `0x02` بود: نه رمزکننده
 * آن را اضافه می‌کرد، نه رمزگشاییِ تقلیدی چکش می‌کرد.
 *
 * پس `decryptAsBrowser()` حالا جداکننده را **الزامی** می‌کند (RFC 8291 §4) و
 * تستِ منفیِ `test_a_body_without_the_delimiter_is_rejected` همان باگ را
 * دوباره می‌سازد تا مطمئن شویم باز هم گرفته می‌شود.
 */
class PushEncryptionRoundTripTest extends TestCase
{
    /** جداکنندهٔ padding (RFC 8291 §4). */
    private const DELIMITER = "\x02";

    public function test_it_round_trips_a_payload_with_keys(): void
    {
        $browser = $this->makeBrowserKeyPair();

        $payload = [
            'title' => 'محتوای تازه منتشر شد',
            'body' => 'صفحهٔ «قوانین و مقررات» به‌روزرسانی شد.',
            'url' => '/legal/rules',
        ];

        $result = PushEncryptor::encrypt($payload, $browser['public'], $browser['auth']);

        $this->assertSame('aes128gcm', $result['headers']['Content-Encoding']);

        // ⚠️ برای `aes128gcm` نباید هدر `Encryption` بفرستیم: salt خودش
        // ۱۶ بایت اولِ body است و آن هدر متعلق به قالبِ کهنِ `aes128` بود.
        $this->assertArrayNotHasKey(
            'Encryption',
            $result['headers'],
            'هدر Encryption برای aes128gcm اضافه و بی‌معناست؛ salt داخل body است.',
        );

        // متن اصلی نباید خوانا باشد
        $this->assertStringNotContainsString('منتشر', $result['body']);
        $this->assertStringNotContainsString('rules', $result['body']);

        $decrypted = $this->decryptAsBrowser($result, $browser);

        $this->assertSame($payload, json_decode($decrypted, true));
    }

    /** ‎`salt ‖ rs ‖ idlen ‖ key ‖ ciphertext ‖ tag` */
    public function test_body_layout_matches_the_specification(): void
    {
        $browser = $this->makeBrowserKeyPair();

        $body = PushEncryptor::encrypt(['a' => 1], $browser['public'], $browser['auth'])['body'];

        $this->assertSame(pack('N', 4096), substr($body, 16, 4), 'record size باید ۴۰۹۶ باشد');
        $this->assertSame(65, ord($body[20]), 'idlen باید ۶۵ باشد');
        $this->assertSame("\x04", $body[21], 'کلید عمومی باید غیرفشرده باشد');

        // ‎rs **قبل** از داده می‌آید.
        $this->assertSame(pack('N', 4096), substr($body, 16, 4));

        // salt = ۱۶ بایت اولِ body (به همین دلیل هدر `Encryption` لازم نیست).
        $this->assertSame(16, strlen(substr($body, 0, 16)));

        $this->assertLessThanOrEqual(4096, strlen($body), 'record نباید از ۴۰۹۶ بایت بگذرد');
    }

    public function test_salt_makes_every_message_unique(): void
    {
        $browser = $this->makeBrowserKeyPair();

        $first = PushEncryptor::encrypt(['x' => 1], $browser['public'], $browser['auth']);
        $second = PushEncryptor::encrypt(['x' => 1], $browser['public'], $browser['auth']);

        $this->assertNotSame($first['body'], $second['body']);

        // salt از ۱۶ بایت اولِ body خوانده می‌شود، نه از هدر.
        $this->assertNotSame(
            substr($first['body'], 0, 16),
            substr($second['body'], 0, 16),
        );
    }

    /**
     * ⚠️ رگرسیون: بدون کلیدِ گیرنده، **هیچ هدر رمزنگاری** نباید فرستاده
     * شود.
     *
     * نسخهٔ اول `encryptWithoutKeys()` هدر `Content-Encoding: aes128gcm`
     * می‌گذاشت در حالی که بدنه رمزنشده بود. سرویس push به هدر اعتماد
     * می‌کند و decrypt شکست می‌خورد ⇒ پیام هرگز نمی‌رسد، ولی ما
     * status 201 می‌گیریم و فکر می‌کنیم موفق بوده.
     */
    public function test_missing_recipient_keys_produce_no_encryption_headers(): void
    {
        $result = PushEncryptor::encrypt(['title' => 'سلام'], null, null);

        $this->assertArrayNotHasKey('Content-Encoding', $result['headers']);
        $this->assertArrayNotHasKey('Encryption', $result['headers']);

        // بدنه باید همان JSON خام باشد.
        $this->assertSame(['title' => 'سلام'], json_decode($result['body'], true));
    }

    /**
     * کلید ناقص (فقط یکی از دو) هم باید همان مسیر امن را برود، نه اینکه
     * یکی را به‌عنوان base64url بی‌معنا جا بزند.
     */
    public function test_half_present_keys_do_not_produce_encryption_headers(): void
    {
        $browser = $this->makeBrowserKeyPair();

        foreach ([[null, $browser['auth']], [$browser['public'], null], [null, null]] as $pair) {
            $result = PushEncryptor::encrypt(['a' => 1], $pair[0], $pair[1]);

            $this->assertArrayNotHasKey(
                'Content-Encoding',
                $result['headers'],
                'کلید ناقص نباید به رمزنگاری ناقص منجر شود'
            );
        }
    }

    public function test_unicode_survives_the_round_trip(): void
    {
        $browser = $this->makeBrowserKeyPair();

        $payload = ['title' => 'خوش‌آمد 👋 به پیشداد — قیمت‌گذاری'];

        $result = PushEncryptor::encrypt($payload, $browser['public'], $browser['auth']);

        $this->assertSame(
            $payload,
            json_decode($this->decryptAsBrowser($result, $browser), true),
        );
    }

    /**
     * ⚠️ این تست قبلاً برعکسِ رفتارِ درست را تثبیت کرده بود.
 *
     * نسخهٔ اولِ این تست انتظار داشت `Content-Encoding: aes128gcm` حتی
     * وقتی بدنه رمزنشده است — یعنی **باگ را قفل کرده بود**. هدر
     * `aes128gcm` به سرویس push می‌گوید «این بدنه رمز است»، پس decrypt
     * شکست می‌خورد و پیام هرگز نمی‌رسد؛ در حالی که ما status 201
     * می‌گیریم و فکر می‌کنیم ارسال موفق بوده.
 *
 * طبق RFC 8291 §5.2 مسیر بدون کلید باید **کاملاً بدون هدر رمزنگاری** باشد.
 */
public function test_subscription_without_keys_falls_back_to_plain_body(): void
    {
        $payload = ['title' => 'بدون کلید'];

        $result = PushEncryptor::encrypt($payload, null, null);

        $this->assertArrayNotHasKey('Content-Encoding', $result['headers']);
        $this->assertArrayNotHasKey('Encryption', $result['headers']);
        $this->assertSame(json_encode($payload, JSON_UNESCAPED_UNICODE), $result['body']);
    }

    public function test_empty_keys_are_treated_as_missing(): void
    {
        $result = PushEncryptor::encrypt(['a' => 1], '', '');

        $this->assertArrayNotHasKey('Encryption', $result['headers']);
    }

    public function test_malformed_recipient_key_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PushEncryptor::encrypt(['a' => 1], Ecdh::b64u('too-short'), Ecdh::b64u(str_repeat("\x01", 16)));
    }

    public function test_oversized_message_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $browser = $this->makeBrowserKeyPair();

        PushEncryptor::encrypt(['body' => str_repeat('ا', 5000)], $browser['public'], $browser['auth']);
    }

    /**
     * ⚠️ مرزِ دقیقِ اندازه: ‎`4096 − 86(header) − 16(tag) − 1(delimiter) = 3993`.
     *
     * نسخهٔ اول این `- 1` را نداشت، پس متنی که دقیقاً در مرز جا می‌شد
     * record را از ۴۰۹۶ بایت بیرون می‌زد و سرویس push کل بدنه را رد می‌کرد.
     */
    public function test_size_guard_accounts_for_the_padding_delimiter(): void
    {
        $browser = $this->makeBrowserKeyPair();

        // ‎`{"body":"…"}` یعنی ۱۱ بایت قالب + محتوا. این assert خودِ حساب را
        // نگه می‌دارد تا اگر روزی قالب عوض شد، تست خودش توضیح بدهد.
        $atLimitJson = str_repeat('a', 3993 - 11);
        $this->assertSame(3993, strlen((string) json_encode(
            ['body' => $atLimitJson],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        )));

        $atLimit = PushEncryptor::encrypt(['body' => $atLimitJson], $browser['public'], $browser['auth']);

        $this->assertLessThanOrEqual(4096, strlen($atLimit['body']));
        $this->assertSame(
            ['body' => $atLimitJson],
            json_decode($this->decryptAsBrowser($atLimit, $browser), true),
            'متنِ دقیقاً روی مرز باید رمز/رمزگشایی شود.',
        );

        $this->expectException(\InvalidArgumentException::class);

        PushEncryptor::encrypt(['body' => str_repeat('a', 3994 - 11)], $browser['public'], $browser['auth']);
    }

    /**
     * ⚠️ رگرسیونِ اصلی: بدنه‌ای که جداکنندهٔ `0x02` ندارد **نباید** معتبر
     * تلقی شود.
     *
     * این همان باگِ نسخهٔ قبلی است: رمزنگاری درست بود ولی بدنه به `0x02` ختم
     * نمی‌شد، و رمزگشاییِ تقلیدی هم آن را چک نمی‌کرد ⇒ تست سبز، پیام‌ها مرده.
     * مرورگر واقعی (RFC 8291 §4) چنین متنی را دور می‌ریزد، پس تست هم باید
     * دورش بدهد.
     */
    public function test_a_body_without_the_delimiter_is_rejected(): void
    {
        $browser = $this->makeBrowserKeyPair();

        $result = PushEncryptor::encrypt(['title' => 'بدون جداکننده'], $browser['public'], $browser['auth']);

        // همان رمزنگاری، ولی **بدون** بایت جداکننده = باگ نسخهٔ قبلی.
        $buggy = $this->forgeBodyWithoutDelimiter($result, $browser);

        $raw = $this->decryptRawAsBrowser($buggy, $browser);

        $this->assertNotFalse($raw, 'پیش از بررسیِ جداکننده باید رمزگشایی موفق باشد');
        $this->assertNotSame(
            "\x02",
            substr($raw, -1),
            'این بدنه عمداً جداکننده ندارد؛ اگر دارد یعنی forge خراب است.',
        );
        $this->assertFalse($this->hasPaddingDelimiter($raw));

        // حالا همان چیزی که مرورگر واقعی می‌کند: دور انداختنِ پیام.
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('جداکنندهٔ padding');

        $this->decryptAsBrowser($buggy, $browser);
    }

    /**
     * رمزگشایی، از دیدِ کتابخانهٔ سمت مرورگر — **همان کاری که مرورگر واقعی
     * می‌کند، با همان سخت‌گیری**.
     *
     * تفاوتِ حیاتی با `decryptRawAsBrowser()`: اینجا جداکنندهٔ `0x02` چک و
     * حذف می‌شود (RFC 8291 §4). بدون این assert، سرور و تست هر دو می‌توانستند
     * یک اشتباهِ یکسان را مرتکب شوند و تست باز هم سبز شود.
     */
    private function decryptAsBrowser(array $result, array $browser): string
    {
        $plain = $this->decryptRawAsBrowser($result, $browser);

        $this->assertTrue(
            $this->hasPaddingDelimiter($plain),
            'متنِ رمزگشایی‌شده باید به جداکنندهٔ padding (0x02) ختم شود؛ بدون آن مرورگر پیام را دور می‌ریزد.',
        );

        return substr($plain, 0, -1);
    }

    /** آیا متن به بایت جداکنندهٔ RFC 8291 ختم می‌شود؟ */
    private function hasPaddingDelimiter(string $plain): bool
    {
        return $plain !== '' && str_ends_with($plain, self::DELIMITER);
    }

    /**
     * رمزگشاییِ خام — بدون هیچ بررسیِ جداکننده.
     *
     * فقط برای ساختنِ fixtureِ تستِ منفی و برای اثباتِ اینکه رمزگشایی خودش سالم
     * است. مسیرِ اصلیِ تست‌ها `decryptAsBrowser()` است.
     */
    private function decryptRawAsBrowser(array $result, array $browser): string
    {
        $body = $result['body'];

        // salt در `aes128gcm` **از خودِ body** خوانده می‌شود: ۱۶ بایت اول.
        $salt = substr($body, 0, 16);

        $rs = substr($body, 16, 4);
        $idlen = ord($body[20]);
        $senderPublic = substr($body, 21, $idlen);
        $ciphertextAndTag = substr($body, 21 + $idlen);

        $this->assertSame(16, strlen($salt));
        $this->assertSame(pack('N', 4096), $rs);
        $this->assertSame(65, $idlen);

        // ECDH: کلید خصوصیِ مرورگر × کلید عمومیِ فرستنده
        $secret = Ecdh::sharedSecret(
            $browser['private'],
            $senderPublic,
        );

        $uaPublic = Ecdh::encodePoint($browser['point']);

        // ⚠️ `auth` در ورودی به‌صورت base64url می‌آید (همان چیزی که مرورگر
        // می‌فرستد)، ولی HKDF با **بایت خام** کار می‌کند. استفاده از شکل
        // رمز‌شده بی‌صدا کلید و nonce را غلط می‌سازد و رمزگشایی شکست می‌خورد.
        $authSecret = Ecdh::b64d($browser['auth']);

        $prkKey = hash_hmac('sha256', $secret, $authSecret, true);

        $keyInfo = "WebPush: info\x00".$uaPublic.$senderPublic;

        $ikm = hash_hmac('sha256', $keyInfo."\x01", $prkKey, true);

        $prk = hash_hmac('sha256', $ikm, $salt, true);

        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01", $prk, true), 0, 12);

        $aad = 'PUSH'.$rs.chr($idlen).$senderPublic;

        // ⚠️ تگ باید **by-reference** پاس داده شود، نه به‌عنوان آرگومان
        // هشتم — `openssl_decrypt` حداکثر ۷ آرگومان می‌گیرد وگرنه
        // `ArgumentCountError` می‌دهد.
        $receivedTag = substr($ciphertextAndTag, -16);

        $plain = openssl_decrypt(
            substr($ciphertextAndTag, 0, -16),
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $receivedTag,
            $aad,
        );

        $this->assertNotFalse($plain, 'رمزگشایی باید موفق باشد');

        return $plain;
    }

    /**
     * همان بدنه را **بدون** بایت جداکننده می‌سازد — بازتولیدِ باگ نسخهٔ قبلی.
     *
     * salt و کلیدها را دست نمی‌زنیم (فقط ciphertext عوض می‌شود)، پس تنها
     * تفاوت با بدنهٔ درست، همان یک بایتِ جداکننده است. هر چیزِ دیگری که خراب
     * باشد، در تستِ منفی گم می‌شود — برای همین `decryptRawAsBrowser` را اول
     * صدا می‌زنیم تا مطمئن شویم رمزگشایی سالم است.
     */
    private function forgeBodyWithoutDelimiter(array $result, array $browser): array
    {
        $body = $result['body'];
        $idlen = ord($body[20]);
        $headerLength = 21 + $idlen;

        $plain = $this->decryptRawAsBrowser($result, $browser);

        $this->assertTrue(
            $this->hasPaddingDelimiter($plain),
            'بدنهٔ اصلی باید جداکننده داشته باشد تا بتوانیم باگ را شبیه‌سازی کنیم.',
        );

        // جداکننده را بردار ⇒ متنِ رمزشدهٔ نسخهٔ باگ‌دار.
        $buggyPlain = substr($plain, 0, -1);

        $salt = substr($body, 0, 16);
        $rs = substr($body, 16, 4);
        $senderPublic = substr($body, 21, $idlen);

        $cek = $this->deriveCek($salt, $rs, $senderPublic, $browser);
        $nonce = $this->deriveNonce($salt, $rs, $senderPublic, $browser);

        $ciphertext = openssl_encrypt(
            $buggyPlain,
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            'PUSH'.$rs.chr($idlen).$senderPublic,
            16,
        );

        $this->assertNotFalse($ciphertext, 'ساختِ fixtureِ باگ‌دار نباید شکست بخورد');

        $result['body'] = substr($body, 0, $headerLength).$ciphertext.$tag;

        return $result;
    }

    /** ‎`Content-Encoding: aes128gcm` ⇒ گامِ HKDF شمارهٔ ۱. */
    private function deriveCek(string $salt, string $rs, string $senderPublic, array $browser): string
    {
        return substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $this->derivePrk($salt, $rs, $senderPublic, $browser), true), 0, 16);
    }

    /** ‎`Content-Encoding: nonce` ⇒ گامِ HKDF شمارهٔ ۲. */
    private function deriveNonce(string $salt, string $rs, string $senderPublic, array $browser): string
    {
        return substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01", $this->derivePrk($salt, $rs, $senderPublic, $browser), true), 0, 12);
    }

    /** ‎HKDF ‎(RFC 8291 §3.4)، سمت گیرنده. */
    private function derivePrk(string $salt, string $rs, string $senderPublic, array $browser): string
    {
        $secret = Ecdh::sharedSecret($browser['private'], $senderPublic);

        $prkKey = hash_hmac('sha256', $secret, Ecdh::b64d($browser['auth']), true);

        $keyInfo = "WebPush: info\x00".Ecdh::encodePoint($browser['point']).$senderPublic;

        $ikm = hash_hmac('sha256', $keyInfo."\x01", $prkKey, true);

        return hash_hmac('sha256', $ikm, $salt, true);
    }

    /**
     * نقشِ مرورگر را بازی می‌کند: کلید عمومی، کلید خصوصی، و `auth`.
     *
     * @return array{public: string, auth: string, point: Point, private: string}
     */
    private function makeBrowserKeyPair(): array
    {
        $pair = Ecdh::generateKeyPair();

        return [
            'public' => $pair['public'],
            'private' => $pair['private'],
            'auth' => Ecdh::b64u(random_bytes(16)),
            'point' => Ecdh::decodePointFromBase64Url($pair['public']),
        ];
    }
}