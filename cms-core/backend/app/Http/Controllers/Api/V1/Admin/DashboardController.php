<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Central\Invoice;
use App\Models\Media;
use App\Models\Page;
use App\Models\Ticket;
use App\Services\Subscription\ClientSubscriptionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * تسک ۲.۲ — داشبورد مشتری: آمار + هشدارها + رجیستری ویجت پلاگین‌ها.
 * شمارش‌ها مشترک نصب است (همه ردیف‌ها)؛ تیکت باز = شمارش واقعی (تسک ۵.۳).
 */
class DashboardController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        [$client, $subscription] = ClientSubscriptionResolver::forUser($user);

        return response()->json([
            'data' => [
                'pages_count' => Page::query()->count(),
                'media_count' => Media::query()->count(),
                'open_tickets' => Ticket::query()
                    ->where('status', '!=', Ticket::CLOSED)
                    ->count(), // مرحله ۵ (تسک ۵.۳): شمارش واقعی تیکت‌های باز.
                // دسته ۱ (برابری UI/UX): دلتای واقعی «جدید در ۷ روز اخیر» برای هر کارت — بدون mock.
                'recent' => [
                    'pages_new_7d' => Page::query()->where('created_at', '>=', now()->subDays(7))->count(),
                    'media_new_7d' => Media::query()->where('created_at', '>=', now()->subDays(7))->count(),
                    'tickets_new_7d' => Ticket::query()->where('created_at', '>=', now()->subDays(7))->count(),
                ],
                'subscription' => [
                    'status' => $subscription->status,
                    'tier' => $client->tier,
                    'due_at' => $subscription->due_at,
                    'days_remaining' => $subscription->daysRemaining(),
                ],
            ],
        ]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $user = $request->user();
        [$client, $subscription] = ClientSubscriptionResolver::forUser($user);

        $alerts = [];

        $overdue = Invoice::query()
            ->where('client_id', $client->id)
            ->where('status', Invoice::PENDING)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();

        if ($overdue > 0) {
            $alerts[] = [
                'type' => 'overdue_invoice',
                'severity' => 'critical',
                'message' => "{$overdue} فاکتور سررسیده دارید. لطفاً پرداخت کنید.",
                'action' => '/admin/subscription',
            ];
        } elseif ($subscription->due_at && $subscription->due_at->isFuture()
            && $subscription->due_at->diffInDays(now()) <= (int) config('billing.reminder_window_days', 7)) {
            $alerts[] = [
                'type' => 'due_soon',
                'severity' => 'warning',
                'message' => 'اشتراک شما به‌زودی سررسید می‌شود.',
                'action' => '/admin/subscription',
            ];
        }

        if ($subscription->status === \App\Models\Central\Subscription::SUSPENDED) {
            $alerts[] = [
                'type' => 'suspended',
                'severity' => 'critical',
                'message' => 'سایت شما معلق است. برای بازگشایی، فاکتور را پرداخت کنید.',
                'action' => '/admin/subscription',
            ];
        }

        $quota = $this->quotaForTier($client->tier);
        $used = (int) Media::query()->sum('size');
        if ($quota > 0 && $used / $quota >= 0.8) {
            $alerts[] = [
                'type' => 'storage',
                'severity' => $used >= $quota ? 'critical' : 'warning',
                'message' => 'مصرف فضای فایل شما به سقف نزدیک شده است.',
                'action' => '/admin/media',
            ];
        }

        return response()->json(['data' => $alerts]);
    }

    /**
     * رجیستری ویجت‌های داشبورد پلاگین‌ها — فعلاً خالی با ساختار آماده.
     * پلاگین مرحله ۵ با همین شکل ثبت می‌کند: {key, title, component, order}.
     */
    public function widgets(): JsonResponse
    {
        return response()->json([
            'data' => [
                'registry_version' => '1.0',
                'widgets' => [],
                'slots' => ['top', 'main', 'side'],
            ],
        ]);
    }

    /** سقف فضای فایل هر تیر (بایت) — هم‌قرارداد با COST-TIERING تا تعیین نهایی. */
    private function quotaForTier(string $tier): int
    {
        return match ($tier) {
            'enterprise' => 20 * 1024 * 1024 * 1024,
            'standard' => 5 * 1024 * 1024 * 1024,
            default => 1024 * 1024 * 1024, // budget = ۱ گیگ
        };
    }
}
