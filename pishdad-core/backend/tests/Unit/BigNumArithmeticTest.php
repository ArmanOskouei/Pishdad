<?php

namespace Tests\Unit;

use App\Services\Push\BigNum;
use PHPUnit\Framework\TestCase;

/**
 * تست حساب پیمانه‌ای `BigNum`.
 *
 * ## چرا جداگانه تست می‌شود
 *
 * `BigNum` پایهٔ کل رمزنگاری push است. یک خطای کوچک اینجا — مثلاً یک صفرِ
 * جاافتاده در الگوریتم تقسیم — به‌جای یک باگِ قابل‌ردیابی، به پیام‌های
 * رمزنگاری‌نشده یا بدتر منجر می‌شود. پس اول این لایه، جدا، با اعداد
 * واقعی کتابخانه قفل می‌شود.
 */
class BigNumArithmeticTest extends TestCase
{
    /**
     * p برای P-256 — به شکل hex با پیشوند `0x`.
     *
     * ‎⚠️ `bcmath` ورودی ده‌دهی می‌خواهد؛ رشتهٔ hexِ بدون پیشوند را
     * بی‌سروصدا رد می‌کند. `BigNum::norm()` خودش تبدیل می‌کند.
     */
    private const P = '0xffffffff00000001000000000000000000000000ffffffffffffffffffffffff';

    public function test_addition(): void
    {
        $this->assertSame('5', (string) BigNum::add('2', '3', self::P));
    }

    /** `p - 1` به شکل hex. */
    public function test_subtraction_wraps_at_the_modulus(): void
    {
        $this->assertSame(
            '115792089210356248762697446949407573530086143415290314195533631308867097853950',
            (string) BigNum::sub('0', '1', self::P),
        );
    }

    public function test_multiplication_wraps_at_the_modulus(): void
    {
        $this->assertSame('0', (string) BigNum::mul(self::P, '7', self::P));
    }

    public function test_division(): void
    {
        $this->assertSame('14', (string) BigNum::div('100', '7', self::P));
    }

    public function test_inverse_multiplies_back_to_one(): void
    {
        $a = BigNum::of('12345678901234567890');
        $inverse = BigNum::inv($a, self::P);

        $this->assertSame('1', (string) BigNum::mul($a, $inverse, self::P));
    }

    /**
     * قاعدهٔ Fermat: برای `p` اول، `a^(p-1) ≡ 1 (mod p)`.
     *
     * این تست هم درستیِ `pow` را می‌سنجد و هم به‌طور غیرمستقیم درستیِ
     * کل محاسبات پیمانه‌ای را.
     */
    public function test_fermars_little_theorem(): void
    {
        $a = BigNum::of('12345678901234567890');
        $pMinusOne = BigNum::sub(self::P, '1', self::P);

        $this->assertSame('1', (string) BigNum::pow($a, $pMinusOne, self::P));
    }

    public function test_fermat_inverse_agrees_with_extended_gcd(): void
    {
        $a = BigNum::of('98765432109876543210');
        $pMinusTwo = BigNum::sub(self::P, '2', self::P);

        $this->assertEquals(
            (string) BigNum::inv($a, self::P),
            (string) BigNum::pow($a, $pMinusTwo, self::P),
        );
    }

    /** ترتیب برگشتی: [باقی‌مانده, خارج‌قسمت] */
    public function test_divmod(): void
    {
        [$remainder, $quotient] = BigNum::divMod('1000', '7');

        $this->assertSame('6', (string) $remainder);
        $this->assertSame('142', (string) $quotient);
    }

    public function test_leading_zeros_are_normalised(): void
    {
        $this->assertSame('7', (string) BigNum::of('007'));
        $this->assertSame('0', (string) BigNum::of('000'));
    }

    public function test_inverting_zero_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BigNum::inv(0, self::P);
    }

    public function test_dividing_by_zero_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BigNum::div('10', '0', self::P);
    }

    /**
     * بیت‌ها باید از پر‌ارزش‌ترین تا کم‌ارزش‌ترین و با عرضِ درخواستی باشند.
     *
     * عرض مهم است: `norm` صفرهای ابتدایی را حذف می‌کند، پس بدون عرض
     * ثابت، عدد `8` به‌جای چهار بیت فقط یک بیت برمی‌گرداند.
     */
    public function test_bits_are_returned_most_significant_first(): void
    {
        $this->assertSame('1000', $this->bitsOf('8', 4));
        $this->assertSame('101', $this->bitsOf('5', 3));
        $this->assertSame('11', $this->bitsOf('3', 2));
        $this->assertSame('1', $this->bitsOf('1', 1));
        $this->assertSame('0101', $this->bitsOf('5', 4));
        $this->assertSame('0000', $this->bitsOf('0', 4));
    }

    /**
     * ‎2^255 باید ۲۵۶ بیت داشته باشد — یعنی عرضِ کامل P-256.
     *
     * این تست جلوی یک باگِ مرگبار را می‌گیرد: اگر `bits()` بیت‌ها را از
     * سمت کم‌ارزش شروع کند، الگوریتم ضرب اسکالر خروجیِ کاملاً اشتباه
     * می‌دهد و *بی‌سروصدا* — بدون هیچ خطایی.
     */
    public function test_bits_keep_full_256_bit_width(): void
    {
        $this->assertCount(256, BigNum::pow('2', '255', self::P)->bits());
    }

    public function test_parity(): void
    {
        $this->assertTrue(BigNum::of('4')->isEven());
        $this->assertTrue(BigNum::of('0')->isEven());
        $this->assertFalse(BigNum::of('7')->isEven());
        $this->assertFalse(BigNum::of('1')->isEven());
    }

    private function bitsOf(string $n, int $width): string
    {
        return implode('', array_map('strval', BigNum::of($n)->bits($width)));
    }
}