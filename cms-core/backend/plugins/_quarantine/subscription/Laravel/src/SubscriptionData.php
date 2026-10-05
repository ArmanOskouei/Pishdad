<?php

namespace Pishdad\Plugins\Subscription;

use App\Models\Setting;
use App\Models\User;
use App\Services\Billing\PaymentGatewayInterface;
use App\Services\Settings\CachedSettings;
use App\Services\Subscription\NullSubscriptionResolver;
use App\Services\Subscription\SubscriptionResolverInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Pishdad\Plugins\Subscription\Models\Plan;
use Pishdad\Plugins\Subscription\Models\Subscription;

/**
 * پیاده‌سازیِ واقعیِ resolverِ اشتراک — **کدِ خصوصی**.
 *
 * ⛔ این فایل داخل افزونهٔ `subscription` است و نباید در repo عمومی باشد.
 *
 * ## چرا اینجا و نه در core
 *
 * منطقِ اشتراک (چه کسی چه پلنی دارد، فاکتورش چقدر است) **دامنهٔ این افزونه** است.
 * core فقط `SubscriptionResolverInterface` را می‌شناسد و از وجود یا نبودِ این
 * کلاس خبر دارد.
 *
 * ## ⭐ استقلالِ داده
 *
 * از L-B12 این کلاس **هیچ ارجاعی به `central_*` ندارد** — نه مدل، نه جدول. داده در
 * `subscription_plans` / `subscription_subscriptions` / `subscription_invoices`
 * است که همین افزونه آن‌ها را می‌سازد. تنها پل ارتباطی، ستونِ اختیاریِ
 * `central_client_id` است که فقط «این نصب در دفتر مرکز ثبت شده» را یادداشت
 * می‌کند و بود و نبودش هیچ رفتاری را عوض نمی‌کند.
 */
final class SubscriptionData implements SubscriptionResolverInterface
{
    // ── خواندن ──────────────────────────────────────────────────────────

    public function forUser(User $user): array
    {
        $subscription = $this->subscriptionFor($user);

        if ($subscription === null) {
            return ['available' => false] + (new NullSubscriptionResolver)->forUser($user);
        }

        $now = now();

        return [
            'available' => true,
            'plan' => $subscription->plan?->only([
                'id', 'name', 'slug', 'tier', 'price_monthly', 'price_yearly',
            ]),
            'tier' => $subscription->plan?->tier,
            'status' => $subscription->effectiveStatus(),
            'due_at' => $subscription->due_at,
            'suspended_at' => $subscription->suspended_at,
            'days_remaining' => $subscription->remainingDays($now),
            // ⭐ برچسبِ هشدار فقط وقتی درست است که واقعاً در آینده باشد.
            'due_soon' => $subscription->isDueSoon($now),
        ];
    }

    public function invoicesFor(User $user, int $perPage): LengthAwarePaginator
    {
        $subscription = $this->subscriptionFor($user);

        if ($subscription === null) {
            return (new NullSubscriptionResolver)->invoicesFor($user, $perPage);
        }

        return $subscription->invoices()->latest()->paginate(max(1, $perPage));
    }

    public function activePlans(): array
    {
        return Plan::query()
            ->where('active', true)
            ->orderBy('price_monthly')
            ->get(['id', 'name', 'slug', 'tier', 'price_monthly', 'price_yearly', 'currency', 'features'])
            ->all();
    }

    // ── نوشتن ───────────────────────────────────────────────────────────

    public function changePlan(User $user, int $planId): array
    {
        $subscription = $this->subscriptionFor($user);

        if ($subscription === null) {
            return ['ok' => false, 'body' => ['message' => 'سرویس اشتراک روی این نصب فعال نیست.']];
        }

        $target = Plan::query()->find($planId);

        if ($target === null || ! $target->active) {
            return ['ok' => false, 'body' => ['message' => 'این پلن در حال حاضر فعال نیست.']];
        }

        if ((int) $subscription->plan_id === (int) $target->id) {
            return ['ok' => false, 'body' => ['message' => 'همین پلن در حال حاضر فعال است.']];
        }

        $diff = (int) $target->price_monthly - (int) ($subscription->plan?->price_monthly ?? 0);

        if ($diff <= 0) {
            return ['ok' => false, 'body' => ['message' => 'ارتقا فقط به پلن بالاتر (گران‌تر) مجاز است.']];
        }

        $invoice = $subscription->invoices()->create([
            'amount' => $diff,
            'currency' => $target->currency ?? 'IRT',
            'status' => 'pending',
            'due_at' => now()->addDays(7),
            'meta' => [
                'type' => 'plan_change',
                'from_plan_id' => $subscription->plan_id,
                'to_plan_id' => $target->id,
            ],
        ]);

        return [
            'ok' => true,
            'body' => [
                'message' => "فاکتور مابه‌التفاوت ارتقا به «{$target->name}» ساخته شد.",
                'data' => $invoice,
            ],
        ];
    }

