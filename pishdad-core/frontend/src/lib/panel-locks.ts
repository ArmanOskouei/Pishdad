/**
 * قفل‌ها داده‌محور، نه هاردکد.
 *
 * ## وضعیت endpointها (چرا این فایل فقط «خلاصه‌ساز» است)
 *
 * - سلامت/بازبینی/سیستمی‌بودن افزونه‌ها: از `/v1/admin/plugins` می‌آید (هست).
 * - `dev_mode`: **هیچ endpointای ندارد** — بک‌اند فقط middleware داخلی
 *   `devmode.gate` دارد و وضعیتش را جایی expose نمی‌کند (ساختن endpoint
 *   کار بک‌اند است، نه فرانت). پس این فایل `devMode` را همیشه `"unknown"`
 *   گزارش می‌کند تا UI به‌جای «خاموش است»ِ دروغین، «نامشخص» صادقانه بگوید.
 *
 * این فایل عمداً هیچ fetch ندارد: خواندن شبکه در هوکِ کامپوننت است
 * (`PanelLocksBanner`) و فقط همان یک مسیرِ موجود را می‌خواند — مسیر تازه‌ای
 * اختراع نمی‌شود. تابع خالص است تا با `node --test` قابل آزمون باشد.
 */

export type LockSummary = {
  /** افزونه‌های در انتظار بازبینی (فعال‌سازی‌شان قفل است). */
  pendingReview: number;
  /** افزونه‌های فعال با امضای نامعتبر. */
  unsignedActive: number;
  /** همیشه unknown — تا وقتی بک‌اند endpoint بدهد (نکتهٔ بالای فایل). */
  devMode: "unknown";
  hasLock: boolean;
};

export type PluginLike = {
  active?: unknown;
  review_status?: unknown;
  signature_valid?: unknown;
} | null | undefined;

/**
 * خلاصهٔ قفل‌ها از دادهٔ خامِ همان API موجود — ورودی بد ⇒ «قفلی نیست».
 *
 * پارامتر عمداً `unknown` است: پاسخ شبکه هیچ تضمینی ندارد و تستِ «ورودی
 * بد» هم باید بدون cast کامپایل شود.
 */
export function summarizeLocks(plugins: unknown): LockSummary {
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
    pendingReview,
    unsignedActive,
    devMode: "unknown",
    hasLock: pendingReview > 0 || unsignedActive > 0,
  };
}