<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PageVital;
use App\Models\PageView;
use App\Services\Analytics\SiteAnalytics;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WF-H12 — گزارش تحلیلِ سایت عمومی برای پنل.
 *
 * فقط **خواندن** و پشت `perm:analytics.view`. هیچ محاسبهٔ وزنی/تبدیلِ نرخ
 * انجام نمی‌دهد؛ همه‌چیز شمارش خامِ بازهٔ انتخابی است تا عدد با جدول یکی باشد.
 *
 * کشور فقط وقتی گزارش می‌شود که لبه واقعاً آن را داده باشد؛ برای همین
 * `country_coverage` هم برگردانده می‌شود تا UI بتواند بگوید «چند درصد
 * بازدیدها کشورشان معلوم است» — نه اینکه صفر را به‌جای «نامعلوم» جا بزند.
 */
class AnalyticsController extends Controller
{
    /** بیشینهٔ بازه — یک درخواست نباید ماه‌ها ردیف را اسکن کند. */
    private const MAX_RANGE_DAYS = 366;

    /** سقف ردیف‌های هر جدولِ تجمعی. */
    private const TOP_LIMIT = 10;

    /** معیارهای Web Vitals — ترتیب نمایش در UI. */
    private const VITAL_METRICS = ['lcp', 'inp', 'cls'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
        ]);

        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->startOfDay() : Carbon::today();
        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : $to->copy()->subDays(29);

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy(), $from->copy()];
        }
        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $from = $to->copy()->subDays(self::MAX_RANGE_DAYS);
        }

        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();

        $base = static fn () => PageView::query()->whereBetween('created_at', [$start, $end]);

        $totalViews = $base()->count();
        $countryKnown = (int) $base()->whereNotNull('country')->count();

        return response()->json([
            'data' => [
                'range' => [
                    'from' => $start->toDateString(),
                    'to' => $end->toDateString(),
                ],
                'totals' => [
                    'views' => $totalViews,
                    'unique_paths' => (int) $base()->distinct()->count('path'),
                    'unique_referrers' => (int) $base()->whereNotNull('referrer')->distinct()->count('referrer'),
                    'countries_known' => (int) $base()->whereNotNull('country')->distinct()->count('country'),
                    'country_coverage' => $totalViews > 0 ? round($countryKnown / $totalViews * 100, 1) : 0.0,
                ],
                'top_pages' => $this->top($base(), 'path'),
                'top_referrers' => $this->top($base(), 'referrer'),
                'countries' => $this->top($base(), 'country'),
                'devices' => $this->top($base(), 'device'),
                'series' => $this->series($base(), $start, $to),
                'vitals' => $this->vitals($start, $end),
            ],
        ]);
    }

    /**
     * WF-M14 — صدک ۷۵ هر معیارِ Web Vitals در بازه.
     *
     * صدک با روش «نزدیک‌ترین رتبه» حساب می‌شود: برای n نمونه با مرتب‌سازی
     * صعودی، اندیس `ceil(0.75n) - 1`. این روش قطعی و مستقل از دیتابیس است و
     * برخلاف میانیابی، همیشه یکی از مقادیر واقعی را برمی‌گرداند. معیارِ بدون
     * نمونه `p75: null` و `samples: 0` می‌گیرد (صفر به‌جای «نامعلوم» جا نمی‌زند).
     *
     * @return array<int, array{metric: string, p75: float|null, samples: int}>
     */
    private function vitals(Carbon $start, Carbon $end): array
    {
        $out = [];

        foreach (self::VITAL_METRICS as $metric) {
            $values = PageVital::query()
                ->whereBetween('created_at', [$start, $end])
                ->where('metric', $metric)
                ->orderBy('value')
                ->pluck('value')
                ->map(static fn ($value): float => (float) $value)
                ->all();

            $out[] = [
                'metric' => $metric,
                'p75' => $this->percentile75($values),
                'samples' => count($values),
            ];
        }

        return $out;
    }

    /**
     * صدک ۷۵ به روش نزدیک‌ترین رتبه روی فهرستِ **مرتب‌صعودی**. فهرست خالی
     * یعنی `null` — نه صفر.
     *
     * @param  list<float>  $sorted
     */
    private function percentile75(array $sorted): ?float
    {
        $count = count($sorted);
        if ($count === 0) {
            return null;
        }

        $index = max(0, (int) ceil($count * 0.75) - 1);
        $index = min($index, $count - 1);

        return round($sorted[$index], 3);
    }

    /**
     * تجمعِ یک ستون — خودِ سطل در `Services\Analytics\SiteAnalytics` است.
     *
     * WF-M12: این متد قبلاً تنها پیاده‌سازیِ top-N بود و کارتِ داشبورد ناچار
     * بود همان کوئری را کپی کند. حالا هر دو از یک جا می‌خوانند.
     *
     * @return array<int, array{key: string, views: int}>
     */
    private function top(\Illuminate\Database\Eloquent\Builder $query, string $column): array
    {
        return app(SiteAnalytics::class)->top($query, $column, self::TOP_LIMIT);
    }

    /**
     * سریِ زمانیِ روزانه با پرکردنِ روزهای بی‌بازدید (صفر) — نمودار نباید
     * فاصله را بگذرد.
     *
     * @return array<int, array{date: string, views: int}>
     */
    private function series(\Illuminate\Database\Eloquent\Builder $query, Carbon $start, Carbon $to): array
    {
        $rows = $query
            ->selectRaw('DATE(created_at) as day, COUNT(*) as views')
            ->groupByRaw('DATE(created_at)')
            ->get()
            ->mapWithKeys(static fn ($row): array => [(string) $row->day => (int) $row->views]);

        $out = [];
        $cursor = $start->copy()->startOfDay();
        $last = $to->copy()->startOfDay();
        while ($cursor->lessThanOrEqualTo($last)) {
            $key = $cursor->toDateString();
            $out[] = ['date' => $key, 'views' => (int) ($rows[$key] ?? 0)];
            $cursor->addDay();
        }

        return $out;
    }
}
