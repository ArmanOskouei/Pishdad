<?php

namespace App\Services\Media;

/**
 * WF-C4 — واسطِ موتور تصویر.
 *
 * چرا واسط: تولید WebP/AVIF به GD یا Imagick وابسته است و هیچ‌کدام در همهٔ
 * محیط‌ها نیستند (کانتینرِ این پروژه نه GD دارد نه Imagick). این واسط اجازه
 * می‌دهد موتور در زمان اجرا انتخاب شود و نبودشان یک پیادهٔ بی‌اثر
 * (`NullImageProcessor`) بدهد ⇒ آپلود هرگز نمی‌شکند.
 */
interface ImageProcessor
{
    /** نام موتور برای ثبت در فرادادهٔ `variants`. */
    public function name(): string;

    /** آیا موتور اصلاً کار می‌کند؟ */
    public function available(): bool;

    /**
     * قالب‌های خروجیِ پشتیبانی‌شده.
     *
     * @return array<string, string> نگاشت mime ⇒ پسوند (مثل `image/webp` ⇒ `webp`)
     */
    public function formats(): array;

    /** خواندن بایت‌ها به یک هندلِ تصویر؛ `null` یعنی قابل‌خواندن نبود (fail-soft). */
    public function load(string $bytes): mixed;

    /** عرض تصویر (پیکسل). */
    public function width(mixed $image): int;

    /**
     * تغییر اندازه به عرض `$width` (حفظ نسبت) و کدگذاری به قالب `$mime`.
     *
     * @return string|null بایت‌های کدشده، یا `null` اگر این قالب ممکن نبود.
     */
    public function encode(mixed $image, int $width, string $mime): ?string;

    /** آزادکردن منابع هندل. */
    public function release(mixed $image): void;
}
