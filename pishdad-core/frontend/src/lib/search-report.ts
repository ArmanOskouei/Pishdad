/**
 * WF-M11 — کمکی‌های خالصِ صفحهٔ «گزارش جستجو» (قابل تست، بدون DOM).
 *
 * میله‌ها با همان قاعدهٔ `analytics.ts` کشیده می‌شوند؛ این‌جا فقط یک تجمعِ
 * سبک برای عبارت‌ها تعریف می‌شود تا UI یک قاعدهٔ واحد داشته باشد.
 */

export type SearchQueryBucket = { query: string; searches: number };

export type SearchReportTotals = {
  searches: number;
  unique_queries: number;
  zero_result_searches: number;
  zero_result_rate: number;
};

export type SearchReportData = {
  range: { from: string; to: string };
  totals: SearchReportTotals;
  top_queries: SearchQueryBucket[];
  zero_result_queries: SearchQueryBucket[];
};

/** بیشینهٔ شمار جستجوی یک جدول — مقیاسِ میله‌ها (صفر روی فهرست خالی). */
export function peakSearches(buckets: readonly SearchQueryBucket[]): number {
  return buckets.reduce((max, bucket) => (bucket.searches > max ? bucket.searches : max), 0);
}

/** جمعِ شمار جستجوهای یک جدول — برای وقتی UI جمع مستقل می‌خواهد. */
export function totalSearches(buckets: readonly SearchQueryBucket[]): number {
  return buckets.reduce((sum, bucket) => sum + (Number.isFinite(bucket.searches) ? bucket.searches : 0), 0);
}
