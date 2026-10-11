import type { Metadata } from "next";
import { headers } from "next/headers";
import { notFound, redirect } from "next/navigation";
import { fetchHomepageFallback, fetchSiteChrome, siteBaseUrl, type SiteChrome } from "@/lib/site";
import { PushOptInBanner } from "@/components/site/PushOptInBanner";
import {
  PreparingSite,
  ThemedHome,
  effectiveLocale,
  pageMetadata,
  preparingMetadata,
} from "@/components/site/PublicPage";
import type { SitePage } from "@/lib/domain";
import { isPublicLocale, publicOgLocale, type PublicLocale } from "@/lib/i18n/public";
import {
  buildAlternatesLanguages,
  localeFallbackChain,
  localePublicPath,
  parseLocalesHeader,
  pickBrowserLocale,
} from "@/lib/public-routing";

/**
 * ECO2 — صفحهٔ خانهٔ زبانِ دوم (`/en`).
 *
 * قالب را همان `ThemedHome` رندر می‌کند؛ فقط زبان از `params.locale` می‌آید و
 * متادیتا زبان‌دار می‌شود. دکمهٔ تغییر زبان در پوستهٔ قالب (SiteHeader) است.
 */

export const revalidate = 300;
// PERF-1 - middleware rewrite alone opts the route into dynamic rendering
// in Next 16 (proven: no-store without it, HIT with dynamic=error and zero
// DynamicServerErrors, i.e. the tree uses no dynamic APIs). force-static keeps
// the URL design (bare primary URLs) AND the 300s cache.
export const dynamic = "force-static";

type Props = { params: Promise<{ locale: string }> };

/**
 * این روت فقط زبانی را سرو می‌کند که میدل‌ور در `x-site-locales` اعلام کرده
 * (شاملِ زبانِ پایه که مسیرِ ریشه به آن بازنویسی می‌شود). نبودِ هدر = میدل‌ور
 * خاموش ⇒ دروازه را باز می‌گذاریم تا رندرِ مستقیم هم نشکند.
 */
async function localeAvailable(locale: PublicLocale): Promise<boolean> {
  // PERF-1 — قبلاً `headers()` می‌خواند و کل صفحه را داینامیک می‌کرد. حالا
  // فهرستِ زبان‌ها از خودِ کروم (که همین حالا برای متادیتا خوانده شده) می‌آید
  // و like before fail-open است.
  const chrome = await fetchSiteChrome().catch(() => null);
  const { locales } = effectiveLocale(chrome, locale);
  return locales.length === 0 || locales.includes(locale);
}

/**
 * WF-H15 — متادیتای صفحهٔ خانه با `hreflang` کامل + `x-default`.
 *
 * `alternates` را از روی زبانِ واقعیِ خانه (که با fallback پیدا شده) می‌سازد تا
 * در صورت نبودِ نسخهٔ زبانِ درخواستی، canonical به نسخهٔ موجود اشاره کند و
 * محتوای تکراری ساخته نشود.
 */
export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { locale: raw } = await params;
  const locale: PublicLocale = isPublicLocale(raw) ? raw : "fa";
  const chrome = await fetchSiteChrome().catch(() => null);
  const { locales, primary } = effectiveLocale(chrome, locale);
  const chain = localeFallbackChain(locale, locales, primary);
  const found = await fetchHomepageFallback(chain);
  if (!found) return { ...preparingMetadata(locale) };
  const resolved: PublicLocale = isPublicLocale(found.locale) ? found.locale : locale;
  const base = siteBaseUrl(chrome);
  const canonical = base ? `${base}${localePublicPath(resolved, "", primary)}` : undefined;
  const languages = base ? buildAlternatesLanguages({ base, slug: "", locales, primary }) : undefined;
  const md = pageMetadata(chrome as SiteChrome | null, found.page as SitePage, resolved, {
    ...(canonical ? { alternates: { canonical, ...(languages ? { languages } : null) } } : null),
  });
  return { ...md, openGraph: { ...(md.openGraph ?? {}), locale: publicOgLocale(resolved) } };
}

/**
 * WF-H15 — fallback زبان برای صفحهٔ خانه.
 *
 * اگر خانه در زبانِ درخواستی منتشر نشده باشد، زبانِ مرورگر و سپس زبانِ پایه
 * امتحان می‌شود و در صورت یافتن، ۳۰۸ به مسیرِ همان زبان می‌رود (برای زبانِ پایه
 * مسیرِ `/`). فقط زبان‌های پیکربندی‌شده کاندید‌اند، پس حلقهٔ ریدایرکت ممکن
 * نیست؛ اگر هیچ نسخه‌ای نباشد، همان صفحهٔ «در حال آماده‌سازی» قبلی می‌آید.
 */
export default async function LocaleHome({ params }: Props) {
  const { locale: raw } = await params;
  const locale: PublicLocale = isPublicLocale(raw) ? raw : "fa";
  if (!(await localeAvailable(locale))) notFound();

  const chrome = await fetchSiteChrome().catch(() => null);
  const { locales, primary } = effectiveLocale(chrome, locale);
  const chain = localeFallbackChain(locale, locales, primary);
  const found = await fetchHomepageFallback(chain);

  if (!found) {
    return <PreparingSite chrome={chrome} locale={locale} />;
  }

  const resolved: PublicLocale = isPublicLocale(found.locale) ? found.locale : locale;
  // PERF-1: a redirect here used to force the whole route dynamic (revalidate
  // 300 was dead). The found locale is rendered in place; canonical/hreflang
  // in the metadata already points at it.

  return <ThemedHome page={found.page as SitePage} chrome={chrome} locale={resolved} banner={<PushOptInBanner />} />;
}
