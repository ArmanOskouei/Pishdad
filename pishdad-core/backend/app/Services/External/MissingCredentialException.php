<?php

namespace App\Services\External;

use RuntimeException;

/**
 * E2/E3/E4 — «درایور انتخاب شده ولی کلیدِ لازم نیست».
 *
 * ## چرا exception و نه فقط یک هشدار
 *
 * سه حالت را نباید یکی گرفت:
 *
 *  ۱. **درگاه نداریم** (`null`/`stub`/`log`) — وضعیتِ شناخته‌شده و صادقانه است.
 *     کاربر می‌داند چیزی فرستاده نشده. ← نیازی به exception ندارد.
 *  ۲. **درگاه را انتخاب کرده‌ایم ولی کلید نیست** — این تنظیمِ ناقص است. با
 *     `Log::warning` رد کردن یعنی هر پیام یک خط در لاگ و بعد فراموشی؛ بعد از
 *     سه ماه معلوم می‌شود «ارسال شد» روی صفحه بوده و هیچ‌وقت نرسیده.
 *     exception یعنی تنظیم بلافاصله و با نامِ دقیقِ کلید گرفته می‌شود.
 *  ۳. **کلید هست ولی غلط** — exception ما کمکی نمی‌کند؛ آن را باید دید با
 *     تلاشِ واقعی. برای هم `pishdad:doctor` وضعیت را گزارش می‌کند و تلاشِ
 *     شبکه‌ای نمی‌کند (که در نصبِ بدون اینترنت کند و گمراه‌کننده است).
 *
 * ## چرا پیام فارسی
 *
 * پیام این exception به اپراتورِ فارسی‌زبانِ سرور می‌رسد، نه به لاگِ لاتین.
 * نامِ کلید لاتین می‌ماند (چون در `.env` همان است) ولی جمله فارسی است تا
 * بداند **کجا** را باید باز کند.
 */
class MissingCredentialException extends RuntimeException
{
    /**
     * @param  list<string>  $keys  کلید(های) محیطیِ جاافتاده — بدون پیشوند `$`.
     */
    public function __construct(
        public readonly string $dependency,
        public readonly string $driver,
        public readonly array $keys,
    ) {
        parent::__construct(self::compose($dependency, $driver, $keys));
    }

    /**
     * @param  list<string>  $keys
     */
    public static function compose(string $dependency, string $driver, array $keys): string
    {
        $one = count($keys) === 1;

        return sprintf(
            'پیکربندی «%s» ناقص است: درایور «%s» انتخاب شده اما %s در متغیرهای محیطی تنظیم نشده — %s. '
            .'تا وقتی این کلید(ها) را در فایل .env مقدار ندهید هیچ پیامی بیرون نمی‌رود؛ '
            .'یا کلید(های) بالا را پر کنید، یا درایور بی‌خطر را انتخاب کنید.',
            $dependency,
            $driver,
            $one ? 'کلید زیر تنظیم نشده' : 'کلیدهای زیر تنظیم نشده‌اند',
            implode('، ', array_map(static fn (string $k): string => "`{$k}`", $keys)),
        );
    }
}