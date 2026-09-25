<?php

namespace App\Http\Controllers\Api\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\Client;
use App\Models\Central\ChabokanDeployment;
use App\Models\Central\Invoice;
use App\Models\Central\Payment;
use App\Models\Central\Subscription;
use App\Services\Chabokan\ChabokanClientInterface;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/**
 * تسک ۳.۲ — داشبورد مرکزی (فقط operator): آمار + سری ۱۲ ماهه.
 * MRR = جمع price_monthly پلن‌های اشتراک‌های فعال؛ درآمد نمودار =
 * aggregation پرداخت‌های paid (ماه created_at) روی central_payments.
 */
class CentralDashboardController extends Controller
{
    public function __construct(private readonly ChabokanClientInterface $chabokan) {}
    public function stats(): JsonResponse
    {
        $mrr = (int) Subscription::query()
            ->where('central_subscriptions.status', Subscription::ACTIVE)
            ->leftJoin('central_plans', 'central_plans.id', '=', 'central_subscriptions.plan_id')
            ->sum('central_plans.price_monthly');

        $overdue = Invoice::query()
            ->where('status', Invoice::PENDING)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();

        return response()->json([
            'data' => [
                'active_clients' => Client::query()->where('status', 'active')->count(),
                'suspended_clients' => Client::query()->where('status', 'suspended')->count(),
                'mrr' => $mrr,
                'overdue_invoices' => $overdue,
            ],
        ]);
    }

    public function charts(): JsonResponse
    {
        $months = collect(range(11, 0))->map(fn (int $ago) => Carbon::now()->startOfMonth()->subMonths($ago));

        $revenueByMonth = Payment::query()
            ->selectRaw("to_char(created_at, 'YYYY-MM') as ym, sum(amount) as total")
            ->where('status', 'paid')
            ->where('created_at', '>=', $months->first())
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $customersByMonth = Client::query()
            ->selectRaw("to_char(created_at, 'YYYY-MM') as ym, count(*) as total")
            ->where('created_at', '>=', $months->first())
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $revenue = $months->map(fn (Carbon $m) => [
            'month' => $m->format('Y-m'),
            'total' => (int) ($revenueByMonth[$m->format('Y-m')] ?? 0),
        ])->values()->all();

        $customers = $months->map(fn (Carbon $m) => [
            'month' => $m->format('Y-m'),
            'count' => (int) ($customersByMonth[$m->format('Y-m')] ?? 0),
        ])->values()->all();

        return response()->json([
            'data' => [
                'revenue_12m' => $revenue,
                'customers_12m' => $customers,
            ],
        ]);
    }

    /**
     * پرونده‌های باز قابل اکشن (دسته ۱ برابری UI/UX — پنل «⚡ پرونده‌های باز» دمو):
     * کیف پول کم / تعلیق‌های نزدیک حذف / دیپلوی ناموفق / فاکتور سررسیده.
     * هر مورد: بج نوع + توضیح + لینک عمیق اقدام. همه داده واقعی (Stub بی‌خطر چابکان).
     */
    public function openCases(): JsonResponse
    {
        $cases = [];

        $wallet = $this->chabokan->wallet();
        if (! empty($wallet['low_balance'])) {
            $cases[] = [
                'kind' => 'wallet',
                'severity' => 'critical',
                'title' => 'کیف پول چابکان زیر آستانه است',
                'detail' => 'موجودی: '.number_format((int) ($wallet['balance'] ?? 0)).' تومان',
                'action_label' => 'شارژ',
                'action' => '/central/chabokan',
            ];
        }

        $idle = collect($this->chabokan->services())
            ->where('status', '!=', 'running')
            ->values()->all();
        if (count($idle) > 0) {
            $cases[] = [
                'kind' => 'pool',
                'severity' => 'warning',
                'title' => count($idle).' سرویس غیرفعال در استخر',
                'detail' => collect($idle)->pluck('name')->implode('، '),
                'action_label' => 'استخر',
                'action' => '/central/chabokan',
            ];
        }

        $nearPurge = Subscription::query()
            ->where('status', Subscription::SUSPENDED)
            ->whereNotNull('suspended_at')
            ->with('client:id,name,site_id')
            ->get()
            ->filter(fn (Subscription $s) => $s->daysRemaining() <= 2)
            ->values();
        foreach ($nearPurge as $sub) {
            $cases[] = [
                'kind' => 'suspension',
                'severity' => 'warning',
                'title' => ($sub->client?->name ?? 'مشتری')." — {$sub->daysRemaining()} روز تا حذف",
                'detail' => 'معلق از '.$sub->suspended_at?->toDateTimeString(),
                'action_label' => 'کنترل تعلیق',
                'action' => '/central/billing?tab=suspension',
            ];
        }

        $failed = ChabokanDeployment::query()
            ->where('status', ChabokanDeployment::FAILED)
            ->latest()
            ->limit(3)
            ->get();
        foreach ($failed as $dep) {
            $cases[] = [
                'kind' => 'deploy',
                'severity' => 'critical',
                'title' => "دیپلوی ناموفق: {$dep->service}",
                'detail' => $dep->created_at?->toDateTimeString(),
                'action_label' => 'بررسی',
                'action' => '/central/chabokan',
            ];
        }

        $overdue = Invoice::query()
            ->where('status', Invoice::PENDING)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();
        if ($overdue > 0) {
            $cases[] = [
                'kind' => 'overdue',
                'severity' => 'warning',
                'title' => "{$overdue} فاکتور سررسیدگذشته",
                'detail' => 'نیازمند یادآوری یا پیگیری',
                'action_label' => 'فاکتورها',
                'action' => '/central/billing?tab=invoices&status=pending',
            ];
        }

        return response()->json(['data' => $cases]);
    }
}
