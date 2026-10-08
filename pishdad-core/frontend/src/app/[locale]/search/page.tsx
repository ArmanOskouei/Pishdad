import type { Metadata } from "next";
import { fetchSiteChrome } from "@/lib/site";
import { SiteFooter, SiteHeader, themeVars } from "@/components/site/Chrome";
import { SiteSearchResults } from "@/components/site/SiteSearchResults";
import { isPublicLocale, publicT, type PublicLocale } from "@/lib/i18n/public";

export const revalidate = 60;

type Props = {
  params: Promise<{ locale: string }>;
  searchParams: Promise<Record<string, string | undefined>>;
};

async function readLocale(params: Props["params"]): Promise<PublicLocale> {
  const { locale } = await params;
  return isPublicLocale(locale) ? locale : "fa";
}

export async function generateMetadata({ params, searchParams }: Props): Promise<Metadata> {
  const locale = await readLocale(params);
  const sp = await searchParams;
  const q = (sp.q ?? "").trim();
  return {
    title: q ? publicT(locale, "search.queryTitle", { q }) : publicT(locale, "search.pageTitle"),
    robots: { index: false, follow: true },
  };
}

/** صفحه نتایج جستجوی عمومی زبان دوم: /en/search?q= */
export default async function LocaleSiteSearchPage({ params, searchParams }: Props) {
  const locale = await readLocale(params);
  const sp = await searchParams;
  const q = (sp.q ?? "").trim();
  const chrome = await fetchSiteChrome().catch(() => null);
  return (
    <div className="site" style={themeVars(chrome)} dir={locale === "en" ? "ltr" : "rtl"}>
      <SiteHeader chrome={chrome} locale={locale} />
      <div className="site-body">
        <main>
          <SiteSearchResults initialQ={q} locale={locale} />
        </main>
      </div>
      <SiteFooter chrome={chrome} locale={locale} />
    </div>
  );
}
