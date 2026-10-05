<?php

namespace App\Services\Subscription;

use App\Models\User;
use App\Services\Billing\PaymentGatewayInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * قراردادِ دادهٔ اشتراک — تنها نقطهٔ تماسِ هسته با دامنهٔ اشتراک.
 *
 * ## چرا این کلاس وجود دارد (L-B12)
 *
 * «اشتراک من» یک افزونهٔ **خصوصی و جدا** است (خواستهٔ صریح کاربر) و نباید در
 * repo عمومی باشد. ولی هسته باید بتواند دربارهٔ وضعیتِ اشتراک تصمیم بگیرد
 * (مثلاً `/site/status` و کارتِ داشبورد).
 *
 * این کلاس همان مرز است: هسته **قرارداد** را می‌شناسد، نه **پیاده‌سازی** را.
 *
 * ## چرا خودش هم import نمی‌کند
 *
 * اگر اینجا `use`ای به کلاسِ افزونه بود، هسته دوباره به افزونه وابسته می‌شد و
 * دقیقاً همان نشتی برمی‌گشت که L-B12 حذف کرد. پس نامِ کلاسِ پیاده‌سازی از
 * **مانیفستِ بستهٔ فعال** خوانده می‌شود، نه از کدِ هسته. در نبودِ بسته،
 * `NullSubscriptionResolver` جواب می‌دهد و نصب عمومی سالم می‌ماند.
 *
 * @see SubscriptionResolverInterface
 */
final class SubscriptionResolver
{
    /**
     * پیاده‌سازیِ فعالِ resolver — از **container** خوانده می‌شود.
     *
     * ## J12 — چرا دیگر اینجا هیچ نامی از resolverِ دور نیست
     *
     * پیش از J12 این متد مستقیماً با `remote_url` تصمیم می‌گرفت و
     * `RemoteSubscriptionResolver`/`HttpTransport` را **در هسته** می‌ساخت. یعنی
     * کدِ نسخهٔ رایگان به کلاس‌هایی ارجاع می‌داد که نباید در آن بیلد باشند —
     * یک مسیرِ کدِ اشتراکِ دور که فقط با یک متغیر محیطی روشن می‌شد.
     *
     * حالا هیچ نامی از آن کلاس‌ها اینجا نیست. انتخابِ پیاده‌سازی به **binding**
     * منتقل شده و `AppServiceProvider` پیش‌فرض را `NullSubscriptionResolver`
     * می‌گذارد. یعنی یک نصبِ تازه هیچ مسیرِ دوری ندارد، ولی فردا با یک binding
     * (یا `SUBSCRIPTION_RESOLVER`) می‌شود عوضش کرد.
     *
     * @see \App\Providers\AppServiceProvider::register()
     */
    private static function impl(): SubscriptionResolverInterface
    {
        return app(SubscriptionResolverInterface::class);
    }

    /**
     * آیا افزونهٔ اشتراک در این نصب حاضر است؟
     *
     * معیار، **بستهٔ فعال در DB** است — نه وجودِ فایل. اگر کد باشد ولی بسته
     * غیرفعال، سرویس باید «خاموش» باشد تا UI بتواند پیام درست بدهد.
     */
/**
 * آیا داده‌ای برای نمایش وجود دارد؟
 *
 * J5 — **این متد قبلاً همیشه `true` برمی‌گرداند** و بی‌صدا اشتباه می‌کرد.
 *
 * نسخهٔ قبل `! self::impl() instanceof NullSubscriptionResolver` بود، یعنی دربارهٔ
 * **پوشش‌دهنده** قضاوت می‌کرد. ولی `impl()` بی‌شرط `PluginSubscriptionResolver`
 * می‌سازد و `NullSubscriptionResolver` فقط **داخلِ delegate** است. پس شرط هرگز
 * درست نبود.
 *
 * نتیجه عملی: `ClientSubscriptionResolver::forSiteId()` — که می‌پرسد «آیا این
 * یک نصبِ *مشترک* است؟» — در هر نصبی جوابِ «بله» می‌گرفت، حتی وقتی هیچ
 * داده‌ای نبود.
 *
 * حالا از خودِ delegate می‌پرسیم: `PluginSubscriptionResolver` می‌گوید آیا کلاسِ
 * داده‌اش هست یا نه، `NullSubscriptionResolver` می‌گوید نه.
 */
public static function isAvailable(): bool
{
    return self::impl()->hasSubscriptionData();
}

    /** @return array<string, mixed> */
    public static function forUser(User $user): array
    {
        return self::impl()->forUser($user);
    }

    public static function invoicesFor(User $user, int $perPage): LengthAwarePaginator
    {
        return self::impl()->invoicesFor($user, $perPage);
    }

    /** @return list<array<string, mixed>> */
    public static function activePlans(): array
    {
        return self::impl()->activePlans();
    }

    /**
     * @return array{ok: bool, body: array<string, mixed>, status?: int}
     */
    public static function changePlan(User $user, int $planId): array
    {
        return self::impl()->changePlan($user, $planId);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function startPayment(User $user, int $invoiceId, PaymentGatewayInterface $gateway): array
    {
        return self::impl()->startPayment($user, $invoiceId, $gateway);
    }

    /** @return array{status: string, message: string} */
    public static function publicStatus(?string $siteId): array
    {
        return self::impl()->publicStatus($siteId);
    }

    /**
     * تضمینِ وجودِ رکوردِ اشتراکِ کاربر.
     *
     * ⭐ چرا در **قرارداد** هست و نه یک متدِ اختیاری: اولین بازدید از پنل باید
     * کار کند. اگر این متد فقط در افزونه بود، core نمی‌توانست تصمیم بگیرد که
     * «نبودِ رکورد» یعنی «هنوز ساخته نشده» یا «سرویس خاموش است».
     *
     * در نبودِ افزونه، کاربری که اصلاً سرویس اشتراک ندارد چیزی برای ساختن
     * ندارد ⇒ همان «غیرفعال» برمی‌گردد و core چیزی نمی‌سازد.
     */
    public static function ensureFor(User $user): bool
    {
        return self::impl()->ensureFor($user);
    }

    /**
     * J5 — @see SubscriptionResolverInterface::overdueInvoiceCount()
     */
    public static function overdueInvoiceCount(User $user): int
    {
        return self::impl()->overdueInvoiceCount($user);
    }

    /**
     * J5 — @see SubscriptionResolverInterface::isDueSoon()
     */
    public static function isDueSoon(User $user): bool
    {
        return self::impl()->isDueSoon($user);
    }
}
