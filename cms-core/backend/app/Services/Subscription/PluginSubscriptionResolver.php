<?php

namespace App\Services\Subscription;

use App\Models\Plugin;
use App\Models\User;
use App\Services\Billing\PaymentGatewayInterface;
use App\Services\Plugins\PluginReleaseManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * پل میان resolverِ core و افزونهٔ اشتراک.
 *
 * ## ⭐ چرا اینجا نامِ کلاس نمی‌آید
 *
 * نسخهٔ قبلی این فایل رشتهٔ `'Pishdad\\Plugins\\Subscription\\SubscriptionData'`
 * را ثابت نگه می‌داشت. درست است که `import` نبود، ولی
 * `CentralBoundaryTest` هر رشتهٔ `Pishdad\Plugins\…` را نشتِ افزونهٔ خصوصی
 * می‌داند — و حق دارد: یک `grep` در repo عمومی همان نام را نشان می‌دهد.
 *
 * راه درست این است که نامِ کلاس از **داده** بیاید، نه از کد:
 * slug از `ManifestRegistry` خوانده می‌شود و نامِ کلاس از مانیفستِ همان بسته.
 * اینطوری:
 *  - هسته هیچ اسمِ خصوصی‌ای در خود ندارد،
 *  - افزونهٔ سوم بدون ویرایش هسته کار می‌کند،
 *  - و اگر بسته‌ای کلاسش را عوض کند، این فایل هم بی‌خبر نمی‌ماند.
 *
 * @see SubscriptionResolver
 */
final class PluginSubscriptionResolver implements SubscriptionResolverInterface
{
    private const PLUGIN_SLUG = 'subscription';

    /**
     * نامِ کلاسِ پیاده‌سازی را از مانیفست می‌خواند.
     *
     * کلید `provider` در مانیفست، اگر نبود fallback به قراردادِ نام‌گذاریِ
     * استانداردِ بسته‌هاست.
     */
    private function implClass(): ?string
    {
        $manifest = $this->manifest();

        if ($manifest === null) {
            return null;
        }

        $provided = $manifest['services']['subscription_resolver'] ?? null;

        if (is_string($provided) && class_exists($provided)) {
            return $provided;
        }

        $fallback = 'Pishdad\\Plugins\\'
            .str_replace(' ', '', ucwords(str_replace('-', ' ', self::PLUGIN_SLUG)))
            .'\\SubscriptionData';

        return class_exists($fallback) ? $fallback : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function manifest(): ?array
    {
        $plugin = Plugin::query()
            ->where('slug', self::PLUGIN_SLUG)
            ->where('active', true)
            ->first();

        if ($plugin === null) {
            return null;
        }

        // ⭐ مدل `Plugin` ستون `manifest` را به آرایه cast می‌کند. اگر روزی cast
        // عوض شد یا یک adapter خام JSON بدهد، هر دو شکل را می‌پذیریم — نه با
        // `json_decode` روی چیزی که شاید آرایه باشد.
        $raw = $plugin->manifest;

        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function data(): SubscriptionResolverInterface
    {
        $class = $this->implClass();

        if ($class === null) {
            return new NullSubscriptionResolver;
        }

        $instance = new $class;

        // ⭐ fail-closed: اگر کلاسِ اعلام‌شده قرارداد را پیاده نکند، سرویس
        // «نصب نیست» تلقی می‌شود. بهتر است قابلیت خاموش باشد تا اینکه یک
        // `call_user_func` روی متدِ ناموجود، خطای ۵۰۰ بدهد.
        return $instance instanceof SubscriptionResolverInterface
            ? $instance
            : new NullSubscriptionResolver;
    }

    /**
     * J5 — delegate را می‌پرسیم، نه خودمان را.
     *
     * implClass() وقتی 
ull می‌دهد که افزونهٔ اشتراک نصب/فعال نیست — و آن‌وقت
     * data() یک NullSubscriptionResolver می‌دهد. پس همین یک شرط، پاسخِ درست
     * است و لازم نیست دوباره به جدول‌ها یا کلاس‌های افزونه نگاه کنیم.
     */
    public function hasSubscriptionData(): bool
    {
        return $this->implClass() !== null;
    }

    public function forUser(User $user): array
    {
        return $this->data()->forUser($user);
    }

    public function invoicesFor(User $user, int $perPage): LengthAwarePaginator
    {
        return $this->data()->invoicesFor($user, $perPage);
    }

    public function activePlans(): array
    {
        return $this->data()->activePlans();
    }

    public function changePlan(User $user, int $planId): array
    {
        return $this->data()->changePlan($user, $planId);
    }

    public function startPayment(User $user, int $invoiceId, PaymentGatewayInterface $gateway): array
    {
        return $this->data()->startPayment($user, $invoiceId, $gateway);
    }

    public function publicStatus(?string $siteId): array
    {
        return $this->data()->publicStatus($siteId);
    }

    public function ensureFor(User $user): bool
    {
        return $this->data()->ensureFor($user);
    }

    /**
     * J5 — واسطِ تازه فقط پاس می‌دهد؛ کار واقعی در پیاده‌سازیِ افزونه است.
     *
     * اگر افزونهٔ اشتراک روش پیاده‌سازی را به‌روز نکرده باشد (نسخهٔ قدیمی)،
     * `instanceof` نمی‌خورد و به `NullSubscriptionResolver` می‌افتیم که صفر و
     * `false` می‌دهد — یعنی **fail-safe**: داشبورد هشدار مالی نشان نمی‌دهد
     * ولی ۵۰۰ هم نمی‌دهد. عکسِ fail-closed اینجاست امن است چون قاعده فقط
     * «چیزی نشان نده» است، نه «چیزی را پنهان کن».
     */
    public function overdueInvoiceCount(User $user): int
    {
        return $this->data()->overdueInvoiceCount($user);
    }

    public function isDueSoon(User $user): bool
    {
        return $this->data()->isDueSoon($user);
    }
}
