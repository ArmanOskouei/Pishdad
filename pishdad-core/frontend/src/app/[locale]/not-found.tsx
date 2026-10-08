import type { Metadata } from "next";
import { headers } from "next/headers";
import { fetchSiteChrome, fetchSitePages } from "@/lib/site";
import { NotFoundSite } from "@/components/site/NotFoundSite";
import { effectiveLocale } from "@/components/site/PublicPage";
import { notFoundSuggestions } from "@/lib/not-found";
import { DEFAULT_PUBLIC_LOCALE, isPublicLocale, publicT, type PublicLocale } from "@/lib/i18n/public";
import { SITE_LOCALE_HEADER } from "@/lib/public-routing";

/**
 * WF-M18 — مرزِ ۴۰۴ قالب‌دار برای همهٔ صفحاتِ عمومی.
 *
 * هر دو زبان زیر همین درخت‌اند (مسیرِ بدون پیشوند با rewrite به `/{primary}`
 * می‌آید)، پس این یک فایل هم `/en/...` و هم مسیرهای زبانِ پایه را می‌پوشاند.
 * زبان از همان هدری خوانده می‌شود که لایهٔ `[locale]` می‌خواند (بدون params،
 * چون `not-found` پارامتر نمی‌گیرد).
 *
 * متادیتای ۴۰۴ با `noindex` می‌آید؛ Next برای پاسخ‌های ۴۰۴ هم خودش
 * `noindex` تزریق می‌کند، ولی این‌جا صریح می‌بندیم تا صفحهٔ `/_not-found` هم
 * بدونِ تکیه بر رفتار پیش‌فرض ایندکس نشود.
 */

async function readLocale(): Promise<PublicLocale> {
  const h = await headers();
  const raw = h.get(SITE_LOCALE_HEADER);
  return isPublicLocale(raw) ? raw : DEFAULT_PUBLIC_LOCALE;
}

export async function generateMetadata(): Promise<Metadata> {
  const locale = await readLocale();
  return {
    title: publicT(locale, "notFound.title"),
    robots: { index: false, follow: false },
  };
}

export default async function LocaleNotFound() {
  const locale = await readLocale();
  const chrome = await fetchSiteChrome().catch(() => null);
  const { primary } = effectiveLocale(chrome, locale);
  const pages = await fetchSitePages(locale).catch(() => []);
  const suggestions = notFoundSuggestions(pages, locale, primary);

  return <NotFoundSite chrome={chrome} locale={locale} suggestions={suggestions} />;
}
