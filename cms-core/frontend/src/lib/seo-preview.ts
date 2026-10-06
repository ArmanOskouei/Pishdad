/**
 * WF-M1 — منطق خالصِ پیش‌نمایش زندهٔ SERP.
 *
 * چرا جدا از کامپوننت: `npm test` فقط `*.test.ts` را می‌خواند و TSX را نه.
 * شمارش کاراکتر و بریدنِ متن باید بدون مرورگر سنجیده شود؛ وگرنه یک آف‌بای‌یک
 * در `String.length` یا مسیرِ URL تا وقتی کاربر رنگِ اشتباه ببیند پنهان می‌ماند.
 */

export type SerpCharState = "short" | "good" | "long";

export const SERP_TITLE_MIN = 30;
export const SERP_TITLE_MAX = 60;
export const SERP_DESC_MIN = 70;
export const SERP_DESC_MAX = 160;

/**
 * شمارش کاراکتر بر پایهٔ code point (نه واحد UTF-16)؛ ایموجی و نویسه‌های
 * فراسوی BMP یک کاراکتر شمرده می‌شوند، همان‌طور که کاربر می‌بیند.
 */
export function serpLength(text: string): number {
  if (typeof text !== "string" || text.length === 0) return 0;
  return Array.from(text).length;
}

/** وضعیت طول نسبت به بازهٔ پیشنهادی: کم/خوب/زیاد. */
export function serpCharState(len: number, min: number, max: number): SerpCharState {
  const n = Number.isFinite(len) ? Math.max(0, Math.trunc(len)) : 0;
  if (n < min) return "short";
  if (n > max) return "long";
  return "good";
}

/**
 * بریدنِ متن برای نمایش در SERP؛ اگر بلندتر از `max` باشد، `max` کاراکتر اول
 * (بدون فاصلهٔ انتهایی) به‌همراه «…» برمی‌گردد.
 */
export function truncateForSerp(text: string, max: number): string {
  if (typeof text !== "string" || text.length === 0) return "";
  if (!Number.isFinite(max) || max <= 0) return "";
  const chars = Array.from(text);
  if (chars.length <= max) return text;
  return chars.slice(0, max).join("").replace(/\s+$/, "") + "…";
}

export type SerpUrlParts = { host: string; path: string };

function safeDecode(value: string): string {
  try {
    return decodeURI(value);
  } catch {
    return value;
  }
}

/**
 * تفکیک نشانیِ نمایشی SERP به میزبان + مسیر. اگر کنونیکالِ مطلق داده شده باشد
 * میزبانش نگه داشته می‌شود؛ وگرنه نمایش بر پایهٔ slug و میزبانِ پیش‌فرض است
 * (پنل ادمین نشانیِ سایت را در این کامپوننت در اختیار ندارد).
 */
export function serpUrlParts(
  canonical: string,
  slug: string,
  fallbackHost = "example.com",
): SerpUrlParts {
  const c = typeof canonical === "string" ? canonical.trim() : "";
  if (c !== "") {
    try {
      const u = new URL(c);
      const path = u.pathname === "/" ? "" : safeDecode(u.pathname);
      return { host: u.host, path };
    } catch {
      if (c.startsWith("/")) return { host: fallbackHost, path: safeDecode(c) };
    }
  }
  const s = (typeof slug === "string" ? slug : "").trim().replace(/^\/+|\/+$/g, "");
  return { host: fallbackHost, path: s === "" ? "" : safeDecode(`/${s}`) };
}
