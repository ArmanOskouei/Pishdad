<?php

namespace App\Services\Billing;

/**
 * J10 — درایورِ «صورتحساب دستی»؛ جایگزینِ Stub زرین‌پال.
 *
 * ## چرا این درایور وجود دارد و چرا دروغ نمی‌گوید
 *
 * نسخهٔ قبلی (`ZarinpalStubDriver`) یک `authority` ساختگی با پیشوند `STUB-` و یک
 * آدرس `sandbox.zarinpal.com` می‌ساخت و پاسخ می‌داد «در حال انتقال به درگاه
 * پرداخت…». قابل‌اعتماد به نظر می‌رسید، ولی هیچ درگاهی وجود نداشت: کاربر کلیک
 * می‌کرد، به صفحه‌ای می‌رسید که هرگز پرداختی نمی‌کرد، و بعد می‌پرسید چرا فاکتور
 * پرداخت‌شده نشد.
 *
 * این همان بدترین حالت است: **ادعای جریانی که وجود ندارد**. نبودِ درگاه باید
 * از همان اول گفته شود، نه اینکه با لینک ساختگی پنهان شود.
 *
 * پس این درایور هیچ session نمی‌سازد: `authority` و `pay_url` هر دو `null` و
 * `manual => true`. مصرف‌کننده‌ها موظف‌اند این حالت را رندر کنند، نه اینکه URL
 * خالی را به کاربر نشان دهند.
 *
 * ## این یعنی چه برای محصول
 *
 * این درایور فقط برای بازارِ افزونه استفاده می‌شود: سفارش دستی تأیید می‌شود و
 * هیچ مبلغی از سامانه رد نمی‌شود. جایگزینی با درگاه واقعی یعنی پیاده‌سازیِ همین
 * اینترفیس و یک خط در `AppServiceProvider`.
 *
 * @see App\Services\Billing\PaymentGatewayInterface
 * @see App\Providers\AppServiceProvider
 */
class ManualBillingDriver implements PaymentGatewayInterface
{
    public function gatewayName(): string
    {
        return 'manual';
    }

    /**
     * هیچ session پرداختی ساخته نمی‌شود — و **هیچ چیزی جعل نمی‌شود**.
     *
     * کلید `manual` قراردادِ صریحِ این حالت است: مصرف‌کننده باید آن را بخواند و
     * پاسخِ «صورتحساب دستی است» بدهد. `authority`/`pay_url` عمداً `null` است تا
     * اگر روزی کسی این شاخه را نادیده گرفت، لینک `null` به کاربر نشان دهد و
     * باز هم دروغ نگوید.
     *
     * @return array{manual: true, authority: null, pay_url: null, callback_url: string, amount: int, description: string, message: string}
     */
    public function requestPayment(int $amount, string $description, string $callbackUrl, array $meta = []): array
    {
        return [
            'manual' => true,
            'authority' => null,
            'pay_url' => null,
            'callback_url' => $callbackUrl,
            'amount' => $amount,
            'description' => $description,
            'message' => 'صورتحساب دستی است',
        ];
    }
}
