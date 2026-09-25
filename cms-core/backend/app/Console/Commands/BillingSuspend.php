<?php

namespace App\Console\Commands;

use App\Models\Central\BillingEvent;
use App\Models\Central\Invoice;
use App\Models\Central\Subscription;
use Illuminate\Console\Command;

/**
 * تعلیق D-0 (PLAN.md فاز ۲): به‌محض گذشتن لحظه سررسید، اشتراکی که فاکتور
 * پرداخت‌نشده دارد → suspended (سایت مسدود + پنل فقط-پرداخت در سمت نصب).
 */
class BillingSuspend extends Command
{
    protected $signature = 'billing:suspend';

    protected $description = 'تعلیق اشتراک‌های سررسیدگذشته پرداخت‌نشده (D-0)';

    public function handle(): int
    {
        $subscriptions = Subscription::query()
            ->whereIn('status', [Subscription::ACTIVE, Subscription::PAST_DUE])
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now())
            ->whereHas('invoices', fn ($q) => $q->where('status', Invoice::PENDING))
            ->get();

        $count = 0;
        foreach ($subscriptions as $subscription) {
            $subscription->forceFill([
                'status' => Subscription::SUSPENDED,
                'suspended_at' => now(),
            ])->save();

            $subscription->client?->forceFill(['status' => 'suspended'])->save();

            BillingEvent::query()->create([
                'subscription_id' => $subscription->id,
                'client_id' => $subscription->client_id,
                'type' => 'suspend',
                'message' => 'سایت غیرفعال شد؛ ۷ روز تا حذف اطلاعات فرصت باقی است.',
            ]);
            $count++;
        }

        $this->info("تعلیق شد: {$count}");

        return self::SUCCESS;
    }
}
