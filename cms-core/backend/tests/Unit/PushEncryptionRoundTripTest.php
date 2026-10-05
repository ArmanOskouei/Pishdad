<?php

namespace Tests\Unit;

use App\Services\Push\BigNum;
use App\Services\Push\Curve;
use App\Services\Push\Ecdh;
use App\Services\Push\Point;
use App\Services\Push\PushEncryptor;
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
 */
class PushEncryptionRoundTripTest extends TestCase
{
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
        $this->assertArrayHasKey('Encryption', $result['headers']);

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
    }

    public function test_salt_makes_every_message_unique(): void
    {
        $browser = $this->makeBrowserKeyPair();

        $first = PushEncryptor::encrypt(['x' => 1], $browser['public'], $browser['auth']);
        $second = PushEncryptor::encrypt(['x' => 1], $browser['public'], $browser['auth']);

        $this->assertNotSame($first['body'], $second['body']);
        $this->assertNotSame($first['headers']['Encryption'], $second['headers']['Encryption']);
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
     * رمزگشایی، از دیدِ کتابخانهٔ سمت مرورگر.
     *
     * ## چرا `Ecdh` خودمان را استفاده می‌کند
     *
     * `openssl_pkey_derive()` روی این محیط خراب است، پس تست هم نمی‌تواند
     * از آن بهره بگیرد. در عوض ECDH را از `Ecdh` می‌گیریم — که جداگانه با
     * بردارهای NIST تست شده — ولی **هیچ متدی از `PushEncryptor` را صدا
     * نمی‌زنیم`.
     *
     * یعنی هنوز دو مسیرِ مستقل داریم: ترکیب‌بندیِ HKDF و رمزنگاری از
     * سمت سرور، در برابر ترکیب‌بندی و رمزگشایی از سمت گیرنده. یک خطای
     * مشترک در هر دو، تست را سبز نمی‌کند.
     */
    private function decryptAsBrowser(array $result, array $browser): string
    {
        $body = $result['body'];
        $salt = Ecdh::b64d($result['headers']['Encryption']);

        $this->assertSame(16, strlen($salt));

        $rs = substr($body, 16, 4);
        $idlen = ord($body[20]);
        $senderPublic = substr($body, 21, $idlen);
        $ciphertextAndTag = substr($body, 21 + $idlen);

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