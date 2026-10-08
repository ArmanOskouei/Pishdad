import jalaali from "jalaali-js";

const FA_DIGITS = ["۰", "۱", "۲", "۳", "۴", "۵", "۶", "۷", "۸", "۹"];

/** اعداد فارسی: 123 → ۱۲۳ */
export function faNum(n: number | string): string {
  return String(n).replace(/[0-9]/g, (d) => FA_DIGITS[Number(d)]);
}

/** تاریخ شمسی: ISO → Y/m/d H:i (فارسی) */
export function jalali(iso: string | null | undefined): string {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  const j = jalaali.toJalaali(d);
  const pad = (x: number) => String(x).padStart(2, "0");
  return faNum(`${j.jy}/${pad(j.jm)}/${pad(j.jd)} ${pad(d.getHours())}:${pad(d.getMinutes())}`);
}

/** تاریخ شمسی کوتاه: فقط تاریخ */
export function jalaliDate(iso: string | null | undefined): string {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  const j = jalaali.toJalaali(d);
  const pad = (x: number) => String(x).padStart(2, "0");
  return faNum(`${j.jy}/${pad(j.jm)}/${pad(j.jd)}`);
}

/**
 * فاصله نسبی فارسی: «۳ روز پیش» / «۱۲ روز مانده».
 *
 * ⭐ **دقت: روز (F0-B3).** این تابع *ساعت و دقیقه* را عمداً دور می‌کند و
 * فقط «چند روز» می‌گوید. یعنی:
 *
 *  - ۳ ساعت پیش ⇒ «امروز» (ولی ۳ ساعت گذشته است)
 *  - ۲۰ ساعت پیش ⇒ «امروز»
 *  - ۲۰ ساعت **دیگر** ⇒ «امروز» (چون ۰٫۸۳ روز به ۱ گرد می‌شود)
 *  - ۳۶ ساعت پیش ⇒ «۲ روز پیش»
 *
 * این گرد‌کردن **عمدی** است: عددِ ساعتی برای کاربرِ فارسی‌زبان («۲۰ ساعت پیش»
 * برای چیزی که در واقع دیروز اتفاق افتاده) گمراه‌کننده‌تر از «امروز» است.
 *
 * ⚠️ **چه وقت استفاده نکن.** اگر می‌خواهی بدانی «دقیقاً کِی» — ممیزی، لاگ،
 *   فاکتور، انقضای مهلت — از `jalali()` استفاده کن که تاریخ و ساعت را می‌دهد.
 *   برای «کمتر از یک روز» هم این تابع جوابِ خوبی ندارد؛ متنِ «امروز» بگذار و
 *   ساعتِ دقیق را کنارش از `jalali()` بیاور.
 */
export function relativeFa(iso: string | null | undefined): string {
  if (!iso) return "—";
  const d = new Date(iso).getTime();
  if (Number.isNaN(d)) return "—";
  // نگه‌داشتنِ متغیر جدا، برای اینکه `Math.round` فقط یک‌بار اعمال شود — گرد
  // کردنِ میانهٔ محاسبه و سپس استفادهٔ دوباره، خطای نیم‌روز می‌سازد.
  const diffDays = Math.round((d - Date.now()) / 86_400_000);
  if (diffDays === 0) return "امروز";
  if (diffDays > 0) return `${faNum(diffDays)} روز مانده`;
  return `${faNum(Math.abs(diffDays))} روز پیش`;
}

/**
 * فاصلهٔ نسبیِ **دقیق** برای فید اعلان (F0-B3).
 *
 * `relativeFa` عمداً فقط «روز» می‌شمارد (برای سرآمدِ اشتراک خوب است)، ولی برای
 * فید اعلان بی‌مصرف بود: هر اعلانِ امروز «امروز» می‌شد و کاربر نمی‌فهمید
 * «۵ دقیقه پیش» بوده یا «۲۰ ساعت پیش». این تابع برای بازهٔ کمتر از یک روز،
 * دقیقه/ساعت می‌دهد و بعد از آن به همان منطقِ روز واگذار می‌کند.
 */
export function relativeFaFine(iso: string | null | undefined): string {
  if (!iso) return "—";
  const d = new Date(iso).getTime();
  if (Number.isNaN(d)) return "—";
  const diffMs = Date.now() - d; // مثبت = گذشته، منفی = آینده
  const abs = Math.abs(diffMs);
  if (abs < 60_000) return diffMs >= 0 ? "همین حالا" : "در لحظاتی";
  if (abs < 3_600_000) {
    const m = Math.floor(abs / 60_000);
    return diffMs >= 0 ? `${faNum(m)} دقیقه پیش` : `${faNum(m)} دقیقه دیگر`;
  }
  if (abs < 86_400_000) {
    const h = Math.floor(abs / 3_600_000);
    return diffMs >= 0 ? `${faNum(h)} ساعت پیش` : `${faNum(h)} ساعت دیگر`;
  }
  return relativeFa(iso);
}
