<?php

namespace App\Services\Market;

use App\Models\MarketLicense;
use App\Models\MarketOrder;
use App\Models\Plugin;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * ECO6 — تحویلِ ZIP پس از خرید.
 *
 * ## چرا این سرویس هست
 *
 * K8.5 سفارش و لایسنس را می‌ساخت، ولی **فایلِ تحویل نمی‌داد**. لایسنس به
 * «نصب روی سرور» معنا می‌داد، نه به «کاربر فایل را برداشت». تصمیمِ ECO6
 * تک‌خرید است: پول که پرداخت شد، بایتی که خریده دقیقاً یک‌بار تحویل می‌شود.
 *
 * ## «یک‌بار» چه معنایی دارد و چه ندارد
 *
 * یعنی **یک لایسنس، یک تحویلِ موفق**. اولین تحویل روی لایسنس مهر می‌خورد
 * (`delivered_at` + checksum)، و هر درخواست بعدی با 409 رد می‌شود.
 *
 * این یک محدودیتِ درآمدی نیست که دور زده شود، یک **قاعدهٔ صادقانه** است:
 * تا وقتی درگاه پرداخت در دامنه نیست (K8.6 معلق)، نمی‌توان بین «خودم» و
 * «کسی که لینک را گیر آورده» فرق گذاشت. پس دقیقاً همان چیزی که فروختیم
 * تحویل می‌دهیم و درِ تحویل را بعدش می‌بندیم — به‌جای ادعایِ «خرید نامحدود»
 * که در این مرحله قابل اجرا نیست.
 *
 * ## چرا `deliver()` خودش نمی‌سازد اگر نبود
 *
 * اگر لایسنس نبود، یعنی سفارش پرداخت نشده — و آن‌وقت `MarketException 402`
 * می‌دهیم، نه اینکه بی‌صدا یک لایسنس بسازیم. تحویل همیشه بعد از پرداخت
 * است، نه جایگزینش.
 */
class DeliveryService
{
    /**
     * تحویلِ فایلِ پلاگین به خریدار.
     *
     * @throws MarketException 402 (پرداخت نشده) | 404 (فایل نیست) | 409 (قبلاً تحویل شده)
     */
    public function deliver(Plugin $plugin, User $user): MarketLicense
    {
        $license = MarketLicense::query()
            ->where('user_id', $user->id)
            ->where('plugin_id', $plugin->id)
            ->first();

        if ($license === null || ! $license->coversCore()) {
            throw new MarketException('این افزونه خریداری نشده یا لایسنسش معتبر نیست.', 402);
        }

        if ($license->delivered_at !== null) {
            throw new MarketException('این فایل قبلاً تحویل داده شده است (تحویل یک‌باره).', 409);
        }

        $path = $plugin->path;
        if (! is_string($path) || $path === '' || ! Storage::disk('local')->exists($path)) {
            throw new MarketException('فایل تحویل این افزونه روی سرور موجود نیست.', 404);
        }

        $license->forceFill([
            'delivered_at' => now(),
            'delivered_checksum' => hash_file('sha256', Storage::disk('local')->path($path)) ?: null,
        ])->save();

        return $license->fresh();
    }

    /**
     * آیا کاربر همین حالا مجاز به تحویل است؟ `false` هم یعنی «قبلاً گرفته».
     *
     * برای رندرِ دکمهٔ UI: بدون این، دکمه بعد از تحویل هم «دانلود» می‌ماند و
     * کلیک بعدی ۴۰۹ می‌دهد — یعنی UI از وضعیتِ واقعی عقب می‌افتاد.
     */
    public function canDeliverNow(Plugin $plugin, User $user): bool
    {
        $license = MarketLicense::query()
            ->where('user_id', $user->id)
            ->where('plugin_id', $plugin->id)
            ->first();

        return $license !== null
            && $license->coversCore()
            && $license->delivered_at === null
            && is_string($plugin->path)
            && $plugin->path !== ''
            && Storage::disk('local')->exists($plugin->path);
    }

    /**
     * وضعیتِ تحویلِ یک افزونه برای یک کاربر — بدون exception، برای UI.
     *
     * @return array{owned: bool, delivered: bool, deliverable: bool, checksum: string|null}
     */
    public function deliveryStatus(Plugin $plugin, User $user): array
    {
        $license = MarketLicense::query()
            ->where('user_id', $user->id)
            ->where('plugin_id', $plugin->id)
            ->first();

        $owned = $license !== null && $license->coversCore();

        return [
            'owned' => $owned,
            'delivered' => $owned && $license->delivered_at !== null,
            'deliverable' => $owned && $license->delivered_at === null,
            'checksum' => $owned ? $license->delivered_checksum : null,
        ];
    }

    /** پولی یا رایگان — برای تصمیم UI بین «خرید» و «تحویل». */
    public function price(Plugin $plugin): int
    {
        $manifest = is_array($plugin->manifest) ? $plugin->manifest : [];

        return max(0, (int) ($manifest['price'] ?? 0));
    }

    public function currency(Plugin $plugin): string
    {
        $manifest = is_array($plugin->manifest) ? $plugin->manifest : [];

        return is_string($manifest['currency'] ?? null) ? $manifest['currency'] : 'IRT';
    }

    /** آیا این سفارشِ کاربر پرداخت شده و آمادهٔ تحویل است؟ */
    public function paidOrderFor(Plugin $plugin, User $user): ?MarketOrder
    {
        return MarketOrder::query()
            ->where('user_id', $user->id)
            ->where('plugin_id', $plugin->id)
            ->where('status', MarketOrder::STATUS_PAID)
            ->latest()
            ->first();
    }
}
