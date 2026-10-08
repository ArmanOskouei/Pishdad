/**
 * WF-M20 — وضعیتِ رضایتِ کوکیِ سایتِ عمومی.
 *
 * سیاستِ این نصب «فقط کوکی ضروری» است: تحلیل بازدید کوکی‌ندارد
 * (`PageViewBeacon` با `sessionStorage` کار می‌کند). پس بنر فقط برای اطلاع و
 * ثبتِ رضایت است و انتخابِ کاربر در `localStorage` می‌ماند — نه در کوکی — چون
 * خودِ بنر نباید چیزی را ردیابی کند.
 *
 * منطقِ خالص و جدا نگه داشته شده تا بدون رندرِ React هم آزمون‌پذیر باشد و
 * کامپوننتِ بنر فقط یک پوستهٔ نازک بماند.
 */

export const COOKIE_CONSENT_KEY = "cms-cookie-consent";
export const COOKIE_CONSENT_ACCEPTED = "accepted";

/** کمینهٔ چیزی که از `Storage` لازم داریم — تا تست بتواند جعلیِ ساده بدهد. */
export interface ConsentStorage {
  getItem(key: string): string | null;
  setItem(key: string, value: string): void;
}

/** آیا کاربر قبلاً رضایت داده؟ خطای دسترسی (حالت خصوصی) ⇒ «نه» تا بنر دیده شود. */
export function hasCookieConsent(storage: Pick<ConsentStorage, "getItem"> | null | undefined): boolean {
  try {
    return storage?.getItem(COOKIE_CONSENT_KEY) === COOKIE_CONSENT_ACCEPTED;
  } catch {
    return false;
  }
}

/** ثبتِ رضایت؛ خطای دسترسی بی‌صدا نادیده گرفته می‌شود (نباید تجربهٔ کاربر را بشکند). */
export function acceptCookieConsent(storage: Pick<ConsentStorage, "setItem"> | null | undefined): void {
  try {
    storage?.setItem(COOKIE_CONSENT_KEY, COOKIE_CONSENT_ACCEPTED);
  } catch {
    // حالت خصوصی/بدون دسترسی: فقط همین صفحه پنهان می‌شود.
  }
}

/**
 * مقصدِ لینکِ «سیاست حریم خصوصی» برای زبانِ جاری.
 *
 * مدل مسیرِ دوزبانه (a): زبانِ پایه در ریشه و زبانِ دوم زیر پیشوند. چون
 * `usePathname` ممکن است پیشوند داشته باشد یا نه، از همان پیشوندِ موجود
 * استفاده می‌کنیم؛ مسیرِ بدون پیشوند یعنی زبانِ پایه ⇒ `/privacy`.
 */
export function privacyPathFor(pathname: string): string {
  const seg = (pathname.split("/").filter(Boolean)[0] ?? "").toLowerCase();
  return seg === "fa" || seg === "en" ? `/${seg}/privacy` : "/privacy";
}
