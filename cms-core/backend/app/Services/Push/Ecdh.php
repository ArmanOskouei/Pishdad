<?php

namespace App\Services\Push;

/**
 * تبادل کلید عمومی ECDH روی P-256.
 *
 * ## چرا دستی
 *
 * `openssl_pkey_derive()` روی این محیط (PHP 8.5.11 / OpenSSL 3.5.8) همیشه
 * با خطای «Supplied key param is a public key» شکست می‌خورد — حتی برای
 * RSA. یعنی ECDH در PHP اینجا در دسترس نیست و `bcmath` تنها گزینهٔ
 * باقی‌مانده است.
 *
 * ## چیدمان نقطه
 *
 * Web Push نقطه را به شکل **غیرفشرده** می‌دهد:
 *   `0x04 ‖ x(32 بایت) ‖ y(32 بایت)`  ⇒ ۶۵ بایت
 *
 * ‎⚠️ مختصات باید دقیقاً **۳۲ بایت** صفرگذاری شوند. `BigNum::norm` صفرهای
 * ابتدایی ده‌دهی را حذف می‌کند، پس اگر مستقیم استفاده شود، نقطه ۶۵ بایتی
 * نیست و مرورگر رمزگشایی را رد می‌کند.
 */
final class Ecdh
{
    /** اندازهٔ هر مختصات. */
    private const COORD_SIZE = 32;

    /** طول نقطهٔ غیرفشرده. */
    public const POINT_LENGTH = 65;

    /**
     * یک جفت‌کلید تازه می‌سازد (به‌جای `openssl_pkey_new`).
     *
     * @return array{public: string, private: string}  هر دو base64url
     */
    public static function generateKeyPair(): array
    {
        $private = self::randomScalar();

        $point = Curve::multiply(Curve::generator(), $private);

        if ($point->isInfinity()) {
            throw new \RuntimeException('جفت‌کلید موقت ناموفق بود.');
        }

        return [
            'public' => self::pointToBase64Url($point),
            'private' => self::scalarToBase64Url($private),
        ];
    }

    /**
     * رازِ مشترک بین کلید خصوصیِ خودمان و کلید عمومیِ مقابل.
     *
     * خروجی، **۳۲ بایت خام** است — نه base64. RFC 8291 انتظار دارد
     * باینری باشد و بعداً HKDF رویش کار کند.
     *
     * @param  string  $privateBase64Url  کلید خصوصی خودمان (base64url)
     * @param  string  $peerPublicRaw     نقطهٔ مقابل (۶۵ بایت خام)
     */
    public static function sharedSecret(string $privateBase64Url, string $peerPublicRaw): string
    {
        $private = self::decodeScalar($privateBase64Url);
        $peer = self::decodePoint($peerPublicRaw);

        $result = Curve::multiply($peer, $private);

        if ($result->isInfinity()) {
            throw new \RuntimeException('ECDH به نقطهٔ بی‌نهایت رسید.');
        }

        return self::coordX($result);
    }

    /**
     * یک اسکالرِ تصادفی در بازهٔ `[1, n−1]`.
     *
     * ‎⚠️ حلقه عمداً دوباره می‌زند. یک عدد ۲۵۶ بیتی تصادفی گاهی از `n`
     * بزرگ‌تر است (احتمالش حدود ۲⁻³² است — کم، ولی غیرصفر) و اسکالرِ
     * نامعتبر یعنی نقطه‌ای که روی منحنی نیست و خطای گمراه‌کننده می‌دهد.
     */
    private static function randomScalar(): \App\Services\Push\BigNum
    {
        $n = self::order();

        do {
            // ‎32 بایت تصادفی = دقیقاً عرض P-256
            $candidate = self::bigNumFromBytes(random_bytes(self::COORD_SIZE));
        } while ($candidate->isZero() || $candidate->cmp($n) >= 0);

        return $candidate;
    }

    /** مرتب گروه: n برای P-256. */
    private static function order(): \App\Services\Push\BigNum
    {
        return \App\Services\Push\BigNum::of(
            '0xffffffff00000000ffffffffffffffffbce6faada7179e84f3b9cac2fc632551'
        );
    }

    /**
     * بایت‌های خام (big-endian) ⇒ عدد.
     *
     * بایتِ نخست ممکن است صفر باشد تا ۶۵ بایت بشود، ولی `BigNum` صفرِ
     * ابتدایی را حذف می‌کند — که اینجا **بی‌خطر** است چون فقط مقدار
     * عددی مهم است، نه عرضش. عرض دقیق بعداً در `bigNumToRaw()` برمی‌گردد.
     */
    private static function bigNumFromBytes(string $bytes): \App\Services\Push\BigNum
    {
        return \App\Services\Push\BigNum::of('0x'.bin2hex($bytes));
    }

