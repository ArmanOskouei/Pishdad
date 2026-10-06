<?php

namespace App\Providers;

use App\Mail\MailSettings;
use Illuminate\Support\ServiceProvider;

/**
 * WF-H11 — اعمالِ تنظیمات SMTP ذخیره‌شده در زمان اجرا.
 *
 * F0.10 فقط پیکربندیِ مبتنی بر `.env` بود؛ این provider آن را به پنل می‌آورد:
 * اگر اپراتور در «تنظیمات → ایمیل» SMTP ذخیره کرده باشد، همان کانفیگ بر
 * `.env` اولویت می‌گیرد. در تست (که `MAIL_MAILER=array` اجباری است) کاری
 * نمی‌کند — منطقِ گارد داخلِ خودِ `MailSettings::applyToConfig()` است.
 */
class MailConfigServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        MailSettings::applyToConfig();
    }
}
