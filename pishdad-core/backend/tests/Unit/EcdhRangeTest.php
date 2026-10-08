<?php

namespace Tests\Unit;

use App\Services\Push\BigNum;
use App\Services\Push\Curve;
use App\Services\Push\Ecdh;
use App\Services\Push\Point;
use PHPUnit\Framework\TestCase;

/**
 * گام ۳ اعتبارسنجیِ نقطه در RFC 8291 §۷: مختصات باید در بازهٔ `1 … p−1` باشند.
 *
 * ## چه چیزی اینجا بسته شد
 *
 * `Curve::isOnCurve()` — تنها دروازهٔ اعتبارسنجیِ نقطهٔ مقابل — فقط دو گام را
 * داشت: «بی‌نهایت نباشد» و «معادلهٔ منحنی را پاس کند». گامِ بازهٔ مختصات جا
 * افتاده بود.
 *
 * و این جا افتادن واقعاً قابل‌بهره‌برداری بود، چون **تمام حساب‌ها پیمانه‌ای‌اند**:
 * `(x, y)` و `(x mod p, y mod p)` از نظر معادله یکی‌اند. یعنی یک مختصاتِ
 * `≥ p` می‌تواند معادلهٔ `y² = x³ − 3x + b (mod p)` را پاس کند و پذیرفته شود.
 *
 * اثرش کم بود — رازِ مشترک فقط مختصات x است و همه‌چیز `mod p` می‌شود — ولی
 * انحراف از RFC بود و حالا بسته است.
 *
 * ## بردارِ بارِ واقعی
 *
 * چون `p ≡ 3 (mod 4)` است، `y₀ = b^((p+1)/4) mod p` ریشهٔ دومِ `b` است. پس
 * نقطهٔ `(x = p, y = y₀)` **معادلهٔ منحنی را پاس می‌کند** (`x ≡ 0 mod p` ⇒
 * `y² ≡ b`) و در عین حال مختصاتش بیرون از بازه است. دقیقاً همین نقطه، بردارِ
 * `test_out_of_range_point_that_satisfies_the_curve_equation_is_rejected` است —
 * پیش از این اصلاح، `isOnCurve()` آن را **قبول** می‌کرد.
 *
 * نکتهٔ بقیهٔ بردارها: `x = 0`، `x = p`، `y = 0` و … به‌تنهایی هم با معادله رد
 * می‌شوند، پس به‌تنهایی گامِ بازه را ثابت نمی‌کنند؛ ارزششان در قفل‌کردنِ رفتارِ
 * کلّی و پوششِ لبه‌هاست.
 */
class EcdhRangeTest extends TestCase
{
    /**
     * ریشهٔ دومِ `b` — همان `y₀` که بردارِ اصلی استفاده می‌کند. اینجا ثابت
     * نوشته شده تا تست بی‌نیاز از modexp بماند؛
     * `test_this_file_uses_the_right_sqrt_of_b` منشأش را قفل می‌کند.
     */
    private const SQRT_B = '0x66485c780e2f83d72433bd5d84a06bb6541c2af31dae871728bf856a174f93f4';

    /** نقطهٔ غیرفشردهٔ ۶۵ بایتی از روی مختصات. */
    private function encode(BigNum $x, BigNum $y): string
    {
        return Ecdh::pointToBase64Url(new Point($x, $y));
    }

    // ─────────────────────────────────────────────────────────────────────
    // نقطهٔ معتبر پذیرفته می‌شود (چکِ ضدِ بیش‌ازحد)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * نقطهٔ مولدِ خودِ `Curve` — قطعاً در بازهٔ `1 … p−1` است، پس گامِ جدید
     * نباید آن را دور بیندازد.
     */
    public function test_generator_is_accepted(): void
    {
        $generator = Curve::generator();

        $decoded = Ecdh::decodePointFromBase64Url($this->encode($generator->x, $generator->y));

        $this->assertTrue($decoded->equals($generator));
        $this->assertTrue(Curve::isInRange($decoded));
        $this->assertTrue(Curve::isOnCurve($decoded));
    }

