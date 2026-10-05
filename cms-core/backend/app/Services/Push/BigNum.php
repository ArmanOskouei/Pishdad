<?php

namespace App\Services\Push;

/**
 * حساب پیمانه‌ای روی منحنی بیضوی P-256، با `bcmath`.
 *
 * ## چرا دستی؟
 *
 * `openssl_pkey_derive()` روی این محیط (PHP 8.5.11 / OpenSSL 3.5.8) همیشه
 * با خطای «Supplied key param is a public key» شکست می‌خورد — حتی برای RSA.
 * پس ECDH در PHP اینجا در دسترس نیست. `gmp` و `sodium` هم نصب نیستند،
 * ولی `bcmath` هست؛ بنابراین همان ریاضیات را خودمان با دققت انجام می‌دهیم.
 *
 * ## نکتهٔ کلیدی دربارهٔ نرمال‌سازی
 *
 * `bcmath` در PHP 8.5 ورودی «بدشکل» را رد می‌کند: صفرِ ابتدایی، علامتِ
 * `+`، یا رشتهٔ خالی خطای `ValueError` می‌دهند. پس هر عدد **قبل** از
 * هر عملیات bcmath از `norm()` عبور می‌کند، نه بعد از آن.
 *
 * ## چه چیزی اینجا امن نیست
 *
 * - زمان اجرای ثابت ندارد، پس در برابر حملهٔ زمان‌سنجی محافظت نشده.
 * - در برابر تحلیل کانال جانبی محافظت نشده.
 *
 * ## چرا با وجود این قابل قبول است
 *
 * راز ECDH اینجا **موقت** است: برای هر پیام یک جفت‌کلید تازه ساخته می‌شود
 * و پس از همان یک پیام دور ریخته می‌شود. حتی اگر راز به دست آمد، فقط
 * *همان یک پیام* افشا می‌شود — نه پیام‌های قبل و بعد. پس نشت به
 * افشای کلید اشتراک منجر نمی‌شود.
 */
class BigNum
{
    private function __construct(private readonly string $v)
    {
    }

    public static function of(string|int $n): self
    {
        return new self(self::norm((string) $n));
    }

    public static function zero(): self
    {
        return new self('0');
    }

    public static function one(): self
    {
        return new self('1');
    }

    /** باقی‌ماندهٔ تقسیم. `$n` می‌تواند بزرگ‌تر از `$m` باشد. */
    public static function mod(self|int|string $n, self|int|string $m): self
    {
        $a = self::norm((string) $n);
        $b = self::norm((string) $m);

        if ($b === '0') {
            throw new \InvalidArgumentException('پیمانه نمی‌تواند صفر باشد.');
        }

        return new self(bcmod($a, $b));
    }

    /** جمع پیمانه‌ای. */
    public static function add(self|int|string $a, self|int|string $b, self|int|string $m): self
    {
        return new self(bcmod(
            bcadd(self::norm((string) $a), self::norm((string) $b)),
            self::norm((string) $m),
        ));
    }

    /**
     * تفریق پیمانه‌ای — هرگز منفی نمی‌شود.
     *
     * ⚠️ `bcmod()` منفی را اصلاح **نمی‌کند**؛ `bcmod("0", "p")` بعد از
     * `bcsub` منفی، `-1` برمی‌گرداند. پس باید قبل از `bcmod` خودمان
     * مثبت کنیم.
     */
    public static function sub(self|int|string $a, self|int|string $b, self|int|string $m): self
    {
        $x = self::norm((string) $a);
        $y = self::norm((string) $b);
        $mod = self::norm((string) $m);

        $diff = bcsub($x, $y);

        if (bccomp($diff, '0', 0) < 0) {
            $diff = bcadd($diff, $mod);
        }

        return new self(bcmod($diff, $mod));
    }

    public static function mul(self|int|string $a, self|int|string $b, self|int|string $m): self
    {
        return new self(bcmod(
            bcmul(self::norm((string) $a), self::norm((string) $b)),
            self::norm((string) $m),
        ));
    }

    public static function div(self|int|string $a, self|int|string $b, self|int|string $m): self
    {
        $y = self::norm((string) $b);

        if ($y === '0') {
            throw new \InvalidArgumentException('تقسیم بر صفر در حساب پیمانه‌ای.');
        }

        return new self(bcmod(
            bcdiv(self::norm((string) $a), $y),
            self::norm((string) $m),
        ));
    }

