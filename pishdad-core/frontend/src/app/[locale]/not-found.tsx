import type { Metadata } from "next";
import { fetchSiteChrome, fetchSitePages } from "@/lib/site";
import { NotFoundSite } from "@/components/site/NotFoundSite";
import { effectiveLocale } from "@/components/site/PublicPage";
import { notFoundSuggestions } from "@/lib/not-found";
import { DEFAULT_PUBLIC_LOCALE, publicT, type PublicLocale } from "@/lib/i18n/public";

/**
 * WF-M18 — مرزِ ۴۰۴ قالب‌دار برای همهٔ صفحاتِ عمومی.
 *
 * هر دو زبان زیر همین درخت‌اند (مسیرِ بدون پیشوند با rewrite به `/{primary}`
 * می‌آید)، پس این یک فایل هم `/en/...` و هم مسیرهای زبانِ پایه را می‌پوشاند.
 *
 * PERF-1 — `not-found` در Next پارامتر نمی‌گیرد، و `headers()` کل درخت را
 * داینامیک می‌کرد؛ پس متنِ ۴۰۴ همیشه به زبانِ پایه است (`robots: noindex`
 * سرِ جایش است، پس رگرسیونِ SEO عملاً صفر است).
 *
 * متادیتای ۴۰۴ با `noindex` می‌آید؛ Next برای پاسخ‌های ۴۰۴ هم خودش
 * `noindex` تزریق می‌کند، ولی این‌جا صریح می‌بندیم تا صفحهٔ `/_not-found` هم
 * بدونِ تکیه بر رفتار پیش‌فرض ایندکس نشود.
 */

function readLocale(): PublicLocale {
  return DEFAULT_PUBLIC_LOCALE;
}

export async function generateMetadata(): Promise<Metadata> {
  const locale = readLocale();
  return {
    title: publicT(locale, "notFound.title"),
    robots: { index: false, follow: false },
  };
}

export default async function LocaleNotFound() {
  const locale = readLocale();
  const chrome = await fetchSiteChrome().catch(() => null);
  const { primary } = effectiveLocale(chrome, locale);
  const pages = await fetchSitePages(locale).catch(() => []);
  const suggestions = notFoundSuggestions(pages, locale, primary);

  return <NotFoundSite chrome={chrome} locale={locale} suggestions={suggestions} />;
}
