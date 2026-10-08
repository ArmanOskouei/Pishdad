/**
 * ECO3 — انتخاب زبانِ محتوای مستندات توسعه‌دهنده.
 *
 * قرارداد هسته برای متن‌های انسان‌خوان دو نسخه دارد (`label_fa`/`label_en`).
 * این ماژول **خالص** است و فقط تصمیم می‌گیرد کدام نسخه را برگرداند؛ هیچ
 * رندری اینجا نیست تا قابل‌تست باشد (همان الگوی `crumbs.ts`).
 *
 * قاعدهٔ افتادن: اگر نسخهٔ زبانِ جاری نبود، نسخهٔ فارسی. عمداً هیچ‌وقت خالی
 * برنمی‌گرداند — خالی یعنی «سطر ناپدید می‌شود»، نه «به زبان دیگر می‌رود».
 */

export type Lang = "fa" | "en";

/** `value_fa`/`value_en` را از یک آبجکت قرارداد می‌خواند. */
export function pick(
  lang: Lang,
  fa: string | undefined,
  en: string | undefined,
): string {
  if (lang === "en") return en && en.trim() !== "" ? en : (fa ?? "");
  return fa && fa.trim() !== "" ? fa : (en ?? "");
}

/** نسخهٔ `pick` برای فیلدهای پسونددارِ قرارداد (`label_fa`/`label_en`). */
export function pickSuffixed(
  lang: Lang,
  record: Record<string, unknown>,
  base: string,
): string {
  const fa = typeof record[`${base}_fa`] === "string" ? (record[`${base}_fa`] as string) : undefined;
  const en = typeof record[`${base}_en`] === "string" ? (record[`${base}_en`] as string) : undefined;
  return pick(lang, fa, en);
}

/** نمونهٔ nice برای نمایش در `<pre>` — کلیدها مرتب تا diff خوانا بماند. */
export function prettyJson(value: unknown): string {
  return JSON.stringify(value, null, 2) ?? "";
}