    /** بایت‌های خام ⇒ base64url. */
    private static function rawToBase64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** base64url ⇒ بایت‌های خام. */
    private static function base64UrlToRaw(string $encoded): string
    {
        $padded = strtr($encoded, '-_', '+/');
        $remainder = strlen($padded) % 4;

        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);

        if ($decoded === false) {
            throw new \InvalidArgumentException('کد base64url نامعتبر است.');
        }

        return $decoded;
    }

    /** عدد ⇒ ۳۲ بایت، صفرگذاری سمت چپ. */
    private static function bigNumToRaw(\App\Services\Push\BigNum $n): string
    {
        $hex = ltrim($n->toHex(), '0');

        if (strlen($hex) % 2 !== 0) {
            $hex = '0'.$hex;
        }

        $raw = (string) hex2bin(str_pad($hex, self::COORD_SIZE * 2, '0', STR_PAD_LEFT));

        return str_pad($raw, self::COORD_SIZE, "\x00", STR_PAD_LEFT);
    }

    /** نقطه ⇒ `0x04 ‖ x ‖ y` (۶۵ بایت). */
    public static function encodePoint(Point $point): string
    {
        return "\x04".self::bigNumToRaw($point->x).self::bigNumToRaw($point->y);
    }

    public static function pointToBase64Url(Point $point): string
    {
        return self::rawToBase64Url(self::encodePoint($point));
    }

    /**
     * `0x04 ‖ x ‖ y` ⇒ نقطه، با اعتبارسنجی کامل.
     *
     * ‎⚠️ اعتبارسنجی در `Curve::isOnCurve()` است و هر سه گامِ RFC 8291 §۷
     * را انجام می‌دهد: بی‌نهایت نبودن، مختصات در بازهٔ `1 … p−1` بودن
     * (گامی که پیش‌تر جا افتاده بود)، و درستیِ معادلهٔ منحنی.
     */
    public static function decodePoint(string $raw): Point
    {
        if (strlen($raw) !== self::POINT_LENGTH || $raw[0] !== "\x04") {
            throw new \InvalidArgumentException('نقطهٔ عمومی معتبر نیست.');
        }

        $x = self::bigNumFromBytes(substr($raw, 1, self::COORD_SIZE));
        $y = self::bigNumFromBytes(substr($raw, 1 + self::COORD_SIZE, self::COORD_SIZE));

        $point = new Point($x, $y);

        if (! Curve::isOnCurve($point)) {
            throw new \InvalidArgumentException('نقطهٔ عمومی روی منحنی نیست.');
        }

        return $point;
    }

    public static function decodePointFromBase64Url(string $encoded): Point
    {
        return self::decodePoint(self::base64UrlToRaw($encoded));
    }

    private static function decodeScalar(string $base64Url): \App\Services\Push\BigNum
    {
        $scalar = self::bigNumFromBytes(self::base64UrlToRaw($base64Url));

        if ($scalar->isZero()) {
            throw new \InvalidArgumentException('اسکالرِ خصوصی نمی‌تواند صفر باشد.');
        }

        return $scalar;
    }

    /** فقط مختصات x (۳۲ بایت) — همان چیزی که RFC 8291 می‌خواهد. */
    private static function coordX(Point $point): string
    {
        return self::bigNumToRaw($point->x);
    }

    /** عمومی‌کردن یک اسکالر خصوصی. */
    public static function publicFromPrivate(string $privateBase64Url): string
    {
        $point = Curve::multiply(Curve::generator(), self::decodeScalar($privateBase64Url));

        if ($point->isInfinity()) {
            throw new \RuntimeException('اسکالر نامعتبر است.');
        }

        return self::pointToBase64Url($point);
    }

    public static function scalarToBase64Url(\App\Services\Push\BigNum $scalar): string
    {
        return self::rawToBase64Url(self::bigNumToRaw($scalar));
    }

    /** نقطه ⇒ بایت خامِ غیرفشرده (`۶۵` بایت). */
    public static function encodePointBytes(Point $point): string
    {
        return self::encodePoint($point);
    }

    /** base64url ⇒ بایت خام. عمومی، چون تست‌ها هم به آن نیاز دارند. */
    public static function b64d(string $encoded): string
    {
        return self::base64UrlToRaw($encoded);
    }

    /**
     * base64 **معمولی** ⇒ بایت خام.
     *
     * برای DER که در PEM ذخیره می‌شود — که برخلاف نقطه‌ها و اشتراک‌ها،
     * base64 استاندارد دارد نه base64url.
     */
    public static function b64StandardToRaw(string $encoded): string
    {
        $decoded = base64_decode(trim($encoded), true);

        if ($decoded === false) {
            throw new \InvalidArgumentException('کد base64 نامعتبر است.');
        }

        return $decoded;
    }

    /** بایت خام ⇒ base64url. */
    public static function b64u(string $raw): string
    {
        return self::rawToBase64Url($raw);
    }
}