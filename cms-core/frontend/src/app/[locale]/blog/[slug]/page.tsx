import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { fetchBlogPost, fetchSiteChrome, siteBaseUrl, type SiteChrome } from "@/lib/site";
import { effectiveLocale } from "@/components/site/PublicPage";
import { resolveBlogTheme } from "@/themes/registry";
import { publicOgLocale, publicT, type PublicLocale } from "@/lib/i18n/public";
import type { SitePage } from "@/lib/domain";
import { requireLocale } from "../_lib";

/**
 * WF-C7 — صفحهٔ تک‌نوشته (نویسنده/تاریخ/تصویر + JSON-LD Article).
 * رندر از اسلاتِ `blog` قالب فعال می‌آید.
 */
export const revalidate = 300;

type Props = { params: Promise<{ locale: string; slug: string }> };

function metaStr(meta: Record<string, unknown>, key: string): string | undefined {
  const v = meta[key];
  return typeof v === "string" && v ? v : undefined;
}

function blogPath(locale: PublicLocale, primary: PublicLocale, slug: string): string {
  const prefix = locale === primary ? "" : `/${locale}`;
  return `${prefix}/blog/${encodeURIComponent(slug)}`;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { locale: raw, slug } = await params;
  const locale = await requireLocale(raw);
  const [page, chrome] = await Promise.all([
    fetchBlogPost(slug, locale),
    fetchSiteChrome().catch(() => null),
  ]);
  if (!page) {
    return { title: publicT(locale, "blog.notFound"), robots: { index: false, follow: false } };
  }

  const meta = (page.meta ?? {}) as Record<string, unknown>;
  const base = siteBaseUrl(chrome);
  const { primary } = effectiveLocale(chrome, locale);
  const title = metaStr(meta, "title") ?? page.title;
  const description = metaStr(meta, "description") ?? chrome?.description ?? undefined;
  const image = metaStr(meta, "og_image_url") ?? chrome?.og_image_url ?? chrome?.logo_url ?? undefined;
  const canonical = metaStr(meta, "canonical") ?? (base ? `${base}${blogPath(locale, primary, slug)}` : undefined);
  const noIndex = meta.noindex === true || String(meta.robots ?? "").toLowerCase().includes("noindex");
  const index = (chrome?.robots_index ?? true) && !noIndex;

  return {
    ...(base ? { metadataBase: new URL(base) } : null),
    title,
    description,
    ...(canonical ? { alternates: { canonical } } : null),
    robots: index ? { index: true, follow: true } : { index: false, follow: false },
    openGraph: {
      title,
      description,
      type: "article",
      locale: publicOgLocale(locale),
      siteName: chrome?.title ?? undefined,
      ...(canonical ? { url: canonical } : null),
      ...(page.published_at ? { publishedTime: page.published_at } : null),
      ...((page.updated_at ?? page.published_at)
        ? { modifiedTime: (page.updated_at ?? page.published_at) as string }
        : null),
      ...(image ? { images: [{ url: image }] } : null),
    },
    ...(image ? { twitter: { card: "summary_large_image", title, images: [image] } } : null),
    ...(chrome?.favicon_url ? { icons: { icon: chrome.favicon_url } } : null),
  };
}

const JSON_LD_ESCAPES: Record<string, string> = { "<": "\\u003c", ">": "\\u003e", "&": "\\u0026" };

function safeJsonLd(value: unknown): string {
  return JSON.stringify(value).replace(/[<>&]/g, (c) => JSON_LD_ESCAPES[c]);
}

function buildArticleJsonLd(
  chrome: SiteChrome | null,
  page: SitePage,
  slug: string,
  base: string | null,
  locale: PublicLocale,
  primary: PublicLocale,
) {
  const inLanguage = locale === "en" ? "en-US" : "fa-IR";
  const meta = (page.meta ?? {}) as Record<string, unknown>;
  const authorName = metaStr(meta, "author_name") ?? chrome?.title ?? undefined;
  const image = metaStr(meta, "og_image_url") ?? chrome?.og_image_url ?? chrome?.logo_url ?? undefined;
  const pageUrl = base ? `${base}${blogPath(locale, primary, slug)}` : undefined;

  return {
    "@context": "https://schema.org",
    "@type": "Article",
    headline: page.title,
    ...(metaStr(meta, "description") ? { description: metaStr(meta, "description") } : null),
    inLanguage,
    ...(pageUrl ? { url: pageUrl, mainEntityOfPage: pageUrl } : null),
    ...(page.published_at ? { datePublished: page.published_at } : null),
    ...((page.updated_at ?? page.published_at) ? { dateModified: page.updated_at ?? page.published_at } : null),
    ...(image ? { image } : null),
    ...(authorName ? { author: { "@type": "Person", name: authorName } } : null),
    publisher: {
      "@type": "Organization",
      name: chrome?.title ?? page.title,
      ...(chrome?.logo_url ? { logo: chrome.logo_url } : null),
    },
  };
}

export default async function BlogPostRoute({ params }: Props) {
  const { locale: raw, slug } = await params;
  const locale = await requireLocale(raw);

  const [page, chrome] = await Promise.all([
    fetchBlogPost(slug, locale),
    fetchSiteChrome().catch(() => null),
  ]);
  if (!page) notFound();

  const meta = (page.meta ?? {}) as Record<string, unknown>;
  const tags = Array.isArray(meta.tags)
    ? meta.tags.filter((t): t is string => typeof t === "string" && t.trim() !== "")
    : [];
  const author = metaStr(meta, "author_name") ?? null;
  const category = metaStr(meta, "category") ?? null;
  const imageUrl = metaStr(meta, "og_image_url") ?? chrome?.og_image_url ?? null;

  const Blog = resolveBlogTheme(chrome?.theme?.slug);
  const { locales, primary } = effectiveLocale(chrome, locale);
  const switcher = locales.length > 1 ? { locales, primary } : undefined;
  const ld = buildArticleJsonLd(chrome, page, slug, siteBaseUrl(chrome), locale, primary);

  return (
    <Blog
      kind="post"
      locale={locale}
      chrome={chrome}
      schemas={chrome?.blocks}
      switcher={switcher}
      page={page}
      author={author}
      category={category}
      tags={tags}
      imageUrl={imageUrl}
      jsonLd={<script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd(ld) }} />}
    />
  );
}
