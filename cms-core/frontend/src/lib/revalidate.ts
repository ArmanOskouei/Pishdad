/**
 * F3 / L-B8 — تگ‌های مجاز مسیر محلی revalidate و منطق allowlist.
 *
 * ## چرا این فایل جدا شد
 *
 * این منطق داخل `app/api/revalidate/route.ts` بود، و آن فایل `next/server` را
 * import می‌کند پس در `node --test` قابل بارگذاری نیست. نتیجه: هیچ تستی
 * allowlist را نمی‌سنجید و یک تگِ جاافتاده (مثل `site-chrome`) فقط با باگ
 * زمانیِ ۳۰۰ثانیه‌ای در production دیده می‌شد. حالا خودِ سیاست اینجاست و
 * route فقط آن را مصرف می‌کند.
 */

/** پنجرهٔ تازگیِ امضای payload (ثانیه) — باید با `config/revalidate.php` یکی باشد. */
export const REVALIDATE_LEEWAY = 300;

/**
 * تگ‌های واقعیِ بک‌اند:
 *   - `site-chrome` (SiteSettings/Layout/Theme activation)
 *   - `pages` + `page:{slug}` (Page publish/delete)
 *   - `site-theme` (Theme activation)
 *   - `site-status` / `site-homepage` (suspension/homepage publish)
 *
 * تگِ مردهٔ `theme` فقط برای سازگاری با مسیر قدیمی نگه داشته شده است.
 */
export const ALLOWED_LOCAL_TAGS: ReadonlySet<string> = new Set([
  "pages",
  "theme",
  "site-chrome",
  "site-theme",
  "site-status",
  "site-homepage",
]);

/** آیا این تگ را مسیر محلیِ ادمین می‌تواند باطل کند؟ */
export function isAllowedLocalTag(t: string): boolean {
  if (ALLOWED_LOCAL_TAGS.has(t)) return true;
  // تگ‌های per-page به شکل `page:{slug}` — تعدادشان نامحدود است پس prefix.
  if (t.startsWith("page:") && t.length > 5) return true;
  // تگ‌های `site-*` آینده بدون بازنویسی allowlist.
  if (t.startsWith("site-") && t.length > 5) return true;
  return false;
}
