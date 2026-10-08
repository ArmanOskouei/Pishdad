/**
 * WF-L1 — نقطهٔ کانونی تصویر.
 *
 * بک‌اند مختصات نرمال‌شدهٔ `0..1` را ذخیره می‌کند و فرانت آن را به مقدار
 * `object-position` («x% y%») تبدیل می‌کند تا برشِ `object-fit: cover` چهره/لوگو
 * را خراب نکند. نبود/نامعتبری مقدار ⇒ `undefined` تا CSS خودش «وسط» (پیش‌فرضِ
 * مرورگر) را اعمال کند؛ یعنی رفتارِ قبلیِ تصاویرِ بدون نقطهٔ کانونی دست‌نخورده
 * می‌ماند.
 *
 * x/y فیزیکی‌اند (از چپ/بالا) — همان محورِ `object-position`؛ در RTL آینه
 * نمی‌شوند. میزانِ درصد با یک رقم اعشار گرد می‌شود.
 */

/** مختصات خام (عدد یا رشتهٔ عددی) را به بازهٔ 0..1 می‌برد؛ نامعتبر ⇒ null. */
export function clampFocal(value: unknown): number | null {
  const n =
    typeof value === "number"
      ? value
      : typeof value === "string" && value.trim() !== ""
        ? Number(value)
        : Number.NaN;
  if (!Number.isFinite(n)) return null;
  return Math.min(1, Math.max(0, n));
}

/** یک مختصات را به درصدِ گردشده تبدیل می‌کند (۰..۱۰۰). */
function toPercent(value: number): number {
  return Math.round(value * 1000) / 10;
}

/**
 * مقدار آمادهٔ `object-position` / `background-position` — «x% y%».
 * اگر هریک از دو محور نامعتبر باشد، `undefined` برمی‌گردد (fallback به وسط).
 */
export function focalPosition(x: unknown, y: unknown): string | undefined {
  const fx = clampFocal(x);
  const fy = clampFocal(y);
  if (fx === null || fy === null) return undefined;
  return `${toPercent(fx)}% ${toPercent(fy)}%`;
}
