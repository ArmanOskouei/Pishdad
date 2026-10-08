<?php

namespace App\Services\Sms;

/**
 * درایور پیش‌فرض: هیچ پیامکی بیرون نمی‌رود.
 *
 * این درایور **عمداً بی‌صدا نیست**: هر تلاش یک خط `log` می‌نویسد تا اگر کسی
 * جایی `Outbox::sms()` را صدا زد، بداند پیام گم شد. ولی exception نمی‌دهد،
 * چون «پیامک نداریم» یک تصمیمِ آگاهانه است، نه خرابی.
 */
class NullSmsDriver implements SmsSenderInterface
{
    private ?string $lastError = null;

    public function driverName(): string
    {
        return 'null';
    }

    public function delivers(): bool
    {
        return false;
    }

    public function send(string $to, string $text): bool
    {
        $this->lastError = 'درگاه پیامک فعال نیست؛ SMS_DRIVER=null است و هیچ پیامکی بیرون نمی‌رود.';

        \Illuminate\Support\Facades\Log::notice('sms.null_driver_dropped', [
            'to' => $to,
            'length' => mb_strlen($text),
        ]);

        return false;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }
}