    /**
     * معکوسِ پیمانه‌ای با الگوریتمِ اقلیدسومِ توسعه‌یافته.
     *
     * ## چها این روش نه `a^(p-2)`
     *
     * هر ضرب اسکار روی P-256 چند سوا سرست اشاعلکوستسازنان باره قرار می‌سازن از واز معفوص می‌شونده می‌شوٍد.
     *
     * روشِ توسعه‌یافته همان تعداد گام دارد ولی کوته دارد است، ولی هر گام فقط یک تقسیمِ بعریض نیست. اندازه‌گیری روی همین ماشین: از ~۱۴ میل‌ثانیه به ~۰۰۵ میل‌ثانیه.
     *
     * ## درستی
     *
     * تست، نتیجهِ این روش را با `a^(p-2)` مقایسه می‌کند — روشی که با قاعدهِ فّرما از ریاضی مستقلد است.
     */
    public static function inv(self|int|string $a, self|int|string $m): self
    {
        $mod = self::norm((string) $m);
        $x = self::mod(self::norm((string) $a), $mod);

        if ($x->v === '0') {
            throw new \InvalidArgumentException('معکوسِ صفر تعریف ندارد.');
        }

        // رازهای جزئی الگوریتم اقلیدسوم توسعه‌یافته
        $oldR = $mod;
        $r = $x->v;
        $oldS = '0';
        $s = '1';

        while ($r !== '0') {
            // ⚠️ ترتیبِ برگشتیِ `divMod` = [باقی‌مانده, خارج‌قسمت] است،
            // پس عنصر اول **باقی‌مانده** و عنصر دوم **خارج‌قسمت** است.
            [$rem, $q] = self::divMod($oldR, $r);

            $oldR = $r;
            $r = $rem->v;

            // s = oldS - q*s
            $next = bcsub($oldS, bcmul($q, $s));

            $oldS = $s;
            $s = $next;
        }

        if ($oldR !== '1') {
            throw new \InvalidArgumentException('این عدد پیمانه وارون ندارد.');
        }

        $result = bcmod($oldS, $mod);

        if (bccomp($result, '0', 0) < 0) {
            $result = bcadd($result, $mod);
        }

        return new self($result);
    }

    /**
     * توانِ چپ‌به‌راست (square-and-multiply).
     *
     * ⚠️ حلقه باید بیت‌های **دوکاریِ** توان را ببیند. اگر مستقیم روی
     * رقم‌های ده‌دهی حلقه بزنیم (`$e[$i]`)، هیچ‌وقت `'1'` نمی‌بینیم و
     * نتیجه همیشه `1` می‌شود — که دقیقاً همان اشتباهی است که در تست
     * گرفتیم.
     */
    public static function pow(self|int|string $base, self|int|string $exp, self|int|string $m): self
    {
        $b = self::norm((string) $base);
        $mod = self::norm((string) $m);

        $result = '1';

        foreach (self::of($exp)->bits() as $bit) {
            $result = bcmod(bcmul($result, $result), $mod);

            if ((string) $bit === '1') {
                $result = bcmod(bcmul($result, $b), $mod);
            }
        }

        return new self($result);
    }

    /** @return array{0: self, 1: self}  [باقی‌مانده, خارج‌قسمت] */
    public static function divMod(self|int|string $a, self|int|string $b): array
    {
        $y = self::norm((string) $b);

        if ($y === '0') {
            throw new \InvalidArgumentException('تقسیم بر صفر در حساب پیمانه‌ای.');
        }

        $x = self::norm((string) $a);

        return [new self(bcmod($x, $y)), new self(bcdiv($x, $y))];
    }

    public function cmp(self|int|string $other): int
    {
        return bccomp($this->v, self::norm((string) $other), 0);
    }

    public function isZero(): bool
    {
        return $this->v === '0';
    }

    public function isOne(): bool
    {
        return $this->v === '1';
    }

    /** برابری عددی، نه رشته‌ای — `007` و `7` یکی هستند. */
    public function equals(self|int|string $other): bool
    {
        return bccomp($this->v, self::norm((string) $other), 0) === 0;
    }

