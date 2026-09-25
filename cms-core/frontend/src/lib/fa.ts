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

/** فاصله نسبی فارسی: «۳ روز پیش» / «۱۲ روز مانده» */
export function relativeFa(iso: string | null | undefined): string {
  if (!iso) return "—";
  const d = new Date(iso).getTime();
  if (Number.isNaN(d)) return "—";
  const diffDays = Math.round((d - Date.now()) / 86_400_000);
  if (diffDays === 0) return "امروز";
  if (diffDays > 0) return `${faNum(diffDays)} روز مانده`;
  return `${faNum(Math.abs(diffDays))} روز پیش`;
}
