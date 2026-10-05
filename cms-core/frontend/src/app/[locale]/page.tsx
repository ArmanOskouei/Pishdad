import type { Metadata } from "next";
import { headers } from "next/headers";
import { notFound } from "next/navigation";
import { fetchHomepage, fetchSiteChrome, type SiteChrome } from "@/lib/site";
import { PushOptInBanner } from "@/components/site/PushOptInBanner";
import {
  PreparingSite,
  ThemedHome,
  pageMetadata,
  preparingMetadata,
  suspendedNotice,
} from "@/components/site/PublicPage";
import type { SitePage } from "@/lib/domain";
import { isPublicLocale, publicOgLocale, type PublicLocale } from "@/lib/i18n/public";
import { parseLocalesHeader, SITE_LOCALES_HEADER } from "@/lib/public-routing";

/**
 * ECO2 — صفحهٔ خانهٔ زبانِ دوم (`/en`).
 *
 * قالب را همان `ThemedHome` رندر می‌کند؛ فقط زبان از `params.locale` می‌آید و
 * متادیتا زبان‌دار می‌شود. دکمهٔ تغییر زبان در پوستهٔ قالب (SiteHeader) است.
 */

export const revalidate = 300;

type Props = { params: Promise<{ locale: string }> };

/**
 * این روت فقط زبانی را سرو می‌کند که میدل‌ور در `x-site-locales` اعلام کرده
 * (شاملِ زبانِ پایه که مسیرِ ریشه به آن بازنویسی می‌شود). نبودِ هدر = میدل‌ور
 * خاموش ⇒ دروازه را باز می‌گذاریم تا رندرِ مستقیم هم نشکند.
 */
async function localeAvailable(locale: PublicLocale): Promise<boolean> {
  const h = await headers();
  const list = parseLocalesHeader(h.get(SITE_LOCALES_HEADER));
  return list.length === 0 || list.includes(locale);
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { locale: raw } = await params;
  const locale: PublicLocale = isPublicLocale(raw) ? raw : "fa";
  const [page, chrome] = await Promise.all([
    fetchHomepage(locale).catch(() => null),
    fetchSiteChrome().catch(() => null),
  ]);
  if (!page) return { ...preparingMetadata(locale) };
  const md = pageMetadata(chrome as SiteChrome | null, page as SitePage, locale);
  return { ...md, openGraph: { ...(md.openGraph ?? {}), locale: publicOgLocale(locale) } };
}

export default async function LocaleHome({ params }: Props) {
  const { locale: raw } = await params;
  const locale: PublicLocale = isPublicLocale(raw) ? raw : "fa";
  if (!(await localeAvailable(locale))) notFound();

  const suspended = await suspendedNotice(locale);
  if (suspended) return suspended;

  const [page, chrome] = await Promise.all([
    fetchHomepage(locale).catch(() => null),
    fetchSiteChrome().catch(() => null),
  ]);

  if (!page) {
    return <PreparingSite chrome={chrome} locale={locale} />;
  }

  return <ThemedHome page={page as SitePage} chrome={chrome} locale={locale} banner={<PushOptInBanner />} />;
}
