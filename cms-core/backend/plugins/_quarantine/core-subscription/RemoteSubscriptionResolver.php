<?php

declare(strict_types=1);

namespace App\Services\Subscription;

use App\Models\User;
use App\Services\Billing\PaymentGatewayInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Log;

/**
 * J2 — resolverِ **دور**، وقتی مرکزی در یک استقرارِ دیگر است (پیش‌نیاز J4).
 *
 * ## چرا این وجود دارد
 *
 * تا J4، پنلِ مرکزی در همان دیتابیسِ مشتری بود و `LocalSubscriptionResolver`
 * کافی بود. ولی مدلِ کسب‌وکار (اشتراکی/مرکزی) یعنی مرکزی باید بتواند روی یک
 * سرورِ جدا باشد — و آن‌وقت هستهٔ مشتری دیگر **جدول** ندارد، فقط **پاسخ** دارد.
 *
 * بدون این کلاس، آن روز یا باید افزونهٔ مرکزی را در همهٔ نصب‌ها نگه می‌داشتیم
 * (نقضِ J5)، یا هسته کور می‌شد.
 *
 * ## قاعدهٔ بنیادی: **fail-closed، همیشه**
 *
 * هر خطایی (شبکه، ۴۰۱، ۵۰۰، JSON خراب، timeout) یعنی «نمی‌دانیم». و «نمی‌دانیم»
 * در این مسیر یعنی **همان پاسخِ نبودِ افزونه**: نه تعلیق، نه خطا.
 *
 * ⚠️ چرا این سخت‌گیری؟ چون یک خطای شبکه نباید سایتِ مشتری را از پنل بیرون بیندازد
 * (کاربر همه‌چیزش را از دست می‌کند)، و نباید سایت را **ببندد** (کسی نمی‌تواند لاگ
 * کند). هر دو بدتر از نمایشِ حالتِ عمومی‌اند. پس این کلاس هرگز throw نمی‌کند.
 *
 * ## چرا adapter دارد
 *
 * چون تستِ HTTP بدونِ آن یعنی دروغ گفتن. یک `Transport` کوچک پشتِ کلاس است تا
 * تست بتواند پاسخِ خواسته را بدهد — و نگهبانیِ fail-closed واقعاً سنجیده شود،
 * نه اینکه فقط ادعا شود.
 *
 * @see LocalSubscriptionResolver  همتای محلی، وقتی داده در همان دیتابیس است
 * @see SubscriptionResolverInterface
 */
