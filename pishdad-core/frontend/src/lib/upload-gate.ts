/**
 * K4.8 — قاعدهٔ نمایش notice دروازهٔ آپلود.
 *
 * جدا از کامپوننت، چون رانندهٔ تست `node --test` است و JSX را نمی‌تواند
 * ایمپورت کند. منطق تصمیم باید مستقل از رندر قابل‌آزمون باشد.
 */

export type DevModeAvailability = {
  /** باز بودن حالت توسعه‌دهنده؛ `null` یعنی هنوز خوانده نشده. */
  enabled: boolean | null;
  /** باقی‌ماندهٔ زمان، یا `null`. */
  expiresAt: string | null;
  /** آیا اصلاً می‌شود حالت توسعه‌دهنده را باز کرد. */
  canUnlock: boolean;
};

/**
 * چه چیزی نشان داده شود.
 *
 * - `open`   حالت توسعه‌دهنده باز است، پس نصب بدون امضا ممکن است
 * - `locked` بسته است ولی راه رسمی باز کردنش وجود دارد ⇒ باید گفته شود
 * - `none`   نه باز است و نه راهی هست ⇒ چیزی گفتن فایده‌ای ندارد
 *
 * `none` وقتی برمی‌گردد که `canUnlock` هم false باشد. آن‌وقت notice فقط کاربر را
 * نگران می‌کند بدون آنکه راهی جلویش بگذارد.
 *
 * ⚠️ این تابع **هرگز** راه دور زدن دروازهٔ امضا را توضیح نمی‌دهد. فقط می‌گوید
 * مسیر رسمی کجاست. راهنمای دور زدن، در دست کاربر بی‌دانا یعنی بدافزار.
 */
export function gateNotice(state: DevModeAvailability, unsignedAllowed: boolean): "none" | "open" | "locked" {
  if (unsignedAllowed) return "open";
  if (state.enabled === true) return "open";
  if (state.canUnlock) return "locked";
  return "none";
}
