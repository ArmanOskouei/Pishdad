<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Pishdad\Plugins\Central\Models\BillingEvent;
use Pishdad\Plugins\Central\Models\Subscription;

/**
 * حذف D+7 (PLAN.md فاز ۳): اشتراکی که ≥۷ روز در تعلیق مانده → deleted
 * (حذف منطقی؛ پاک‌سازی فیزیکی دیتابیس/MinIO منابع با Job جداگانه — TODO مرحله ۵).
 */
class BillingPurge extends Command
{
    protected $signature = 'billing:purge';

    protected $description = 'حذف اشتراک‌های ۷ روز معلق (D+7)';

    public function handle(): int
    {
        $purgeDays = (int) config('billing.purge_after_suspended_days', 7);
        $cutoff = now()->subDays($purgeDays);

        $subscriptions = Subscription::query()
            ->where('status', Subscription::SUSPENDED)
            ->whereNotNull('suspended_at')
            ->where('suspended_at', '<=', $cutoff)
            ->get();

        $count = 0;
        foreach ($subscriptions as $subscription) {
            $subscription->forceFill([
                'status' => Subscription::DELETED,
                'deleted_at' => now(),
            ])->save();

            $subscription->client?->forceFill(['status' => 'deleted'])->save();

            BillingEvent::query()->create([
                'subscription_id' => $subscription->id,
                'client_id' => $subscription->client_id,
                'type' => 'purge',
                'message' => 'اطلاعات پس از ۷ روز تعلیق حذف شد.',
            ]);
            $count++;
        }

        $this->info("حذف شد: {$count}");

        return self::SUCCESS;
    }
}