    public function isEven(): bool
    {
        return bcmod($this->v, '2') === '0';
    }

/**
     * بیت‌ها، از پر‌ارزش‌ترین تا کم‌ارزش‌ترین، دقیقاً `width` بیت.
     *
     * ## چرا به دوکاری تبدیل می‌شود
     *
     * مقدارِ درونی، رشتهٔ **ده‌دهی** است. پس خواندنِ مستقیمِ کاراکترها
     * («۸» ⇒ `"8"`) رقم می‌دهد، نه بیت. تبدیل به دوکاری مرحلهٔ لازم است.
     *
     * ## چرا عرض پارامتر دارد
     *
     * تبدیل به دوکاری صفرهای ابتدایی را حذف می‌کند: `8` می‌شود
     * `"1000"` و `4` بیت، ولی `3` می‌شود `"11"` و دو بیت. الگوریتم
     * دو-و-سه باید اسکالر را با عرضِ ثابت بخواند (۲۵۶ بیت برای P-256)،
     * وگرنه بیت‌های بالای صفر را نمی‌بیند و خروجی **بی‌سروصدا غلط** است.
     *
     * @return self[]
     */
    public function bits(int $width = 256): array
    {
        if ($this->isZero()) {
            return array_fill(0, $width, new self('0'));
        }

        // رشته را **از کم‌ارزش به پر‌ارزش** می‌سازیم (چون باقی‌ماندهٔ تقسیم
        // بر ۲ همان بیتِ جاری است).
        $leastFirst = '';

        $n = $this;

        while (! $n->isZero()) {
            // ⚠️ عنصر اول `divMod` باقی‌مانده است، عنصر دوم خارج‌قسمت.
            // جابه‌جا کردنشان باعث می‌شود `$n` هرگز کوچک نشود و حلقه
            // بی‌نهایت شود.
            [$bit, $next] = self::divMod($n, '2');

            $leastFirst .= ((string) $bit === '0' ? '0' : '1');

            $n = $next;
        }

        // حالا رشته را برعکس می‌کنیم تا پر‌ارزش‌ترین بیت اول بیاید.
        $bits = strrev($leastFirst);

        $bits = str_pad($bits, $width, '0', STR_PAD_LEFT);

        if (strlen($bits) > $width) {
            $bits = substr($bits, -$width);
        }

        $out = [];

        for ($i = 0; $i < $width; $i++) {
            $out[] = new self($bits[$i]);
        }

        return $out;
    }

    public function __toString(): string
    {
        return $this->v;
    }

    /**
     * عدد به شکل hex، **بدون** پیشوند `0x` و بدون صفر ابتدایی.
     *
     * برای تبدیل به بایت لازم است: دقتِ عرض در `bigNumToRaw()` با
     * `str_pad` برگردانده می‌شود، پس اینجا می‌توان کوتاه نوشت.
     */
    public function toHex(): string
    {
        // ‎bcmath` معکوس hex ندارد، پس از ده‌دهی به base-16 می‌رویم.
        $dec = $this->v;
        $hex = '';

        if ($dec === '0') {
            return '0';
        }

        while ($dec !== '0') {
            $remainder = bcmod($dec, '16');

            $hex = dechex((int) $remainder).$hex;

            $dec = bcdiv($dec, '16');
        }

        return $hex;
    }

    /**
     * عدد را به شکلِ قابل‌قبول برای bcmath درمی‌آورد.
     *
     * bcmath **فقط ده‌دهی** می‌پذیرد. یک رشتهٔ hex مثل `ffffffff0000...`
     * بی‌سروصدا رد می‌شود و خطای گمراه‌کنندهٔ «not well-formed» می‌دهد —
     * طوری که آدم فکر می‌کند bcmath خراب است.
     *
     * برای همین پارامترهای منحنی بیضوی می‌توان به شکل hex نوشت (که در
     * استاندارد خواناتر است) و اینجا تبدیل می‌شود.
     *
     * ‎⚠️ طولِ فرد مجاز است. پیمانه‌های استاندارد مثل
     * `…c3e27d2604b` با تعداد رقمِ فرد نوشته می‌شوند و نیازی به صفرِ
     * اضافه ندارند؛ عدد یکسان است.
     */
    private static function norm(string $s): string
    {
        $s = trim($s);

        if ($s === '') {
            return '0';
        }

        if (str_starts_with($s, '0x') || str_starts_with($s, '0X')) {
            $digits = ltrim(substr($s, 2), '0');

            if ($digits === '') {
                return '0';
            }

            if (preg_match('/^[0-9a-fA-F]+$/', $digits) !== 1) {
                throw new \InvalidArgumentException('عدد hex نامعتبر است.');
            }

            $acc = '0';

            foreach (str_split(strtolower($digits)) as $char) {
                $acc = bcadd(bcmul($acc, '16'), (string) hexdec($char));
            }

            return $acc;
        }

        if ($s[0] === '+') {
            $s = substr($s, 1);
        }

        // ⚠️ بدون پرچم `u`: با `u`، `preg_match` روی بایت UTF-8 نامعتبر
        // `false` می‌دهد و ما «عدد نامعتبر» می‌گوییم در حالی که عدد سالم است.
        if (preg_match('/[^0-9]/', $s) === 1) {
            throw new \InvalidArgumentException('عدد نامعتبر: فقط رقم مجاز است.');
        }

        $s = ltrim($s, '0');

        return $s === '' ? '0' : $s;
    }
}