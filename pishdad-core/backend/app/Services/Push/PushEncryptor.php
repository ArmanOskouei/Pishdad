<?php

namespace App\Services\Push;

/**
 * رمزنگاری بدنهٔ پیام push — RFC 8291 (`aes128gcm`).
 *
 * ## الگوریتم، قدم‌به‌قدم
 *
 * ۱. یک **جفت‌کلید موقت** (`as`) می‌سازیم — فقط برای همین یک پیام.
 * ۲. با `p256dh` مرورگر، ECDH می‌زنیم ⇒ `ecdh_secret` (۳۲ بایت).
 * ۳. `auth` مرورگر را با آن ترکیب می‌کنیم و HKDF می‌سازیم ⇒ `IKM`.
 * ۴. `IKM` را با یک **salt تصادفی** به `PRK` تبدیل می‌کنیم.
 * ۵. از `PRK` دو چیز مشتق می‌کنیم: کلید AES و **nonce**.
 * ۶. بدنه را AES-128-GCM رمز می‌کنیم.
 *
 * ## چیدمان خروجی
 *
 * ‎`salt(16) ‖ rs(4) ‖ idlen(1) ‖ as_public(65) ‖ ciphertext ‖ tag(16)`
 *
 * ⚠️ پنج چیز که پیاده‌سازی‌های «تقریباً درست» خراب می‌کنند:
 *
 * • **nonce پیش‌فرضِ صفر نیست.** باید از `PRK` مشتق شود (گام ۵).
 * • **`rs` قبل از داده می‌آید**، نه بعدش — و مقدارش همیشه ۴۰۹۶ است.
 * • **AAD دقیقاً** `"PUSH" ‖ rs ‖ idlen ‖ as_public` است؛ نه بیشتر، نه کمتر.
 * • **جداکنندهٔ padding** ‎`0x02` باید انتهای متنِ رمزشده باشد (RFC 8291 §4).
 * • **هدر `Encryption` برای `aes128gcm` وجود ندارد.** salt خودش ۱۶ بایت
 *   اولِ body است؛ آن هدر متعلق به قالبِ کهنِ `aes128` است.
 *
 * ## چرا ECDH دستی است
 *
 * `openssl_pkey_derive()` روی این محیط خراب است، پس `Ecdh` همان کار را
 * با `bcmath` انجام می‌دهد. رازِ موقت است و پس از یک پیام دور می‌ریزد.
 */
class PushEncryptor
{
    /** اندازهٔ هر record — برای `aes128gcm` همیشه ۴۰۹۶. */
    private const RECORD_SIZE = 4096;

    /** ‎`salt(16) ‖ rs(4) ‖ idlen(1) ‖ key(65)` */
    private const HEADER_SIZE = 16 + 4 + 1 + 65;

    /** طول تگ AES-GCM. */
    private const TAG_SIZE = 16;

    /**
     * جداکنندهٔ padding — RFC 8291 §4.
     *
     * آخرین record **باید** به `0x02` ختم شود (پیام‌های push تک‌record
     * هستند، پس padding خالی است و فقط همان یک بایت لازم می‌شود).
     * مرورگر این بایت را چک می‌کند و اگر نباشد کل پیام را دور می‌ریزد.
     */
    private const PADDING_DELIMITER = "\x02";

    /**
     * @param  array<string, mixed>  $payload
     * @return array{body: string, headers: array<string, string>}
     */
    public static function encrypt(array $payload, ?string $recipientPublicKeyB64, ?string $authSecretB64): array
    {
        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === '') {
            throw new \InvalidArgumentException('پیام push قابل تبدیل به JSON نبود.');
        }

        // ۸۶ بایت هدر + ۱۶ بایت تگ + **۱ بایت جداکننده**. بدون کسرِ
        // جداکننده، record از ۴۰۹۶ بایت رد می‌شود و سرویس push کل بدنه را
        // به‌عنوان خراب رد می‌کند.
        if (strlen($json) > self::RECORD_SIZE - self::HEADER_SIZE - self::TAG_SIZE - 1) {
            throw new \InvalidArgumentException(
                'پیام push بزرگ‌تر از حد مجاز است؛ متن را کوتاه‌تر کنید.',
            );
        }

        // اشتراک‌های قدیمی `p256dh` ندارند؛ آن‌ها رمزنگاری نمی‌شوند
        // (RFC 8291 §3.4) و TLS این مسیر را پوشش می‌دهد.
        if ($recipientPublicKeyB64 === null || $recipientPublicKeyB64 === ''
            || $authSecretB64 === null || $authSecretB64 === '') {
            return self::encryptWithoutKeys($json);
        }

        $recipientPoint = Ecdh::decodePointFromBase64Url($recipientPublicKeyB64);
        $authSecret = Ecdh::b64d($authSecretB64);