final class RemoteSubscriptionResolver implements SubscriptionResolverInterface
{
    /**
     * @param  Transport  $transport  لایهٔ HTTP. جدا شده تا تست بتواند واقعی باشد.
     * @param  string|null  $baseUrl  آدرسِ پایه؛ `null` یعنی پیکربندی نشده.
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly ?string $baseUrl = null,
    ) {}

    /**
     * آیا پیکربندیِ لازم کامل است؟
     *
     * بدونِ این پرسش، یک نصبِ محلیِ که `SUBSCRIPTION_REMOTE_URL` ندارد هر بار
     * به یک دامنهٔ خالی درخواست می‌فرستاد و لاگ پر می‌کرد.
     */
    public function isConfigured(): bool
    {
        return is_string($this->baseUrl) && trim($this->baseUrl) !== '';
    }

    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        return $this->call('forUser', $this->absentPayload(), ['user_id' => $user->id]);
    }

    public function invoicesFor(User $user, int $perPage): LengthAwarePaginator
    {
        // یک paginatorِ خالی با همان شکل، تا UI به‌جای «کرش» یک جدولِ بدون ردیف
        // نشان دهد. `path`/`pageName` عیناً مثل `NullSubscriptionResolver`.
        return new Paginator([], 0, max(1, $perPage), 1, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function activePlans(): array
    {
        $answer = $this->call('activePlans', [], []);

        return is_array($answer['plans'] ?? null) ? array_values($answer['plans']) : [];
    }

    /** @return array{ok: bool, body: array<string, mixed>} */
    public function changePlan(User $user, int $planId): array
    {
        return $this->action('changePlan', ['user_id' => $user->id, 'plan_id' => $planId], 'تغییر پلن');
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function startPayment(User $user, int $invoiceId, PaymentGatewayInterface $gateway): array
    {
        // `startPayment` **نمی‌فرستیم**. دو دلیل:
        //
        //  ۱. پرداخت فقط در پنلِ مرکزی است (J10) — پس جریان مالی نباید از هستهٔ
        //     مشتری عبور کند.
        //  ۲. `PaymentGatewayInterface` یک شیءِ محلی است و قابلِ تبدیل به payload
        //     نیست. فرستادنش یعنی وانمود کردن به قراردادی که وجود ندارد.
        //
        // پس پاسخ صریحِ «این مسیر از اینجا نیست» می‌دهیم، نه یک HTTP مبهم.
        return [
            'status' => 409,
            'body' => ['message' => 'پرداخت فقط از پنل مرکزی انجام می‌شود.'],
        ];
    }

    /** @return array{status: string, message: string} */
    public function publicStatus(?string $siteId): array
    {
        // ⚠️ fallback هم باید **همان** شکلی را داشته باشد که `tidy` تضمین می‌کند.
        //
        // نسخهٔ اول اینجا `['status' => 'active', 'message' => '']` می‌داد و تست
        // درست گرفت که پیام خالی از راه می‌رسد: `tidy` فقط روی **پاسخِ شبکه** اجرا
        // می‌شود، نه روی fallback. یعنی مرکزیِ در دسترس نبود ⇒ پیامِ خالی، دقیقاً
        // همان بدترین حالتی که این متد قرار بود جلویش را بگیرد.
        return $this->call(
            'publicStatus',
            ['status' => 'active', 'message' => $this->defaultMessage()],
            ['site_id' => $siteId],
            static function (array $answer): array {
                $message = $answer['message'] ?? null;

                if (is_string($message) && $message !== '') {
                    return $answer;
                }

                // `array_replace` نه `+`: عملگرِ `+` کلیدِ موجودِ چپ را نگه می‌دارد،
                // پس متنِ خالی می‌ماند. این همان باگی بود که تست گرفت.
                return array_replace($answer, [
                    'message' => SubscriptionStatusMessage::make((string) ($answer['status'] ?? 'active')),
                ]);
            },
        );
    }

    /**
     * متنِ پیش‌فرض، همان‌که `tidy` می‌سازد.
     *
     * یک‌جا ساخته می‌شود تا دو مسیرِ «مرکزی نیست» و «مرکزی جوابِ بی‌متن داد» یک
     * جمله بدهند، نه دو جملهٔ متفاوت برای یک کاربر.
     */
    private function defaultMessage(): string
    {
        return SubscriptionStatusMessage::make('active');
    }

    public function ensureFor(User $user): bool
    {
        return (bool) ($this->call('ensureFor', ['available' => false], ['user_id' => $user->id])['available'] ?? false);
    }

    public function overdueInvoiceCount(User $user): int
    {
        $value = $this->call('overdueInvoiceCount', ['count' => 0], ['user_id' => $user->id])['count'] ?? 0;

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    public function isDueSoon(User $user): bool
    {
        return (bool) ($this->call('isDueSoon', ['due_soon' => false], ['user_id' => $user->id])['due_soon'] ?? false);
    }

    public function hasSubscriptionData(): bool
    {
        // ⚠️ فرقِ کلیدی با بقیه: این یکی **می‌پرسد**، پس جوابِ منفیِ دور از پایین
        // معنا دارد (مرکزی جواب داد «ندارم»). ولی وقتی اصلاً پیکربندی نشده، یعنی
        // «نمی‌دانیم» — و همان جوابِ منفی است، چون مصرف‌کننده در نبودِ داده
        // بی‌طرفانه رفتار می‌کند.
        return $this->isConfigured()
            && (bool) ($this->call('hasSubscriptionData', ['available' => false], [])['available'] ?? false);
    }

    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $fallback
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $operation, array $fallback, array $payload, ?callable $tidy = null): array
    {
        if (! $this->isConfigured()) {
            return $fallback;
        }

        try {
            $answer = $this->transport->post(
                rtrim((string) $this->baseUrl, '/').'/'.$operation,
                $payload
            );
        } catch (\Throwable $e) {
            // ⛔ این‌جا **هیچ** exceptionی بیرون نمی‌رود. یک نصبِ مرکزی که در دسترس
            // نیست نباید سایتِ مشتری را خراب کند.
            Log::warning('subscription.remote_unreachable', [
                'operation' => $operation,
                'reason' => $e->getMessage(),
            ]);

            return $fallback;
        }

        if (! is_array($answer)) {
            return $fallback;
        }

        return is_callable($tidy) ? $tidy($answer) : $answer;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, body: array<string, mixed>}
     */
    private function action(string $operation, array $payload, string $label): array
    {
        $answer = $this->call($operation, ['ok' => false, 'message' => 'سرویس مرکزی در دسترس نیست.'], $payload);

        return [
            'ok' => (bool) ($answer['ok'] ?? false),
            'body' => $answer,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function absentPayload(): array
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
}