<?php

namespace App\Validation;

use Illuminate\Validation\ValidationException;

/**
 * F0.2 — منبع حقیقت allowlist نشانی در سمت بک‌اند.
 *
 * چرا این کلاس وجود دارد و نه یک regex دیگر در هر کنترلر:
 * پیش از این، سه پیاده‌سازی جدا وجود داشت و هر سه ناقص بودند:
 *   - `LinkItems::isValidHref` — `//evil.com` را قبول می‌کرد (بدون lookahead)
 *   - `BlockRenderer.HREF_OK` (فرانت) — `mailto:` و `tel:` را نداشت
 *   - `Chrome.tsx` ویجت `cta` — اصلاً اعتبارسنجی نمی‌شد
 *
 * یعنی هر رندرر تازه‌ای که نوشته می‌شد، یا باید `safeHref` را به یاد می‌آورد یا
 * سوراخ بود — و هیچ‌کس بازبینی نمی‌کرد. یک allowlist سست در جای اشتباه، رندرر
 * تازه را بدون دفاع رها می‌کند **و این قابل تشخیص نیست** چون ظاهرش مثل بقیه است.
 *
 * بنابراین: **روی نوشتن، یک‌بار و برای همیشه** (این کلاس). روی خواندن/رندر هم
 * یک لایه هست (فرانت) برای داده‌ای که پیش از این patch در DB نشسته — ولی آن
 * لایه دفاع عمقی است، نه منبع حقیقت.
 *
 * الگو مو به مو از `BlockRenderer.tsx` پورت شده: هر سه جزء با هم —
 *   1. lookahead `(?!\/)` برای رد `//evil.com` و `/\evil.com`
 *   2. حذف نویسه‌های کنترلی **فقط برای آزمون**، نه برای رشتهٔ خروجی؛ بدون این
 *      `java\tscript:` زنده می‌ماند چون `\t` داخل رشته است و regex پروتکل را
 *      دور می‌زند
 *   3. `?` و `tel:` که بک‌اند از قبل می‌پذیرفت و فرانت نداشت
 */
final class SafeUrl
{
    /**
     * پروتکل‌های مجاز برای `href`.
     *
     * `/` با lookahead رد می‌شود اگر بعدش `/` یا `\` باشد، تا:
     *   - `//evil.com`  (protocol-relative) رد شود
     *   - `/\evil.com`  رد شود — مرورگر `\` را مثل `/` تفسیر می‌کند و به
     *                    `https://evil.com` ناوبری می‌کند (open-redirect)
     */
    public const HREF_PATTERN = '~^(?:/(?![\/\\\\])|#|\?|https?://|mailto:|tel:)~iu';

    /** برای `src`: `mailto:`/`tel:`/`#` معنا ندارند و `data:` عمداً رد است. */
    public const SRC_PATTERN = '~^(?:\/(?![\/\\\\])|https?://)~iu';

    /** پروتکل‌هایی که هرگز مجاز نیستند — دفاع در برابر دور زدن الگو. */
    private const DENY_PREFIXES = ['javascript:', 'data:', 'vbscript:', 'file:'];

    public static function isAllowedHref(string $value): bool
    {
        return self::check($value, self::HREF_PATTERN);
    }

    public static function isAllowedSrc(string $value): bool
    {
        return self::check($value, self::SRC_PATTERN);
    }

    /**
     * هستهٔ تصمیم. نویسه‌های کنترلی برای آزمون حذف می‌شوند ولی رشتهٔ اصلی
     * دست‌نخورده می‌ماند تا ذخیره شود همان‌طور که کاربر وارد کرده.
     */
    private static function check(string $value, string $pattern): bool
    {
        $raw = trim($value);
        if ($raw === '') {
            return false;
        }

        // `javascript:` با هر حروف بزرگ/کوچک، و با فاصله/نویسه کنترلی تزریق‌شده
        // پیش از colon (`java\tscript:`).
        $probe = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', $raw));
        foreach (self::DENY_PREFIXES as $denied) {
            if (str_starts_with($probe, $denied)) {
                return false;
            }
        }

        return (bool) preg_match($pattern, (string) preg_replace('/[\x00-\x1f\x7f]/u', '', $raw));
    }

    /**
     * قاعدهٔ اعتبارسنجی قابل استفاده در `$request->validate()`.
     *
     * ⚠️ `Validator::extend()` یک `bool` می‌خواهد، نه یک شیء. برگرداندن شیء
     * یعنی truthy ⇒ **همه‌چیز رد می‌شود** و قاعده بی‌اثر ولی ظاهراً فعال
     * می‌ماند. به همین دلیل منطق در متد استاتیک است و extension فقط آن را
     * صدا می‌زند.
     *
     * @param  'href'|'src'  $kind
     */
    public static function rule(string $kind = 'href'): bool
    {
        return true; // فقط برای سازگاری؛ منطق در passes() است.
    }

    /** @param  'href'|'src'  $kind */
    public static function passes(mixed $value, string $kind = 'href'): bool
    {
        if (! is_string($value) || $value === '') {
            return true; // خالی = «تنظیم نشده»؛ نبودِ فیلد کاربر نیست.
        }

        return $kind === 'src' ? self::isAllowedSrc($value) : self::isAllowedHref($value);
    }

    /** پیام خطای فارسی برای هر kind. */
    public static function message(string $kind = 'href'): string
    {
        return $kind === 'src'
            ? 'نشانی تصویر/رسانه معتبر نیست (فقط مسیر داخلی یا https://).'
            : 'نشانی پیوند معتبر نیست (فقط مسیر داخلی، https://، یا mailto:/tel:).';
    }

    /**
     * بررسی و پرتاب مستقیم — برای مسیرهایی که با `validate()` کار نمی‌کنند
     * (مثل تنظیمات دیکودری‌شدهٔ ویجت که کلیدش پویا است).
     *
     * @throws ValidationException
     */
    public static function assertHref(string $field, mixed $value, string $label = 'نشانی پیوند'): void
    {
        if (is_string($value) && $value !== '' && ! self::isAllowedHref($value)) {
            throw ValidationException::withMessages([
                $field => "{$label} معتبر نیست (فقط مسیر داخلی، https://، یا mailto:/tel:).",
            ]);
        }
    }
}
