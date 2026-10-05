/**
 * حالتِ مشتق‌شدهٔ بخشِ اشتراک در داشبورد.
 *
 * ## چرا این تابع وجود دارد
 *
 * `DashboardController` آگاهانه `data.subscription = null` برمی‌گرداند و در
 * کدش نوشته «UI باید بخش را پنهان کند». ولی تایپِ فرانت آن را غیرقابل‌null
 * اعلام کرده بود و داشبورد `stats.subscription.status` را بدون guard
 * می‌خواند. برای سوپرادمین — که اصلاً اشتراک ندارد — یعنی
 * «Cannot read properties of null (reading 'status')» و کرشِ کل صفحه.
 *
 * پس `null` یک **حالتِ عادی** است، نه خطا: کارتِ پلن و کارتِ وضعیت پنهان
 * می‌شوند و بقیهٔ داشبورد سالم می‌ماند.
 */

export type SubscriptionStats = {
  status: string;
  tier: string;
  due_at: string | null;
  days_remaining: number;
};

export type SubscriptionView = {
  /** آیا اصلاً بخشی از اشتراک باید رندر شود. */
  present: boolean;
  suspended: boolean;
  /** درصد گذشته از دوره؛ `0` وقتی بخشی نیست. */
  periodPct: number;
};

/** فرضِ چرخهٔ ۳۰ روزه — همان چیزی که داشبورد قبلاً درجا از آن استفاده می‌کرد. */
export const PERIOD_DAYS = 30;

export function deriveSubscriptionView(sub: SubscriptionStats | null | undefined): SubscriptionView {
  if (sub === null || sub === undefined) {
    return { present: false, suspended: false, periodPct: 0 };
  }

  return {
    present: true,
    suspended: sub.status === "suspended",
    periodPct: Math.min(100, Math.max(0, Math.round(((PERIOD_DAYS - sub.days_remaining) / PERIOD_DAYS) * 100))),
  };
}
