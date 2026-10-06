<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SearchQuery;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WF-M11 — گزارش جستجوی سایت برای پنل.
 *
 * فقط **خواندن** و پشت `perm:analytics.view` (همان پرمیشن تحلیل سایت؛
 * جدول جداگانه اما دامنهٔ گزارش یکی است). هیچ عددِ وزنی ساخته نمی‌شود:
 * همه‌چیز شمارش خامِ بازهٔ انتخابی است.
 *
 * دو خروجی برای تولید محتوا:
 *  - `top_queries`: پرجستجوترین عبارت‌ها (تقاضای کاربران).
 *  - `zero_result_queries`: عبارت‌هایی که جستجو شدند ولی هیچ نتیجه‌ای
 *    نداشتند — صریح‌ترین سرنخِ محتوای غایب.
 *
 * `results_count` شمارِ نتیجهٔ همان پاسخ است؛ پس تشخیص «بی‌نتیجه» با صفر
 * قطعی است.
 */
class SearchReportController extends Controller
{
    /** بیشینهٔ بازه — یک درخواست نباید ماه‌ها ردیف را اسکن کند. */
    private const MAX_RANGE_DAYS = 366;

    /** سقف ردیف‌های هر جدولِ تجمعی. */
    private const TOP_LIMIT = 10;

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

        $base = static fn (): Builder => SearchQuery::query()->whereBetween('created_at', [$start, $end]);

        $totalSearches = (int) $base()->count();
        $zeroResult = (int) $base()->where('results_count', 0)->count();

        return response()->json([
            'data' => [
                'range' => [
                    'from' => $start->toDateString(),
                    'to' => $end->toDateString(),
                ],
                'totals' => [
                    'searches' => $totalSearches,
                    'unique_queries' => (int) $base()->distinct()->count('query'),
                    'zero_result_searches' => $zeroResult,
                    'zero_result_rate' => $totalSearches > 0 ? round($zeroResult / $totalSearches * 100, 1) : 0.0,
                ],
                'top_queries' => $this->aggregate($base(), false),
                'zero_result_queries' => $this->aggregate($base(), true),
            ],
        ]);
    }

    /**
     * تجمعِ عبارات: نزولی بر پایهٔ شمار جستجو، با سقف. اگر `$zeroOnly` باشد
     * فقط عبارت‌های بی‌نتیجه می‌آیند.
     *
     * @return array<int, array{query: string, searches: int}>
     */
    private function aggregate(Builder $query, bool $zeroOnly): array
    {
        if ($zeroOnly) {
            $query->where('results_count', 0);
        }

        return $query
            ->select('query')
            ->selectRaw('COUNT(*) as searches')
            ->groupBy('query')
            ->orderByDesc('searches')
            ->orderBy('query')
            ->limit(self::TOP_LIMIT)
            ->get()
            ->map(static fn ($row): array => [
                'query' => (string) $row->query,
                'searches' => (int) $row->searches,
            ])
            ->all();
    }
}
