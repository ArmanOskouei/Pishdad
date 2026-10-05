<?php

namespace Pishdad\Plugins\Central\Console\Commands;

use Illuminate\Console\Command;
use Pishdad\Plugins\Central\Models\BillingEvent;
use Pishdad\Plugins\Central\Models\Invoice;
use Pishdad\Plugins\Central\Models\Subscription;

/**
 * یادآوری D-7 تا D-0 (PLAN.md فاز ۱): برای هر اشتراک فعال با فاکتور پرداخت‌نشده
 * که سررسیدش در ۷ روز آینده (یا امروز) است، یک رویداد remind ثبت می‌کند
 * (روزی یک‌بار برای هر اشتراک — ضدتکرار با whereDate).
 * ارسال واقعی ایمیل/پیامک در Dispatch جداگانه (TODO مرحله ۵)؛ این کامند مبنای آن است.
 */
class BillingRemind extends Command
{
    protected $signature = 'billing:remind';

    protected $description = 'یادآوری سررسید اشتراک (D-7 تا D-0)';

    public function handle(): int
    {
        $window = (int) config('billing.reminder_window_days', 7);
        $today = now()->startOfDay();
        $horizon = now()->addDays($window)->endOfDay();

        $subscriptions = Subscription::query()
            ->whereIn('status', [Subscription::ACTIVE, Subscription::PAST_DUE])
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [$today, $horizon])
            ->whereHas('invoices', fn ($q) => $q->where('status', Invoice::PENDING))
            ->get();

        $count = 0;
        foreach ($subscriptions as $subscription) {
            $already = BillingEvent::query()
                ->where('subscription_id', $subscription->id)
                ->where('type', 'remind')
                ->whereDate('created_at', $today)
                ->exists();

            if ($already) {
                continue;
            }

            $days = $today->diffInDays($subscription->due_at->startOfDay());
            BillingEvent::query()->create([
                'subscription_id' => $subscription->id,
                'client_id' => $subscription->client_id,
                'type' => 'remind',
                'message' => $days === 0
                    ? 'امروز روز سررسید است؛ لطفاً پرداخت کنید.'
                    : "فاکتور شما {$days} روز دیگر سررسید می‌شود.",
            ]);
            $count++;
        }

        $this->info("یادآوری ثبت شد: {$count}");

        return self::SUCCESS;
    }
}
