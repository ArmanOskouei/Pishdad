/**
 * K7.16 — قفل‌ها داده‌محور، نه هاردکد.
 *
 * ## وضعیت endpointها (چرا این فایل فقط «خلاصه‌ساز» است)
 *
 * - قفل تعلیق/سررسید: از `/v1/admin/subscription` می‌آید (هست).
 * - سلامت/بازبینی/سیستمی‌بودن افزونه‌ها: از `/v1/admin/plugins` می‌آید (هست).
 * - `dev_mode`: **هیچ endpointای ندارد** — بک‌اند فقط middleware داخلی
 *   `devmode.gate` دارد و وضعيتش را جایی expose نمی‌کند (ساختن endpoint
 *   کار بک‌اند است، نه فرانت). پس این فایل `devMode` را همیشه `"unknown"`
 *   گزارش می‌کند تا UI به‌جای «خاموش است»ِ دروغین، «نامشخص» صادقانه بگوید.
 *
 * این فایل عمداً هیچ fetch ندارد: خواندن شبکه در هوکِ کامپوننت است
 * (`PanelLocksBanner`) و فقط همین دو مسیرِ موجود را می‌خواند — مسیر تازه‌ای
 * اختراع نمی‌شود. تابع خالص است تا با `node --test` قابل آزمون باشد.
 */

export type LockSummary = {
  suspended: boolean;
  pastDue: boolean;
  /** افزونه‌های در انتظار بازبینی (فعال‌سازی‌شان قفل است). */
  pendingReview: number;
  /** افزونه‌های فعال با امضای نامعتبر. */
  unsignedActive: number;
  /** همیشه unknown — تا وقتی بک‌اند endpoint بدهد (نکتهٔ بالای فایل). */
  devMode: "unknown";
  hasLock: boolean;
};

export type SubLike = { status?: unknown } | null | undefined;
export type PluginLike = {
  active?: unknown;
  review_status?: unknown;
  signature_valid?: unknown;
} | null | undefined;

/**
 * خلاصهٔ قفل‌ها از دادهٔ خامِ همان APIهای موجود — ورودی بد ⇒ «قفلی نیست».
 *
 * پارامترها عمداً `unknown`‌اند: پاسخ شبکه هیچ تضمینی ندارد و تستِ «ورودی
 * بد» هم باید بدون cast کامپایل شود.
 */
export function summarizeLocks(sub: unknown, plugins: unknown): LockSummary {
  const status = typeof (sub as SubLike)?.status === "string" ? ((sub as SubLike)?.status as string) : "";
  const suspended = status === "suspended";
  const pastDue = status === "past_due";

  let pendingReview = 0;
  let unsignedActive = 0;
  if (Array.isArray(plugins)) {
    for (const raw of plugins) {
      const p = raw as PluginLike;
      if (!p || typeof p !== "object") continue;
      if (p.review_status === "pending") pendingReview += 1;
      if (p.active === true && p.signature_valid === false) unsignedActive += 1;
    }
  }

  return {
    suspended,
    pastDue,
    pendingReview,
    unsignedActive,
    devMode: "unknown",
    hasLock: suspended || pastDue || pendingReview > 0 || unsignedActive > 0,
  };
}
