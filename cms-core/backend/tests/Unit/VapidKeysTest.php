<?php

namespace Tests\Unit;

use App\Services\Push\Curve;
use App\Services\Push\Ecdh;
use App\Services\Push\Point;
use App\Services\Push\VapidKeys;
use PHPUnit\Framework\TestCase;

/**
 * تست کلیدهای VAPID و امضای ES256.
 *
 * ## چرا این تست‌ها حیاتی‌اند
 *
 * سه باگِ واقعی در نسخهٔ اول این کد بود که همگی **بی‌سروصدا** بودند:
 *
 * ۱. کلید خصوصی از `$details['key']` گرفته می‌شد که روی OpenSSL 3.x یک
 *    PEM **عمومی** است، نه خصوصی — پس امضا همیشه شکست می‌خورد.
 * ۲. `s` در خروجی DER اصلاً به امضا اضافه نمی‌شد — فقط `r`.
 * ۳. `r`/`s` با `''`-padding می‌آمدند، پس مختصاتِ کوتاه‌تر از ۳۲ بایت
 *    جابه‌جا می‌شدند.
 *
 * هر سه فقط در **تولید** خودش را نشان می‌دادند، پس تست باید دقیقاً همان
 * مسیر تولیدی را صدا بزند — نه یک بازسازیِ موازی.
 */
class VapidKeysTest extends TestCase
{
    public function test_generated_public_key_is_an_uncompressed_point(): void
    {
        $keys = VapidKeys::generate();

        $raw = Ecdh::b64d($keys['publicKey']);

        $this->assertSame(65, strlen($raw));
        $this->assertSame("\x04", $raw[0]);
        $this->assertTrue(Curve::isOnCurve(Ecdh::decodePoint($raw)));
    }

    /**
     * مهم‌ترین تست: امضای تولیدشده باید با کلید عمومیِ متناظر **تأیید** شود.
     *
     * اگر این پاس نشود، سرویس push هر درخواست را با خطای ۴۰۱ رد می‌کند.
     *
     * `openssl_verify` امضای خامِ JWS را نمی‌پذیرد، پس اینجا DER را از
     * `r ‖ s` می‌سازیم تا بتوانیم با همان تابع استاندارد بررسی کنیم.
     */
    public function test_signature_verifies_with_the_public_key(): void
    {
        $keys = VapidKeys::generate();

        $message = 'a.b.c';

        $raw = Ecdh::b64d(VapidKeys::signEs256($message, $keys['privateKey']));

        $this->assertSame(1, openssl_verify(
            $message,
            $this->rawToDer($raw),
            $this->pointToPem(Ecdh::decodePoint(Ecdh::b64d($keys['publicKey']))),
            OPENSSL_ALGO_SHA256,
        ));
    }

    /**
     * ‎ES256 در JWS از امضای **خام** `r ‖ s` استفاده می‌کند، نه DER.
     *
     * اگر کد DER بدهد، برای بعضی کلیدها (تقریباً ۲۰٪) نامعتبر می‌شود و
     * برای بقیه درست — بدترین نوع باگ، چون «تقریباً کار می‌کند».
     */
    public function test_signature_is_raw_r_s_not_der(): void
    {
        $keys = VapidKeys::generate();

        $raw = Ecdh::b64d(VapidKeys::signEs256('x', $keys['privateKey']));

        // ‏r ‖ s هرکدام دقیقاً ۳۲ بایت
        $this->assertSame(64, strlen($raw));

        // DER با `0x30` شروع می‌شود
        $this->assertNotSame("\x30", substr($raw, 0, 1));
    }

    /**
     * هر دو عددِ امضا باید دقیقاً ۳۲ بایت باشند، حتی وقتی کوتاه‌اند.
     *
     * ASN.1 عددِ کوچک را با بایت کمتری می‌نویسد؛ اگر کد صفرگذاری نکند،
     * جابه‌جایی رخ می‌دهد و امضا برای همیشه غلط می‌شود.
     */
    public function test_both_signature_halves_are_always_32_bytes(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $keys = VapidKeys::generate();

            $raw = Ecdh::b64d(VapidKeys::signEs256('m'.$i, $keys['privateKey']));

            $this->assertSame(64, strlen($raw), 'امضا باید همیشه ۶۴ بایت باشد');

            $this->assertSame(1, openssl_verify(
                'm'.$i,
                $this->rawToDer($raw),
                $this->pointToPem(Ecdh::decodePoint(Ecdh::b64d($keys['publicKey']))),
                OPENSSL_ALGO_SHA256,
            ));
        }
    }

    public function test_signature_does_not_verify_against_a_different_key(): void
    {
        $keys = VapidKeys::generate();
        $other = VapidKeys::generate();

        $raw = Ecdh::b64d(VapidKeys::signEs256('tampered', $keys['privateKey']));

        $this->assertNotSame(1, openssl_verify(
            'tampered',
            $this->rawToDer($raw),
            $this->pointToPem(Ecdh::decodePoint(Ecdh::b64d($other['publicKey']))),
            OPENSSL_ALGO_SHA256,
        ));
    }

    public function test_two_generated_keys_differ(): void
    {
        $first = VapidKeys::generate();
        $second = VapidKeys::generate();

        $this->assertNotSame($first['publicKey'], $second['publicKey']);
        $this->assertNotSame($first['privateKey'], $second['privateKey']);
    }

    /**
     * کلید عمومی باید از روی اسکالرِ کلید خصوصی قابل بازتولید باشد.
     *
     * اگر این نبود، VAPID یک کلید امضا می‌کرد ولی کلید عمومیِ اعلام‌شده
     * به آن تعلق نداشت — و سرویس push هر درخواست را رد می‌کرد.
     */
    public function test_public_key_is_derived_from_the_private_key(): void
    {
        $keys = VapidKeys::generate();

        $this->assertSame(
            $keys['publicKey'],
            Ecdh::publicFromPrivate(VapidKeys::base64ToScalar($keys['privateKey'])),
        );
    }

    /** ‎`r ‖ s` خام ⇒ DER، تا `openssl_verify` بپذیرد. */
    private function rawToDer(string $raw): string
    {
        $this->assertSame(64, strlen($raw));

        $r = ltrim(substr($raw, 0, 32), "\x00");
        $s = ltrim(substr($raw, 32), "\x00");

        // ASN.1 INTEGER علامت‌دار است؛ اگر بیتِ بالا روشن باشد باید
        // یک بایت صفر جلوش گذاشته شود تا منفی تعبیر نشود.
        if ($r === '' || ord($r[0]) > 0x7F) {
            $r = "\x00".$r;
        }

        if ($s === '' || ord($s[0]) > 0x7F) {
            $s = "\x00".$s;
        }

        $body = "\x02".chr(strlen($r)).$r."\x02".chr(strlen($s)).$s;

        return "\x30".chr(strlen($body)).$body;
    }

    private function pointToPem(Point $point): string
    {
        $algorithm = "\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

        $bytes = Ecdh::encodePoint($point);

        $bitString = "\x03".chr(strlen($bytes) + 1)."\x00".$bytes;

        $der = "\x30".chr(strlen($algorithm.$bitString)).$algorithm.$bitString;

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }
}