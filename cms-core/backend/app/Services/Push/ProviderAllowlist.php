<?php

namespace App\Services\Push;

/**
 * فهرستِ سفیدِ دامنه‌های ارائه‌دهندهٔ Push (محافظ SSRF).
 *
 * ## چرا این کلاس جدا است
 *
 * gate مسیرِ **ثبت** (کنترلر) به‌تنهایی کافی نبود: دادهٔ endpoint می‌تواند
 * از راه‌های دیگر (seed، کار قدیمی، import) وارد پایگاه‌داده شود و بعد
 * `PushSender::post()` آن را بدون هیچ بررسی‌ای POST می‌کند. پس همان فهرست
 * باید در **هر دو** نقطه — نوشتن وsink ارسال — اعمال شود و تنها یک
 * تعریف داشته باشد.
 *
 * فقط HTTPS هم کافی نیست، چون سرویس‌های داخلیِ HTTPS هم وجود دارند؛
 * پس فهرستِ سفیدِ **دامنه** لازم است.
 */
class ProviderAllowlist
{
    /**
     * دامنه‌های مجاز برای `endpoint`.
     *
     * فقط همین دو ارائه‌دهنده پشتیبانی می‌شوند:
     *
     * • Mozilla/Autotrigger — ‎`push.services.mozilla.com`
     * • Chrome/Edge (FCM) — ‎`fcm.googleapis.com`
     *
     * ⛔ **Windows/WNS (`notify.windows.com`) عمداً در فهرست نیست.**
     *
     * WNS اصلاً VAPID را نمی‌پذیرد: احراز هویتش یک توکنِ OAuth
     * (`Authorization: Bearer`) است که باید از endpointِ کلاینت گرفته و باز
     * شود، و آدرسش هم **نسبی** است (`/notify/?token=…`). آن زمان در این
     * بیلد یک شاخهٔ ساختگی وجود داشت که همان هدرِ `vapid` را برمی‌گرداند —
     * یعنی هر اشتراکِ Edge بی‌صدا ۴۰۱ می‌گرفت و هیچ‌وقت اعلانی نمی‌رسید.
     *
     * پس به‌جای نگه‌داشتنِ مسیری که کار نمی‌کند، **fail-closed** شد: رد در
     * مسیرِ ثبت (۴۲۲ با پیامِ روشن) و رد در sinkِ ارسال. حذفِ سطرِ سرویس از
     * فهرست باید همراه با پیاده‌سازیِ واقعیِ OAuth و مسیرِ نسبی انجام شود، نه
     * به‌تنهایی — تا کسی دوباره صرفاً یک خط به این آرایه اضافه نکند.
     *
     * این فهرست عمداً **بسته** است: هر دامنهٔ دیگری باید اول به کد
     * اضافه شود تا provider مربوطه هم مسیر رمزگذاری/ارسالش تأیید شود.
     */
    public const PROVIDER_HOST_SUFFIXES = [
        'push.services.mozilla.com',
        'fcm.googleapis.com',
    ];

    /**
     * آیا `endpoint` روی یکی از دامنه‌های مجاز است؟
     *
     * تطابق «دقیق یا زیردامنه» است: نقطه را صریح چک می‌کنیم تا
     * `evil-example.com` با `example.com` قبول نشود.
     */
    public static function allows(string $endpoint): bool
    {
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        foreach (self::PROVIDER_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /** میزبانِ استخراج‌شده، برای لاگ و پیام خطا. */
    public static function host(string $endpoint): string
    {
        return strtolower((string) parse_url($endpoint, PHP_URL_HOST));
    }
}