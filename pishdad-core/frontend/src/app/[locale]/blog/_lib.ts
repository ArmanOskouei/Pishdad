import { notFound } from "next/navigation";
import { isPublicLocale, type PublicLocale } from "@/lib/i18n/public";
import { fetchSiteChrome } from "@/lib/site";
import { effectiveLocale } from "@/components/site/PublicPage";

/**
 * WF-C7 — زبانِ مؤثرِ مسیرِ بلاگ.
 *
 * PERF-1 — قبلاً `headers()` می‌خواند و کلِ مسیرهای بلاگ (فهرست، برچسب،
 * دسته، خوراک) را داینامیک می‌کرد ⇒ هیچ‌کدام ISR نمی‌شدند. حالا فهرستِ
 * زبان‌ها از کروم می‌آید (همان چیزی که این صفحه‌ها همین حالا می‌خوانند) و
 * مثل قبل fail-open است.
 */
export async function requireLocale(raw: string): Promise<PublicLocale> {
  const locale: PublicLocale = isPublicLocale(raw) ? raw : "fa";
  const chrome = await fetchSiteChrome().catch(() => null);
  const { locales } = effectiveLocale(chrome, locale);
  if (locales.length > 0 && !locales.includes(locale)) notFound();

  return locale;
}