    public function startPayment(User $user, int $invoiceId, PaymentGatewayInterface $gateway): array
    {
        $subscription = $this->subscriptionFor($user);

        if ($subscription === null) {
            return ['status' => 404, 'body' => ['message' => 'سرویس اشتراک روی این نصب فعال نیست.']];
        }

        // `whereBelongsTo` — یعنی «فقط فاکتورِ خودِ این اشتراک». بدون این،
        // یک کاربر می‌توانست با حدس زدن id، فاکتورِ کس دیگری را صدا بزند (IDOR).
        $invoice = $subscription->invoices()->whereKey($invoiceId)->first();

        if ($invoice === null) {
            return ['status' => 404, 'body' => ['message' => 'فاکتوری یافت نشد.']];
        }

        if ($invoice->status === 'paid') {
            return ['status' => 422, 'body' => ['message' => 'این فاکتور قبلاً پرداخت شده است.']];
        }

        $session = $gateway->requestPayment(
            (int) $invoice->amount,
            "پرداخت فاکتور #{$invoice->id}",
            (string) config('billing.gateway.callback_url'),
            ['invoice_id' => $invoice->id, 'subscription_id' => $subscription->id],
        );

        // J10 — حالتِ «صورتحساب دستی».
        //
        // درایورِ پیش‌فرض (`ManualBillingDriver`) هیچ درگاهی نمی‌سازد. پیش از این
        // شاخه، `authority` ساختگی و لینک `sandbox.zarinpal.com` ذخیره و به کاربر
        // داده می‌شد — یعنی ادعای جریانی که وجود نداشت، با لینکی که هرگز پرداخت
        // نمی‌کرد. `gateway_ref` هم روی فاکتور می‌نشست و بعد در ستون «مرجع درگاه»
        // به اپراتور نشان داده می‌شد؛ یعنی داده‌ای که هیچ درگاهی تولید نکرده بود.
        //
        // پس اینجا **هیچ چیز نوشته نمی‌شود** و کاربر همان حقیقت را می‌بیند:
        // پرداخت بیرون از سامانه است و وضعیت را پنل مرکزی دستی می‌کند.
        //
        // نکتهٔ ترتیب: دو گارد بالا (فاکتورِ غریبه ⇒ ۴۰۴، فاکتورِ پرداخت‌شده ⇒
        // ۴۲۲) **قبل** از این شاخه می‌آیند و دست‌نخورده‌اند. حذفِ دروغ نباید
        // مرزِ امنیتیِ IDOR را هم حذف کند.
        if (($session['manual'] ?? false) === true) {
            return [
                'status' => 200,
                'body' => [
                    'message' => (string) ($session['message'] ?? 'صورتحساب دستی است'),
                    'data' => [
                        'manual_billing' => true,
                        'status' => (string) $invoice->status,
                        'amount' => (int) $invoice->amount,
                        'currency' => (string) $invoice->currency,
                    ],
                ],
            ];
        }

        $invoice->forceFill(['gateway_ref' => $session['authority']])->save();

        return [
            'status' => 200,
            'body' => [
                'message' => 'در حال انتقال به درگاه پرداخت...',
                'data' => ['authority' => $session['authority'], 'pay_url' => $session['pay_url']],
            ],
        ];
    }

    /**
     * J5 — این کلاس خودش داده است، پس جواب همیشه 	rue.
     */
    public function hasSubscriptionData(): bool
    {
        return true;
    }

    public function publicStatus(?string $siteId): array
    {
        $brand = $this->brand();

        // ⭐ تفکیک سه‌گانه — اگر این‌ها یکی شوند دو باگ برمی‌گردد.
        //  ۱) siteId نداریم  ⇒ «نمی‌دانیم کدام سایت» ⇒ نه ۴۰۴، نه تعلیق. `active`.
        //  ۲) siteId داریم و پیدا شد ⇒ وضعیتِ واقعیِ همان سایت.
        //  ۳) siteId داریم ولی پیدا نشد ⇒ سایت شناخته‌شده نیست ⇒ `missing` (۴۰۴).
        if ($siteId === null || $siteId === '') {
            return ['status' => 'active', 'message' => "{$brand} فعال است."];
        }

        $subscription = Subscription::query()
            ->withTrashed()
            ->where('site_id', $siteId)
            ->first();

        if ($subscription === null) {
            return ['status' => 'missing', 'message' => 'اشتراکی برای این سایت یافت نشد.'];
        }

        $status = $subscription->isActive() ? 'active' : 'suspended';

        return [
            'status' => $status,
            'message' => $status === 'suspended'
                ? "دسترسی به {$brand} متوقف شده است."
                : "{$brand} فعال است.",
        ];
    }