    /** مسیرِ واقعیِ تولیدِ کلید هم نباید بشکند. */
    public function test_a_freshly_generated_key_pair_is_accepted(): void
    {
        $pair = Ecdh::generateKeyPair();

        $decoded = Ecdh::decodePointFromBase64Url($pair['public']);

        $this->assertTrue(Curve::isInRange($decoded));
        $this->assertTrue(Curve::isOnCurve($decoded));
        $this->assertSame($pair['public'], Ecdh::pointToBase64Url($decoded));
    }

    /**
     * ‎⚠️ `BigNum::cmp` از `bccomp` می‌آید و **اختلافِ خام** را برمی‌گرداند،
     * نه `-1/0/1`. پس فقط علامتش قابل استفاده است — نه مقدارش.
     */
    public function test_generator_coordinates_are_strictly_inside_the_prime_field(): void
    {
        $generator = Curve::generator();
        $p = Curve::p();

        $this->assertTrue($generator->x->cmp($p) < 0, 'x مولد باید کوچک‌تر از p باشد.');
        $this->assertTrue($generator->y->cmp($p) < 0, 'y مولد باید کوچک‌تر از p باشد.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // گام ۳ RFC 8291 §۷ — مختصات بیرون از `1 … p−1` رد می‌شوند
    // ─────────────────────────────────────────────────────────────────────

    public function test_x_zero_is_rejected(): void
    {
        $generator = Curve::generator();

        $this->expectException(\InvalidArgumentException::class);

        Ecdh::decodePointFromBase64Url($this->encode(BigNum::zero(), $generator->y));
    }

    public function test_x_equal_to_prime_is_rejected(): void
    {
        $generator = Curve::generator();

        $this->expectException(\InvalidArgumentException::class);

        Ecdh::decodePointFromBase64Url($this->encode(Curve::p(), $generator->y));
    }

    public function test_x_greater_than_prime_is_rejected(): void
    {
        $generator = Curve::generator();

        $x = BigNum::of(bcadd((string) Curve::p(), '1', 0));

        $this->assertTrue($x->cmp(Curve::p()) > 0, 'بردارِ تست: x باید بزرگ‌تر از p باشد.');

        $this->expectException(\InvalidArgumentException::class);

        Ecdh::decodePointFromBase64Url($this->encode($x, $generator->y));
    }

    public function test_y_zero_is_rejected(): void
    {
        $generator = Curve::generator();

        $this->expectException(\InvalidArgumentException::class);

        Ecdh::decodePointFromBase64Url($this->encode($generator->x, BigNum::zero()));
    }

    public function test_y_equal_to_prime_is_rejected(): void
    {
        $generator = Curve::generator();

        $this->expectException(\InvalidArgumentException::class);

        Ecdh::decodePointFromBase64Url($this->encode($generator->x, Curve::p()));
    }

    /**
     * ⚠️ **این تستِ بارِ واقعی است.**
     *
     * بردارهای بالا همگی با معادلهٔ منحنی هم رد می‌شوند، پس اگر گامِ بازه نبود
     * باز هم پاس می‌شدند. این یکی **معادله را پاس می‌کند**: `x = p ≡ 0 (mod p)`
     * ⇒ `y² ≡ b (mod p)` و `SQRT_B` دقیقاً ریشهٔ دومِ `b` است. یعنی پیش از
     * این اصلاح، `Curve::isOnCurve()` این نقطه را **قبول** می‌کرد.
     *
     * چهار چیز قفل می‌شود: بردار درست ساخته شده (معادله را پاس می‌کند)،
     * طولش ۶۵ بایت است (پس به دلیلِ طول رد نمی‌شود)، `isInRange`/`isOnCurve`
     * آن را رد می‌کنند، و مسیرِ `decode` خطا می‌دهد.
     */
    public function test_out_of_range_point_that_satisfies_the_curve_equation_is_rejected(): void
    {
        $p = Curve::p();
        $y = BigNum::of(self::SQRT_B);

        $crafted = new Point($p, $y);

        // ‎(a) منشأِ بردار: y² ≡ b (mod p) ⇒ با x ≡ 0 معادله را پاس می‌کند.
        $this->assertTrue(
            BigNum::mul($y, $y, $p)->equals(Curve::b()),
            'SQRT_B باید ریشهٔ دومِ b باشد.',
        );

        $this->assertTrue(
            $this->satisfiesCurveEquation($crafted),
            'بردارِ تست باید معادلهٔ منحنی را پاس کند، وگرنه این تست بی‌معنا می‌شود.',
        );

        // ‎(b) طول درست است، پس دلیلِ رد شدن «برشِ نقطه» نیست.
        $this->assertSame(Ecdh::POINT_LENGTH, strlen(Ecdh::encodePoint($crafted)));

        // ‎(c) حالا باید به خاطرِ بازه رد شود.
        $this->assertFalse(Curve::isInRange($crafted));
        $this->assertFalse(Curve::isOnCurve($crafted));

        $this->expectException(\InvalidArgumentException::class);

        Ecdh::decodePointFromBase64Url($this->encode($crafted->x, $crafted->y));
    }

    /** نقطهٔ بی‌نهایت هم بی‌نهایت است و هم بیرون از بازه. */
    public function test_infinity_is_rejected(): void
    {
        $this->assertFalse(Curve::isInRange(Point::infinity()));
        $this->assertFalse(Curve::isOnCurve(Point::infinity()));
    }

    // ─────────────────────────────────────────────────────────────────────
    // گام ۲ RFC 8291 §۷ سرِ جایش است (ضدِ رگرسیون)
    // ─────────────────────────────────────────────────────────────────────

    /** نقطه‌ای که نه در بازه است و نه روی منحنی، همچنان رد می‌شود. */
    public function test_off_curve_point_is_still_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Ecdh::decodePoint(str_repeat("\x01", 65));
    }

    /** طول و پیشوند همچنان بررسی می‌شوند. */
    public function test_truncated_point_is_still_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Ecdh::decodePoint("\x04too-short");
    }