        if (strlen($authSecret) !== 16) {
            throw new \InvalidArgumentException('کلید `auth` مرورگر معتبر نیست.');
        }

        $salt = random_bytes(16);

        // گام ۱: جفت‌کلید موقت
        $ephemeral = Ecdh::generateKeyPair();

        // گام ۲: ECDH
        $ecdhSecret = Ecdh::sharedSecret($ephemeral['private'], Ecdh::encodePointBytes($recipientPoint));

        // گام ۳: IKM
        //
        // ‎PRK_key = HMAC(auth, ecdh_secret)
        // ‎key_info = "WebPush: info" ‖ 0x00 ‖ ua_public ‖ as_public
        // ‎IKM = HMAC(PRK_key, key_info ‖ 0x01)
        $prkKey = hash_hmac('sha256', $ecdhSecret, $authSecret, true);

        $keyInfo = "WebPush: info\x00".Ecdh::encodePointBytes($recipientPoint).Ecdh::b64d($ephemeral['public']);

        $ikm = hash_hmac('sha256', $keyInfo."\x01", $prkKey, true);

        // گام ۴: PRK
        $prk = hash_hmac('sha256', $ikm, $salt, true);

        // گام ۵: کلید و nonce
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01", $prk, true), 0, 12);

        // گام ۶: AES-GCM
        //
        // ⚠️ متنِ ورودی AES **باید** به جداکنندهٔ padding ختم شود (RFC 8291
        // §4). بدون آن، مرورگر پیام را معتبر نمی‌داند و بی‌صدا دورش می‌ریزد —
        // و چون decrypt شکست نمی‌خورد، هیچ خطایی هم دیده نمی‌شود.
        $senderPublic = Ecdh::b64d($ephemeral['public']);

        $ciphertext = self::aesGcmEncrypt(
            $cek,
            $nonce,
            $json.self::PADDING_DELIMITER,
            $senderPublic,
        );

        $body = $salt
            .pack('N', self::RECORD_SIZE)
            .chr(Ecdh::POINT_LENGTH)
            .$senderPublic
            .$ciphertext;

        return [
            'body' => $body,
            'headers' => [
                'Content-Encoding' => 'aes128gcm',
                'Content-Type' => 'application/octet-stream',
                'TTL' => '86400',
                // ⚠️ عمداً **بدون** هدر `Encryption`، و برای `aes128gcm`
                // لازم هم نیست: salt همین ۱۶ بایتِ اولِ body است. آن هدر
                // متعلق به قالبِ کهنِ `aes128` (draft پیش از RFC 8188) بود که
                // salt را بیرون از body می‌فرستاد. اگر اینجا دوباره بیاید،
                // گیرنده آن را با salt داخلِ body اشتباه می‌گیرد یا نادیده
                // می‌گیرد — و به‌سوی باگ‌های بی‌پایان ختم می‌شود.
            ],
        ];
    }

    /**
     * مسیر بدون رمزنگاری، برای اشتراک‌هایی که کلید ندارند.
     *
     * ## ⚠️ چرا `Content-Encoding` ندارد
     *
     * نسخهٔ اول اینجا `Content-Encoding: aes128gcm` می‌فرستاد، در حالی که
     * بدنه **رمزنشده** بود. سرویس push به `aes128gcm` اعتماد می‌کند و
     * بدنه را decrypt می‌کند ⇒ شکست ⇒ پیام هرگز به دست کاربر نمی‌رسد و
     * ما فکر می‌کنیم ارسال موفق بوده چون status 201 گرفته‌ایم.
     *
     * طبق RFC 8291 §5.2 وقتی گیرنده `p256dh` ندارد، فرستنده باید بدنه را
     * **بدون هیچ هدر رمزنگاری** بفرستد؛ متن در مسیر TLS سرویس push
     * محافظت می‌شود.
     */
    private static function encryptWithoutKeys(string $plaintext): array
    {
        return [
            'body' => $plaintext,
            'headers' => [
                'Content-Type' => 'application/octet-stream',
                'TTL' => '86400',
            ],
        ];
    }

    /**
     * ‎AAD = `"PUSH" ‖ rs ‖ idlen ‖ senderPublicKey`.
     *
     * تگ ۱۶ بایتی به **انتهای** ciphertext اضافه می‌شود.
     *
     * `$plaintext` از قبل باید به `PADDING_DELIMITER` ختم شده باشد — رمزگشایی
     * اینجا عمداً هیچ کاری به پدینگ ندارد، چون record ما تکی است.
     */
    private static function aesGcmEncrypt(string $cek, string $nonce, string $plaintext, string $senderPublic): string
    {
        $rs = pack('N', self::RECORD_SIZE);

        $aad = 'PUSH'.$rs.chr(Ecdh::POINT_LENGTH).$senderPublic;

        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
            16,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('رمزنگاری AES-GCM ناموفق بود: '.openssl_error_string());
        }

        return $ciphertext.$tag;
    }
}