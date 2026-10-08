import type { Metadata } from "next";
import { headers } from "next/headers";
import { isPublicLocale, publicDir, publicHtmlLang, type PublicLocale } from "@/lib/i18n/public";
import { PageViewBeacon } from "@/components/site/PageViewBeacon";
import { WebVitalsBeacon } from "@/components/site/WebVitalsBeacon";
import { CookieConsentBanner } from "@/components/site/CookieConsentBanner";
import { fetchSiteChrome } from "@/lib/site";

/**
 * WF-H1 — کد تأیید مالکیت Google Search Console روی `<head>` همهٔ صفحاتِ
 * عمومی. `metadata.verification` در لایهٔ عمومی می‌نشیند تا با متادیتای
 * per-page (canonical/robots) تعارض نکند.
 */
export async function generateMetadata(): Promise<Metadata> {
  const chrome = await fetchSiteChrome().catch(() => null);
  const code = chrome?.google_site_verification?.trim();
  return code ? { verification: { google: code } } : {};
}

/**
 * ECO2 — `lang`/`dir` سرور-رندرِ سایت عمومی per-locale.
 *
 * ریشه (`app/layout.tsx`) برای پنل `fa/rtl` هاردکد است و اسکریپتِ ضد-FOUC
 * فقط کلاینت را درست می‌کند. پس صفحهٔ سرور-رندرِ `/en` تا هیدریت با `lang="fa"`
 * می‌آمد — هم برای SEO غلط، هم یک پرش بصری در اولین رنگ‌آمیزی.
 *
 * این لایهٔ تودرتو `lang`/`dir` را از همان هدری می‌خواند که میدل‌ور
 * (`proxy.ts`) تعیین کرده، پس یک منبع حقیقت می‌ماند و به fetch وابسته نیست.
 */
export default async function LocaleLayout({
  children,
  params,
}: {
  children: React.ReactNode;
  params: Promise<{ locale: string }>;
}) {
  const { locale: raw } = await params;
  const fromParams: PublicLocale = isPublicLocale(raw) ? raw : "fa";
  const h = await headers();
  const header = h.get("x-site-locale");
  const locale: PublicLocale = isPublicLocale(header) ? header : fromParams;

  return (
    <div lang={publicHtmlLang(locale)} dir={publicDir(locale)} data-locale={locale}>
      <PageViewBeacon />
      <WebVitalsBeacon />
      <CookieConsentBanner locale={locale} />
      {children}
    </div>
  );
}
