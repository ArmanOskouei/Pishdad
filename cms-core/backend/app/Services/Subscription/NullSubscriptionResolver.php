<?php

declare(strict_types=1);

namespace App\Services\Subscription;

use App\Models\Setting;
use App\Models\User;
use App\Services\Billing\PaymentGatewayInterface;
use App\Services\Settings\CachedSettings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

/**
 * پیاده‌سازیِ **نبودِ** سرویسِ اشتراک.
 *
 * ## چرا لازم است
 *
 * یک نصبِ عمومیِ گیت‌هاب هیچ افزونهٔ خصوصی‌ای ندارد. بدون این کلاس، هر صفحه‌ای
 * که به اشتراک نگاه می‌کند یا ۵۰۴ می‌داد یا یک بن‌بست می‌ساخت (کاربر باید وارد
 * پنلی شود که برای روشن‌کردنِ خودش نیاز به همان پنل دارد).
 *
 * ## قاعده
 *
 * `active` گذاشته می‌شود، نه `suspended`. یعنی «سرویسی نیست» **به معنای
 * محدودیت نیست**. برعکسش غلط است: کاربرِ عادیِ یک نصب عمومی نباید ببیند
 * «اشتراک شما تعلیق شده».
 */
final class NullSubscriptionResolver implements SubscriptionResolverInterface
{
    /**
     * J5 — پیش‌فرض همیشه alse.
     *
     * SubscriptionResolver::isAvailable() روزی دربارهٔ *پوشش‌دهنده* قضاوت می‌کرد و
     * همیشه 	rue می‌شد. این متد جوابِ درست را از **delegate** می‌گیرد.
     */
    public function hasSubscriptionData(): bool
    {
        return false;
    }

    public function forUser(User $user): array
    {
        return [
            'available' => false,
            'plan' => null,
            'tier' => null,
            'status' => null,
            'due_at' => null,
            'suspended_at' => null,
            'days_remaining' => null,
        ];
    }

    public function invoicesFor(User $user, int $perPage): LengthAwarePaginator
    {
        // یک paginatorِ **خالیِ واقعی**، نه یک آرایهٔ ساختگی. UI همان شکلِ
        // `meta.links` را می‌بیند و کدِ سرو یا کلاینت نمی‌شکند.
        return new Paginator([], 0, max(1, $perPage), 1, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    public function activePlans(): array
    {
        return [];
    }

    public function changePlan(User $user, int $planId): array
    {
        return [
            'ok' => false,
            'body' => ['message' => 'سرویس اشتراک روی این نصب فعال نیست.'],
        ];
    }

    public function startPayment(User $user, int $invoiceId, PaymentGatewayInterface $gateway): array
    {
        return [
            'status' => 404,
            'body' => ['message' => 'سرویس اشتراک روی این نصب فعال نیست.'],
        ];
    }

    public function publicStatus(?string $siteId): array
    {
        // سرویسِ اشتراک اصلاً نصب نیست ⇒ هیچ سایتی «مجهول» نیست. یک نصب عمومی
        // همیشه `active` است.
        return [
            'status' => 'active',
            'message' => $this->brand().' فعال است.',
        ];
    }

    public function ensureFor(User $user): bool
    {
        // ⭐ بدون افزونه، چیزی برای ساختن وجود ندارد. برگرداندن `false` یعنی
        // «سرویس خاموش است» و core می‌داند نباید چیزی در DB بنویسد.
        return false;
    }

    /**
     * J5 — بدون افزونهٔ اشتراک، «فاکتور معوق» وجود ندارد.
     *
     * صفر یعنی «نمی‌دانیم» یا «هیچ»؟ برای UI فرقی نمی‌کند: در هر دو حالت
     * هشدارِ مالی نشان داده نمی‌شود. پس صفر امن است — و صفر دروغ نمی‌گوید چون
     * اصلاً صورت‌حسابی در کار نیست.
     */
    public function overdueInvoiceCount(User $user): int
    {
        return 0;
    }

    /**
     * J5 — بدون افزونهٔ اشتراک سررسیدی وجود ندارد، پس هرگز «به‌زودی» نیست.
     *
     * `false` یعنی شمارهٔ روز صفر نیست و هشدار نشان داده نمی‌شود — که در نبودِ
     * افزونه دقیقاً رفتار درست است.
     */
    public function isDueSoon(User $user): bool
    {
        return false;
    }

    private function brand(): string
    {
        $site = CachedSettings::remember('site', 'global', 300, fn () => Setting::get('site', 'global', []));

        return is_array($site) && ! empty($site['title']) ? (string) $site['title'] : 'وب‌سایت شما';
    }
}
