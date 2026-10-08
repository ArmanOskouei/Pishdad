<?php

namespace App\Services\Sms;

/**
 * کانال پیامک (E3).
 *
 * ⭐ قراردادِ مهم: `send()` برمی‌گرداند **آیا واقعاً تحویل داده شد یا نه**.
 * `false` یعنی «نرسید» — نه اینکه exception بدهد. چون `Outbox::deliver()` برای
 * هر پیام یک تصمیم می‌گیرد؛ اگر درایور exception بدهد هر پیام retry می‌شود و
 * پس از `max_attempts` می‌میرد، در حالی که «اصلاً درگاهی نیست» یک شرایطِ
 * پایدار است و retry بی‌فایده.
 *
 * بنابراین: خطایِ *پیکربندی* exception می‌دهد (در `SmsDriverFactory`)، و
 * خطایِ *ارسال* فقط `false` برمی‌گرداند.
 */
interface SmsSenderInterface
{
    /** نامِ درایور، برای لاگ و برای `pishdad:doctor`. */
    public function driverName(): string;

    /**
     * آیا این درایور واقعاً پیام را به دستِ گوشی می‌رساند؟
     *
     * `false` برای `null` و `log` — یعنی «نه». مصرف‌کننده باید این را
     * بداند تا «ارسال شد» ننویسد.
     */
    public function delivers(): bool;

    /**
     * تلاش برای ارسال.
     *
     * @return bool true = تحویل داده شد. false = نرفت (و `lastError()` چرا).
     */
    public function send(string $to, string $text): bool;

    /** آخرین دلیلِ شکست، برای لاگ و پاسخِ `doctor`. */
    public function lastError(): ?string;
}