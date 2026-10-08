import type { Metadata } from "next";
import { BLOG_PER_PAGE, parseBlogPage } from "@/lib/blog";
import { fetchBlogPosts, fetchSiteChrome, siteBaseUrl, type SiteChrome } from "@/lib/site";
import { effectiveLocale } from "@/components/site/PublicPage";
import { resolveBlogTheme } from "@/themes/registry";
import { publicT } from "@/lib/i18n/public";
import { requireLocale } from "./_lib";

/**
 * WF-C7 — بایگانی بلاگ (صفحه‌بندی‌شده).
 *
 * داده در سرور بارگذاری می‌شود (بدون setState در render). رندرِ نهایی به
 * اسلاتِ `blog` قالب فعال واگذار می‌شود.
 */
export const revalidate = 300;

type Props = {
  params: Promise<{ locale: string }>;
  searchParams: Promise<Record<string, string | string[] | undefined>>;
};

export async function generateMetadata({ params, searchParams }: Props): Promise<Metadata> {
  const locale = await requireLocale((await params).locale);
  const page = parseBlogPage((await searchParams).page);
  const chrome = await fetchSiteChrome().catch(() => null);
  const { primary } = effectiveLocale(chrome, locale);
  const base = siteBaseUrl(chrome);
  const path = `${locale === primary ? "" : `/${locale}`}/blog${page > 1 ? `?page=${page}` : ""}`;
  return {
    ...(base ? { metadataBase: new URL(base) } : null),
    title: publicT(locale, "blog.archiveTitle"),
    description: chrome?.description || undefined,
    robots: page > 1 ? { index: false, follow: true } : { index: true, follow: true },
    ...(base ? { alternates: { canonical: `${base}${path}` } } : null),
  };
}

export default async function BlogArchiveRoute({ params, searchParams }: Props) {
  const locale = await requireLocale((await params).locale);
  const page = parseBlogPage((await searchParams).page);

  const [chrome, paginator] = await Promise.all([
    fetchSiteChrome().catch(() => null),
    fetchBlogPosts(locale, { page, perPage: BLOG_PER_PAGE }).catch(() => null),
  ]);

  const Blog = resolveBlogTheme((chrome as SiteChrome | null)?.theme?.slug);
  const { locales, primary } = effectiveLocale(chrome, locale);
  const switcher = locales.length > 1 ? { locales, primary } : undefined;

  return (
    <Blog
      kind="index"
      locale={locale}
      chrome={chrome}
      schemas={chrome?.blocks}
      switcher={switcher}
      archive={{
        posts: paginator?.data ?? [],
        page: paginator?.current_page ?? page,
        lastPage: paginator?.last_page ?? 1,
        total: paginator?.total ?? 0,
        perPage: paginator?.per_page ?? BLOG_PER_PAGE,
      }}
    />
  );
}
