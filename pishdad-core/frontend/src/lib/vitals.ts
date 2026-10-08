/**
 * WF-M14 — کمکی‌های خالصِ Web Vitals (بدون DOM، قابل تست).
 *
 * آستانه‌ها همان آستانه‌های رسمی Core Web Vitals برای صدک ۷۵ هستند:
 *  - LCP (میلی‌ثانیه): خوب ≤ ۲۵۰۰، نیازمند بهبود ≤ ۴۰۰۰، ضعیف > ۴۰۰۰
 *  - INP (میلی‌ثانیه): خوب ≤ ۲۰۰، نیازمند بهبود ≤ ۵۰۰، ضعیف > ۵۰۰
 *  - CLS (بی‌بعد):     خوب ≤ ۰٫۱، نیازمند بهبود ≤ ۰٫۲۵، ضعیف > ۰٫۲۵
 *
 * این ماژول عمداً هیچ وابستگی خارجی (مثل `web-vitals`) ندارد.
 */

export type VitalMetric = "lcp" | "inp" | "cls";
export type VitalRating = "good" | "needs-improvement" | "poor";

export type VitalThresholds = { good: number; poor: number };

export const VITAL_THRESHOLDS: Record<VitalMetric, VitalThresholds> = {
  lcp: { good: 2500, poor: 4000 },
  inp: { good: 200, poor: 500 },
  cls: { good: 0.1, poor: 0.25 },
};

/** نام فارسی معیارها برای UI. */
export const VITAL_LABELS: Record<VitalMetric, string> = {
  lcp: "بزرگ‌ترین ترسیم محتوا (LCP)",
  inp: "تأخیر تعامل (INP)",
  cls: "جابه‌جایی چیدمان (CLS)",
};

export const VITAL_RATING_LABELS: Record<VitalRating, string> = {
  good: "خوب",
  "needs-improvement": "نیازمند بهبود",
  poor: "ضعیف",
};

/** واحد نمایش هر معیار؛ CLS بی‌بعد است. */
export const VITAL_UNITS: Record<VitalMetric, string> = {
  lcp: "میلی‌ثانیه",
  inp: "میلی‌ثانیه",
  cls: "",
};

/**
 * درجهٔ یک معیار بر پایهٔ صدک ۷۵. مقدار نامعلوم/نامعتبر ⇒ `null` (نه «خوب»).
 */
export function classifyVital(metric: VitalMetric, value: number | null | undefined): VitalRating | null {
  if (value === null || value === undefined || !Number.isFinite(value)) return null;

  const { good, poor } = VITAL_THRESHOLDS[metric];
  if (value <= good) return "good";
  if (value <= poor) return "needs-improvement";
  return "poor";
}

/** قالب‌بندی عددیِ معیار: CLS سه رقم اعشار، LCP/INP گرد به میلی‌ثانیهٔ صحیح. */
export function formatVitalValue(metric: VitalMetric, value: number): string {
  if (!Number.isFinite(value)) return "—";
  if (metric === "cls") return String(Math.round(value * 1000) / 1000);
  return String(Math.round(value));
}

/** درصدِ هم‌ارز برای میلهٔ CSS: ارزش نسبت به سقفِ «ضعیف» (۰..۱۰۰). */
export function vitalBarWidth(metric: VitalMetric, value: number): number {
  if (!Number.isFinite(value) || value <= 0) return 0;
  const { poor } = VITAL_THRESHOLDS[metric];
  return Math.min(100, Math.round((value / poor) * 100));
}
