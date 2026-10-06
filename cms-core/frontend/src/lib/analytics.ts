/**
 * WF-H12 — کمکی‌های خالصِ صفحهٔ تحلیل (قابل تست، بدون وابستگی به DOM).
 *
 * نمودارها CSS ساده‌اند و کتابخانه‌ای اضافه نشده؛ این توابع فقط «چه کسری از
 * بیشینه» را حساب می‌کنند تا هر میله یک قاعدهٔ واحد داشته باشد.
 */

export type AnalyticsBucket = { key: string; views: number };
export type AnalyticsSeriesPoint = { date: string; views: number };

/** WF-M14 — یک معیارِ Web Vitals در گزارش: صدک ۷۵ و شمار نمونه‌ها. */
export type AnalyticsVital = {
  metric: "lcp" | "inp" | "cls";
  p75: number | null;
  samples: number;
};

export type AnalyticsData = {
  range: { from: string; to: string };
  totals: {
    views: number;
    unique_paths: number;
    unique_referrers: number;
    countries_known: number;
    country_coverage: number;
  };
  top_pages: AnalyticsBucket[];
  top_referrers: AnalyticsBucket[];
  countries: AnalyticsBucket[];
  devices: AnalyticsBucket[];
  series: AnalyticsSeriesPoint[];
  vitals?: AnalyticsVital[];
};

/** درصدِ پهنای میله: `value` نسبت به `max`، همیشه ۰..۱۰۰ و صحیح. */
export function barWidth(value: number, max: number): number {
  if (!Number.isFinite(value) || value <= 0) return 0;
  if (!Number.isFinite(max) || max <= 0) return 0;
  return Math.min(100, Math.round((value / max) * 100));
}

/** بیشینهٔ بازدید یک سری — مقیاسِ میله‌های نمودار. */
export function peakViews(points: readonly AnalyticsSeriesPoint[]): number {
  return points.reduce((max, point) => (point.views > max ? point.views : max), 0);
}

/** جمع کل بازدیدهای یک سری — برای وقتی که UI سری را جدا از totals می‌خواهد. */
export function totalViews(points: readonly AnalyticsSeriesPoint[]): number {
  return points.reduce((sum, point) => sum + (Number.isFinite(point.views) ? point.views : 0), 0);
}

/** بیشینهٔ یک جدولِ تجمعی (پست‌ترین ردیف باید ۱۰۰٪ باشد). */
export function peakBucket(buckets: readonly AnalyticsBucket[]): number {
  return buckets.reduce((max, bucket) => (bucket.views > max ? bucket.views : max), 0);
}
