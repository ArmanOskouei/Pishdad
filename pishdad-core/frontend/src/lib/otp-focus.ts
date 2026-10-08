/**
 * محاسبهٔ کادر بعدیِ کد یک‌بارمصرف.
 *
 * ## چرا این تابع بیرون از کامپوننت است
 *
 * منطقش یک خط بود ولی **یک واحد اختلاف** داشت و همان یک واحد یعنی «کاربر باید
 * دستی کلیک کند یا TAB بزند». چون داخل `onChange` زندگی می‌کرد، نه تست
 * می‌شد نه review می‌شد؛ فقط در مرورگر لو می‌رفت.
 *
 * قرارداد: `digits` رقم‌هایی است که در کادرهای `start` به بعد نوشته شد.
 * جواب باید **اولین کادرِ بعد از آخرین رقمِ نوشته‌شده** باشد، نه خودِ آن کادر.
 */

export const OTP_LENGTH = 6;

/** `start` = کادری که کاربر در آن تایپ کرد، `digits` = چند رقم وارد شد. */
export function nextOtpFocusIndex(start: number, digits: number, otpLength = OTP_LENGTH): number {
  const written = Math.max(digits, 1);
  return Math.min(start + written, otpLength - 1);
}
