<?php

namespace App\Services\Subscription;

use App\Models\User;
use App\Services\Billing\PaymentGatewayInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * قراردادِ resolverِ اشتراک.
 *
 * هر دو پیاده‌سازی باید **همین شکل** را برگردانند:
 *
 *  - `forUser()` — نمای «اشتراک من» برای یک کاربر. اگر سرویسی نصب نباشد،
 *    یک آرایهٔ `available => false` برمی‌گردد، نه `null` و نه throw. UI باید
 *    بتواند بخش را پنهان کند بدون اینکه ۵۰۴ ببیند.
 *  - `publicStatus()` — همیشه یک `status|message` معتبر برمی‌گرداند.
 *
 * @see SubscriptionResolver
 */
interface SubscriptionResolverInterface
{
    /** @return array<string, mixed> */
    public function forUser(User $user): array;

    /**
     * J5 — آیا پشتِ این resolver **واقعاً داده‌ای** وجود دارد؟
     *
     * ## چرا این متد لازم شد
     *
     * `SubscriptionResolver::isAvailable()` قبلاً این را می‌پرسید:
     * `! self::impl() instanceof NullSubscriptionResolver` — یعنی دربارهٔ **پوشش‌دهنده**
     * قضاوت می‌کرد، نه دربارهٔ delegate. و چون `impl()` بی‌شرط
     * `PluginSubscriptionResolver` برمی‌گرداند، جواب **همیشه `true`** بود.
     *
     * یعنی `ClientSubscriptionResolver::forSiteId()` که می‌پرسد «آیا این یک نصبِ
     * *مشترک* است؟» در هر نصبی — حتی وقتی هیچ داده‌ای وجود ندارد — جوابِ «بله»
     * می‌گرفت. تست `PublicInstallWithoutCentralPluginTest` این را گرفت.
     *
     * ## فرقش با `publicStatus()`
     *
     * این دو **دو سؤالِ متفاوت**‌اند و یکی را نمی‌شود جای دیگری نشاند:
     *
     *  - `hasSubscriptionData()` — «اصلاً داده‌ای هست؟» یک پرسشِ **تشخیصی**.
     *  - `publicStatus()` — «وضعیت چیست؟» یک پرسشِ **نمایشی**، و عمداً وقتی
     *    نامعلوم است `active` جواب می‌دهد چون سایتِ عمومی نباید بی‌دلیل بسته شود.
     *
     * اگر این دو یکی شوند، یا سایتِ عمومی اشتباهی بسته می‌شود، یا تشخیصِ
     * «نصبِ مشترک» همیشه مثبت می‌شود. هر دو بد است.
     *
     * @return bool
     */
    public function hasSubscriptionData(): bool;

    public function invoicesFor(User $user, int $perPage): LengthAwarePaginator;

    /** @return list<array<string, mixed>> */
    public function activePlans(): array;

    /**
     * @return array{ok: bool, body: array<string, mixed>}
     */
    public function changePlan(User $user, int $planId): array;

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function startPayment(User $user, int $invoiceId, PaymentGatewayInterface $gateway): array;

    /**
     * وضعیت عمومی برای یک سایت.
     *
     * ⭐ سه حالتِ متمایز، و قاطی‌کردنشان یک باگ واقعی بود:
     *
     *  - `active`      — سرویس هست و مشتری محدود نیست (یا اصلاً سرویسی نیست).
     *  - `suspended`   — مشتری هست و تعلیق شده.
     *  - `missing`     — سرویس هست ولی **این** `site_id` وجود ندارد ⇒ ۴۰۴.
     *
     * `missing` عمداً جدا از `active` است: مسیر عمومیِ سایت باید برای یک
     * `site_id` ناشناخست ۴۰۴ بدهد، ولی برای **نصبِ تازه** (که هنوز مشترکی
     * ندارد) نباید. اگر این دو یکی شوند، سایتِ خودِ کاربر هم باز نمی‌شود.
     *
     * @return array{status: string, message: string}
     */
    public function publicStatus(?string $siteId): array;

    /**
     * اگر رکوردِ اشتراکِ کاربر نبود، بسازش.
     *
     * ⭐ چرا در **قرارداد** هست و نه یک متدِ اختیاریِ افزونه: اولین بازدید از
     * پنل باید کار کند. اگر فقط در افزونه بود، core نمی‌توانست فرقِ «سرویس
     * خاموش است» و «هنوز رکورد ساخته نشده» را بفهمد.
     *
     * @return bool آیا سرویسِ اشتراک روی این نصب فعال است؟
     */
    public function ensureFor(User $user): bool;

    /**
     * شمارش فاکتورهای **سررسیدشدهٔ پرداخت‌نشده** — برای هشدار داشبورد.
     *
     * ## چرا کلید و نه شیء
     *
     * J5 اضافه شد. پیش‌تر `DashboardController` خودش روی جدول فاکتورهای
     * افزونه کوئری می‌زد تا این عدد را دربیاورد — یعنی داشبوردِ هسته می‌دانست
     * «فاکتور معوق» یعنی چه، و اگر افزونه جدولش را عوض می‌کرد هسته هم
     * می‌شکست.
     *
     * حالا افزونه خودش می‌گوید چند فاکتور دارد و هسته فقط عدد را می‌خواند.
     *
     * @return int صفر یعنی «هیچ فاکتور معوقی نیست» **یا** «افزونهٔ اشتراک نصب
     *             نیست» — هر دو برای UI یکی‌اند: هشدار نشان نده.
     */
    public function overdueInvoiceCount(User $user): int;

    /**
     * آیا سررسیدِ اشتراک در پنجرهٔ یادآوری است؟ (پیش‌فرض هسته ۷ روز.)
     *
     * باز هم کلید، نه سیاست: اینکه «چند روز بیشتر یادآوری کنیم» تصمیمِ
     * افزونه است، حتی اگر پیش‌فرضِ هسته هم همین باشد.
     */
    public function isDueSoon(User $user): bool;
}