    // ── کمکی ───────────────────────────────────────────────────────────

    /**
     * ⭐ کلیدِ اتصال، `owner_user_id` است — نه `site_id`.
     *
     * چرا؟ کاربرِ محلی «مشتری» است و هر کاربر یک اشتراک دارد. `site_id` فقط
     * وقتی پر است که مرکز این نصب را در دفترش ثبت کرده باشد، پس نمی‌شود
     * مبنای نگاشت باشد.
     */
    private function subscriptionFor(User $user): ?Subscription
    {
        return Subscription::query()
            ->with(['plan'])
            ->where('owner_user_id', $user->id)
            ->first();
    }

    /**
     * ساختِ خودکارِ رکوردِ خالی.
     *
     * بدون این، اولین بازدیدِ کاربر از پنل ۴۰۴ می‌گیرد و اصلاً نمی‌فهمد
     * سرویس چه می‌کند. رکوردِ `active` با پلنِ پایه، یعنی «سرویس روی این نصب
     * فعال است ولی هنوز پلنی انتخاب نشده».
     *
     * ⚠️ در برابر خطای تکراری (race) محافظت شده: اگر دو درخواست هم‌زمان برسند،
     * `firstOrCreate` روی قیدِ یکتای `owner_user_id` دومی را رد می‌کند و ما
     * مقدارِ موجود را برمی‌گردانیم، نه اینکه خطای دیتابیس لو بدهیم.
     */
    public function ensureFor(User $user): bool
    {
        $existing = $this->subscriptionFor($user);

        if ($existing !== null) {
            return true;
        }

        $defaultPlan = Plan::query()
            ->where('active', true)
            ->orderBy('price_monthly')
            ->first();

        try {
            Subscription::query()->create([
                'owner_user_id' => $user->id,
                'site_id' => 'site-'.$user->id,
                'plan_id' => $defaultPlan?->id,
                'status' => 'active',
                'due_at' => $defaultPlan !== null ? now()->addMonth() : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // درخواستِ موازی زودتر رسیده — کار تمام است.
        }

        return $this->subscriptionFor($user) !== null;
    }

    /**
     * J5 — شمارش فاکتورهای معوق، از سمت افزونه.
     *
     * ## چرا این متد وجود دارد و چرا این‌جا
     *
     * پیش‌تر `DashboardController` (هسته) خودش این کوئری را می‌زد:
     * `Invoice::where('client_id', …)->where('status', PENDING)->where('due_at','<',now())`.
     * یعنی هسته می‌دانست «معوق» یعنی چه — و اگر این افزونه فردا وضعیتِ
     * دیگری اضافه کند، هسته بی‌صدا اشتباه می‌کرد.
     *
     * حالا تعریف در همین‌جاست و هسته فقط عدد را می‌خواند.
     *
     * ⚠️ نکته: `status='pending'` اینجا **معوق** است، برخلاف نسخهٔ مرکزی که
     * `due_at < now()` را هم شرط می‌کرد. هر دو شرط با هم معنی «سررسید گذشته و
     * پرداخت‌نشده» می‌دهند، ولی اینجا تعریف صریح‌تر است و به ستون‌های این
     * جدول تکیه می‌کند نه جدولِ افزونهٔ دیگر.
     */
    public function overdueInvoiceCount(User $user): int
    {
        $subscription = $this->subscriptionFor($user);

        if ($subscription === null) {
            return 0;
        }

        return $subscription->invoices()
            ->where('status', 'pending')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();
    }

    /**
     * J5 — @see SubscriptionResolverInterface::isDueSoon()
     *
     * پنجرهٔ یادآوری از `config('billing.reminder_window_days')` می‌آید، پس
     * نصب‌کننده می‌تواند عوضش کند بدون آنکه کدِ این‌جا تغییر کند.
     */
    public function isDueSoon(User $user): bool
    {
        $subscription = $this->subscriptionFor($user);

        if ($subscription === null) {
            return false;
        }

        return $subscription->isDueSoon(now());
    }

    private function brand(): string
    {
        $site = CachedSettings::remember('site', 'global', 300, fn () => Setting::get('site', 'global', []));

        return is_array($site) && ! empty($site['title']) ? (string) $site['title'] : 'وب‌سایت شما';
    }
}
