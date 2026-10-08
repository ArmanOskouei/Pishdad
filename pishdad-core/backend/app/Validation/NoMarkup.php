<?php

namespace App\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * F0.1 — رد markup در فیلدهای متنیِ سطح‌اول.
 *
 * چرا لازم است وقتی فرانت هم escape می‌کند: هر رندرر تازه‌ای که اضافه شود
 * (بلوک پلاگینی، ویجت، ایمیل) دوباره به همان فیلد می‌رسد. اگر فقط فرانت
 * پاک‌سازی کند، هر رندرر بعدی یا باید sanitize را به یاد بیاورد یا سوراخ است.
 * این قاعده **پیش‌فرض امن** را در لحظهٔ نوشتن می‌سازد، جایی که یک‌بار برای همیشه
 * اجرا می‌شود.
 *
 * عمداً فقط `<` را می‌گیرد، نه `>`:
 *   - باز کردن تگ برای مرورگر با `<` شروع می‌شود و بستنش اختیاری است
 *     (`<script` به‌تنهایی کافی است) ⇒ مسدودکردن `<` تمام بردارها را می‌بندد.
 *   - `>` به‌تنهایی در متن فارسی و ریاضی رایج است («پلن A > B»). بستنش یعنی
 *     بستن نویسندهٔ فارسی برای هیچ سود امنیتی.
 *   - لایهٔ رندر هم `<` را escape می‌کند (`safeJsonLd` و `safeHtml`)، پس دفاع
 *     در عمق سر جایش هست.
 *
 * به فیلدهایی که *باید* markup داشته باشند (متن غنی Jodit در بلوک‌ها) اعمال
 * نمی‌شود — آن‌ها مسیر جدا با `safeHtml` دارند.
 */
final class NoMarkup implements ValidationRule
{
    /**
     * هستهٔ تصمیم. جدا از `validate()` است چون `Validator::extend()` اساساً
     * `bool` می‌خواهد: اگر extension یک شیء برگرداند، آن شیء truthy است و
     * **همه‌چیز رد می‌شود** — یعنی قاعده بی‌اثر ولی ظاهراً فعال.
     */
    public static function passes(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return true;
        }

        return ! str_contains($value, '<');
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::passes($value)) {
            $fail('این مقدار نباید شامل علامت «<» باشد.');
        }
    }
}
