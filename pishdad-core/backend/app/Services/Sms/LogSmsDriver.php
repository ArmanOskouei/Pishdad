<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * درایور لاگ — فقط توسعه و تست دستی.
 *
 * فرقش با `NullSmsDriver` یک چیز است و همان یک چیز مهم است: **متنِ کامل را
 * می‌نویسد**. برای تستِ دستیِ تأیید شماره (`sms/request` → `sms/verify`) لازم
 * است که کد را ببینیم. برای همین `delivers()` هم `false` است — چون هیچ‌کس
 * گوشی‌اش روشن نمی‌شود.
 *
 * ⚠️ در production نباید فعال باشد: متنِ پیام (که ممکن است کد تأیید یا
 * جزئیات مالی باشد) در لاگِ سرور می‌نشیند. `pishdad:doctor` این را `warn`
 * می‌دهد و در production آن را نادیده نگیرید.
 */
class LogSmsDriver implements SmsSenderInterface
{
    private ?string $lastError = null;

    public function driverName(): string
    {
        return 'log';
    }

    public function delivers(): bool
    {
        return false;
    }

    public function send(string $to, string $text): bool
    {
        $this->lastError = 'درایور لاگ فعال است؛ پیام فقط در لاگ نوشته شد و به هیچ گوشی‌ای نرسید.';

        Log::warning('sms.log_driver_not_delivered', [
            'to' => $to,
            'text' => $text,
            'hint' => 'این متن بیرون نرفته است — فقط برای تست دستی است.',
        ]);

        return false;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }
}