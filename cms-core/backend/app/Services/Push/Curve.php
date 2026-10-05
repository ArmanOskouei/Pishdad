<?php

namespace App\Services\Push;

/**
 * منحنی بیضوی P-256 (secp256r1) — پارامترها و اعمال گروهی.
 *
 * ## منحنی
 *
 * ‎y² = x³ − 3x + b (mod p)، یعنی `a = −3`.
 *
 * ## چرا مختصات Affine
 *
 * کد کوتاه‌تر و خطایابی روشن‌تر است، به قیمت یک تقسیم پیمانه‌ای در هر
 * جمع. یک ضرب اسکالر ۲۵۶ بیتی حدود **۱ میلی‌ثانیه** طول می‌کشد که برای
 * حجم push کاملاً کافی است.
 *
 * ## فرمول‌ها (Short Weierstrass, a = −3)
 *
 * • اگر `x₁ = x₂` و `y₁ = y₂` ⇒ دوبرابر کردن، شیب `λ = (3x² + a)/2y`
 * • اگر `x₁ = x₂` و `y₁ ≠ y₂` ⇒ نتیجه بی‌نهایت
 * • وگرنه ⇒ `λ = (y₂−y₁)/(x₂−x₁)`
 */
final class Curve
{
    public static function p(): BigNum
    {
        return BigNum::of('0xffffffff00000001000000000000000000000000ffffffffffffffffffffffff');
    }

    /** b منحنی P-256. */
    public static function b(): BigNum
    {
        return BigNum::of('0x5ac635d8aa3a93e7b3ebbd55769886bc651d06b0cc53b0f63bce3c3e27d2604b');
    }

    /**
     * a = −3 (mod p).
     *
     * ⚠️ این `p − 3` است، **نه** `p mod 3`. نوشتنِ `mod(p, 3)` همان
     * اشتباهی است که اولین پیاده‌سازی داشت: نتیجه `p mod 3 = 1` می‌شد و
     * منحنی عملاً `y² = x³ + x + b` می‌شد — که نقطهٔ مولدِ استاندارد روی
     * آن نیست و تمام تست‌های نقطه‌ای بی‌سروصدا خراب می‌شدند.
     */
    public static function a(): BigNum
    {
        return BigNum::sub(self::p(), '3', self::p());
    }

    /** مرتّب (نقطهٔ مولد) — همان `G` در استاندارد. */
    public static function generator(): Point
    {
        return new Point(
            BigNum::of('0x6b17d1f2e12c4247f8bce6e563a440f277037d812deb33a0f4a13945d898c296'),
            BigNum::of('0x4fe342e2fe1a7f9b8ee7eb4a7c0f9e162bce33576b315ececbb6406837bf51f5'),
        );
    }

    /** آیا نقطه واقعاً روی منحنی است؟ */
    public static function isOnCurve(Point $point): bool
    {
        if ($point->isInfinity()) {
            return false;
        }

        $p = self::p();

        $left = BigNum::mul($point->y, $point->y, $p);

        $rhs = BigNum::add(
            BigNum::add(
                BigNum::mul(
                    BigNum::mul($point->x, $point->x, $p),
                    $point->x,
                    $p,
                ),
                BigNum::mul(self::a(), $point->x, $p),
                $p,
            ),
            self::b(),
            $p,
        );

        return $left->equals($rhs);
    }

    /** دوبرابر کردن نقطه: `λ = (3x² + a) / 2y`. */
    public static function double(Point $point): Point
    {
        if ($point->isInfinity() || $point->y->isZero()) {
            return Point::infinity();
        }

        $p = self::p();

        $numerator = BigNum::add(
            BigNum::mul(BigNum::of('3'), BigNum::mul($point->x, $point->x, $p), $p),
            self::a(),
            $p,
        );

        $lambda = BigNum::mul(
            $numerator,
            BigNum::inv(BigNum::mul(BigNum::of('2'), $point->y, $p), $p),
            $p,
        );

        $x3 = BigNum::sub(
            BigNum::sub(BigNum::mul($lambda, $lambda, $p), BigNum::mul(BigNum::of('2'), $point->x, $p), $p),
            BigNum::zero(),
            $p,
        );

        $y3 = BigNum::sub(
            BigNum::mul($lambda, BigNum::sub($point->x, $x3, $p), $p),
            $point->y,
            $p,
        );

        return new Point($x3, $y3);
    }

    public static function add(Point $a, Point $b): Point
    {
        if ($a->isInfinity()) {
            return $b;
        }

        if ($b->isInfinity()) {
            return $a;
        }

        $p = self::p();

        if ($a->x->equals($b->x)) {
            // ‎y₁ = −y₂ ⇒ دو نقطهٔ متقابل‌اند، نه یکی.
            if ($a->y->equals($b->y)) {
                return self::double($a);
            }

            return Point::infinity();
        }

        $lambda = BigNum::mul(
            BigNum::sub($b->y, $a->y, $p),
            BigNum::inv(BigNum::sub($b->x, $a->x, $p), $p),
            $p,
        );

        $x3 = BigNum::sub(
            BigNum::sub(BigNum::mul($lambda, $lambda, $p), $a->x, $p),
            $b->x,
            $p,
        );

        $y3 = BigNum::sub(
            BigNum::mul($lambda, BigNum::sub($a->x, $x3, $p), $p),
            $a->y,
            $p,
        );

        return new Point($x3, $y3);
    }

    /**
     * ضرب اسکالر با روش «دو‌و‌سه».
     *
     * ‎⚠️ اسکالر باید دقیقاً **۲۵۶ بیت** خوانده شود. صفرهای بالای آن هستند
     * و الگوریتم به آن‌ها نیاز دارد؛ خواندن با عرض متغیر یعنی دیدنِ
     * صفر به‌جای بیت — که همان باگی است که تست‌های غلط تولید می‌کنند.
     */
    public static function multiply(Point $point, BigNum $scalar): Point
    {
        if ($scalar->isZero() || $point->isInfinity()) {
            return Point::infinity();
        }

        $result = Point::infinity();

        foreach ($scalar->bits(256) as $bit) {
            $result = self::double($result);

            if ((string) $bit === '1') {
                $result = self::add($result, $point);
            }
        }

        return $result;
    }
}