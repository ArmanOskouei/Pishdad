<?php

namespace Tests\Unit;

use App\Services\Push\BigNum;
use App\Services\Push\Curve;
use App\Services\Push\Ecdh;
use App\Services\Push\Point;
use PHPUnit\Framework\TestCase;

/**
 * تست‌های منحنی بیضوی P-256 و تبادل کلید ECDH.
 *
 * ## چرا این‌قدر تست داریم
 *
 * کل رمزنگاریِ Web Push روی همین چند خط بنا شده، و `openssl_pkey_derive()`
 * روی این محیط کار نمی‌کند — پس امکان تکیه بر کتابخانه نیست. هر باگی
 * اینجا یا پیام‌ها را **بی‌سروصدا بی‌رمز** می‌کند یا بدتر: خروجیِ نادرست
 * می‌دهد که هیچ خطایی تولید نمی‌کند.
 *
 * به همین دلیل تست‌ها روی **بردارهای واقعی و منتشرشدهٔ NIST** قفل شده‌اند،
 * نه روی «خروجی همان چیزی که کد خودمان می‌دهد».
 */
class CurveEcdhTest extends TestCase
{
    public function test_generator_is_on_the_curve(): void
    {
        $this->assertTrue(Curve::isOnCurve(Curve::generator()));
    }

    /**
 * ‎2G از NIST SP 800-56A.
 *
 * اگر `a` اشتباه باشد — مثلاً `p mod 3` به‌جای `p − 3` — این تست می‌افتد
 * ولی هیچ خطایی نمی‌دهد.
 *
 * ⚠️ مختصات با `strPadLeft` مقایسه می‌شوند، چون `toHex()` صفرهای
 * ابتدایی را حذف می‌کند ولی NIST آن‌ها را می‌نویسد: `0x0777...` در برابر
 * `0x777...`.
 */
    public function test_double_generator_matches_nist(): void
    {
        $two = Curve::double(Curve::generator());

        $this->assertSame(
            '7CF27B188D034F7E8A52380304B51AC3C08969E277F21B35A60B48FC47669978',
            str_pad(strtoupper($two->x->toHex()), 64, '0', STR_PAD_LEFT),
        );

        $this->assertSame(
            '07775510DB8ED040293D9AC69F7430DBBA7DADE63CE982299E04B79D227873D1',
            str_pad(strtoupper($two->y->toHex()), 64, '0', STR_PAD_LEFT),
        );
    }

    public function test_triple_generator_matches_nist(): void
    {
        $three = Curve::add(Curve::double(Curve::generator()), Curve::generator());

        $this->assertSame(
            '5ECBE4D1A6330A44C8F7EF951D4BF165E6C6B721EFADA985FB41661BC6E7FD6C',
            str_pad(strtoupper($three->x->toHex()), 64, '0', STR_PAD_LEFT),
        );
    }

    /**
     * قوی‌ترین اعتبارسنجیِ ممکن: `n·G` باید بی‌نهایت باشد.
     *
     * اگر هر کجای ضرب اسکالر یا جمع خطا باشد، این تست می‌افتد.
     */
    public function test_order_times_generator_is_infinity(): void
    {
        $n = BigNum::of('0xffffffff00000000ffffffffffffffffbce6faada7179e84f3b9cac2fc632551');

        $this->assertTrue(Curve::multiply(Curve::generator(), $n)->isInfinity());
    }

    public function test_multiply_matches_repeated_addition(): void
    {
        $generator = Curve::generator();

        $scalar = BigNum::of('7');

        $manual = Point::infinity();

        for ($i = 0; $i < 7; $i++) {
            $manual = Curve::add($manual, $generator);
        }

        $this->assertTrue(Curve::multiply($generator, $scalar)->equals($manual));
    }

    public function test_point_and_its_negation_sum_to_infinity(): void
    {
        $generator = Curve::generator();
        $p = Curve::p();

        $negated = new Point(
            $generator->x,
            BigNum::sub($p, $generator->y, $p),
        );

        $this->assertTrue(Curve::add($generator, $negated)->isInfinity());
    }

    public function test_generated_key_pair_produces_a_valid_point(): void
    {
        $pair = Ecdh::generateKeyPair();

        $raw = Ecdh::b64d($pair['public']);

        $this->assertSame(65, strlen($raw));
        $this->assertSame("\x04", $raw[0]);
        $this->assertTrue(Curve::isOnCurve(Ecdh::decodePoint($raw)));
    }

    public function test_public_key_can_be_rederived_from_private(): void
    {
        $pair = Ecdh::generateKeyPair();

        $this->assertSame($pair['public'], Ecdh::publicFromPrivate($pair['private']));
    }

    /**
     * خاصیتِ اصلیِ ECDH: هر دو طرف باید به یک رازِ یکسان برسند.
     */
    public function test_both_sides_derive_the_same_secret(): void
    {
        $alice = Ecdh::generateKeyPair();
        $bob = Ecdh::generateKeyPair();

        $fromAlice = Ecdh::sharedSecret($alice['private'], Ecdh::b64d($bob['public']));
        $fromBob = Ecdh::sharedSecret($bob['private'], Ecdh::b64d($alice['public']));

        $this->assertSame(32, strlen($fromAlice));
        $this->assertSame($fromAlice, $fromBob);
    }

    public function test_different_peers_give_different_secrets(): void
    {
        $alice = Ecdh::generateKeyPair();
        $bob = Ecdh::generateKeyPair();
        $mallory = Ecdh::generateKeyPair();

        $this->assertNotSame(
            Ecdh::sharedSecret($alice['private'], Ecdh::b64d($bob['public'])),
            Ecdh::sharedSecret($alice['private'], Ecdh::b64d($mallory['public'])),
        );
    }

    /**
     * ‎⚠️ این تست جلوی یک باگِ واقعیِ پیاده‌سازی را می‌گیرد: مختصات باید
     * دقیقاً ۳۲ بایت باشند. اگر صفرِ ابتدایی حذف شود، نقطه ۶۵ بایتی
     * نمی‌شود و مرورگر رمزگشایی را رد می‌کند.
     */
    public function test_small_coordinates_are_zero_padded_to_32_bytes(): void
    {
        $encoded = Ecdh::encodePoint(new Point(BigNum::of('1'), BigNum::of('2')));

        $this->assertSame(65, strlen($encoded));
        $this->assertSame(str_repeat("\x00", 31), substr($encoded, 1, 31));
        $this->assertSame("\x01", $encoded[32]);
    }

    public function test_off_curve_point_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Ecdh::decodePoint(str_repeat("\x01", 65));
    }

    public function test_truncated_point_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Ecdh::decodePoint("\x04too-short");
    }

    public function test_zero_scalar_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $peer = Ecdh::generateKeyPair();

        Ecdh::sharedSecret(
            Ecdh::b64u(str_repeat("\x00", 32)),
            Ecdh::b64d($peer['public']),
        );
    }

    /**
     * عملکرد باید در حدی بماند که بشود در مسیر درخواست استفاده‌اش کرد.
     *
     * اگر کسی `inv` را برگرداند به «توانِ p−2»، هر ECDH حدود ۱.۲ ثانیه
     * می‌شود و این تست می‌افتد.
     */
    public function test_a_single_ecdh_is_fast_enough(): void
    {
        $start = microtime(true);

        $pair = Ecdh::generateKeyPair();

        Ecdh::sharedSecret($pair['private'], Ecdh::b64d($pair['public']));

        $this->assertLessThan(2.0, microtime(true) - $start);
    }
}