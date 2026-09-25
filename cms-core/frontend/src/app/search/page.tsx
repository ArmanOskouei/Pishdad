import type { Metadata } from "next";
import { fetchSiteChrome } from "@/lib/site";
import { SiteFooter, SiteHeader, themeVars } from "@/components/site/Chrome";
import { SiteSearchResults } from "@/components/site/SiteSearchResults";

export const revalidate = 60;

export async function generateMetadata({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}): Promise<Metadata> {
  const sp = await searchParams;
  const q = (sp.q ?? "").trim();
  return {
    title: q ? `جستجو: ${q}` : "جستجو در سایت",
    robots: { index: false, follow: true },
  };
}

/** صفحه نتایج جستجوی عمومی: /search?q= — روی همه صفحات منتشرشده (عنوان + متن). */
export default async function SiteSearchPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const sp = await searchParams;
  const q = (sp.q ?? "").trim();
  const chrome = await fetchSiteChrome().catch(() => null);
  return (
    <div className="site" style={themeVars(chrome)}>
      <SiteHeader chrome={chrome} />
      <div className="site-body">
        <main>
          <SiteSearchResults initialQ={q} />
        </main>
      </div>
      <SiteFooter chrome={chrome} />
    </div>
  );
}