    /**
     * ECDH روی یک نقطهٔ ساختگیِ بیرون-از-بازه نباید راز بدهد.
     *
     * هرچند پیش از این اصلاح هم به خاطرِ ماهیتِ موقتِ راز اثری نداشت، این
     * تست تضمین می‌کند که دروازهٔ اعتبارسنجی سرِ جایش مانده.
     */
    public function test_shared_secret_refuses_an_out_of_range_peer_point(): void
    {
        $pair = Ecdh::generateKeyPair();

        $crafted = Ecdh::encodePoint(new Point(Curve::p(), BigNum::of(self::SQRT_B)));

        $this->expectException(\InvalidArgumentException::class);

        Ecdh::sharedSecret($pair['private'], $crafted);
    }

    /**
     * ریشهٔ دومِ `b` را از صفر حساب می‌کند و با ثابتِ این فایل مقایسه می‌کند.
     *
     * `p ≡ 3 (mod 4)` است، پس `b^((p+1)/4)` ریشهٔ دومِ `b` است — به شرط آنکه
     * `b` مربعِ مرکب باشد. اگر روزی روش عوض شود و ثابت هنوز درست بماند،
     * این تست می‌افتد.
     */
    public function test_this_file_uses_the_right_sqrt_of_b(): void
    {
        $p = Curve::p();

        // ‎⚠️ `bccomp` اختلافِ خام می‌دهد، پس باید با صفر مقایسه شود نه با ۳.
        $this->assertSame(0, bccomp(bcmod((string) $p, '4', 0), '3', 0));

        $exponent = bcdiv(bcadd((string) $p, '1', 0), '4', 0);

        $sqrt = BigNum::mod(bcpowmod((string) Curve::b(), $exponent, (string) $p), (string) $p);

        $this->assertTrue($sqrt->equals(BigNum::of(self::SQRT_B)));
    }

    /** معادلهٔ خامِ منحنی — بدون گامِ بازه — برای ساختنِ بردارهای تست. */
    private function satisfiesCurveEquation(Point $point): bool
    {
        $p = Curve::p();

        return BigNum::mul($point->y, $point->y, $p)->equals(
            BigNum::add(
                BigNum::add(
                    BigNum::mul(BigNum::mul($point->x, $point->x, $p), $point->x, $p),
                    BigNum::mul(Curve::a(), $point->x, $p),
                    $p,
                ),
                Curve::b(),
                $p,
            ),
        );
    }
}
