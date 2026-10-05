import type { Metadata } from "next";
import { headers } from "next/headers";
import { notFound } from "next/navigation";
import { fetchSiteChrome, fetchSitePage, siteBaseUrl, type SiteChrome } from "@/lib/site";
import { resolveTheme } from "@/themes/registry";
import type { SitePage } from "@/lib/domain";
import { isPublicLocale, publicHreflang, type PublicLocale } from "@/lib/i18n/public";
import { parseLocalesHeader, SITE_LOCALES_HEADER } from "@/lib/public-routing";
import { effectiveLocale } from "@/components/site/PublicPage";

/**
 * ECO2 — صفحهٔ عمیقِ زبانِ دوم (`/en/about`, `/en/blog/post`).
 *
 * کاملاً بازنویسی نشده: همان منطقِ `[...path]` را دارد ولی زبان را از
 * `params.locale` می‌گیرد (نه از هدر)، چون این روت در درختِ `[locale]` است و
 * Next خودش مقدار را می‌دهد. متادیتا + hreflang + JSON-LD زبان‌دار.
 */

export const revalidate = 300;

type Props = { params: Promise<{ locale: string; path: string[] }> };

function metaStr(meta: Record<string, unknown> | null | undefined, key: string): string | undefined {
  const v = meta?.[key];
  return typeof v === "string" && v ? v : undefined;
}

function metaBool(meta: Record<string, unknown> | null | undefined, key: string): boolean {
  const v = meta?.[key];
  if (v === true) return true;
  if (typeof v === "string") return v.toLowerCase() === "true" || v.toLowerCase().includes("noindex");
  return false;
}

function trunc(s: string | undefined, n = 160): string | undefined {
  if (!s) return undefined;
  const t = s.trim().replace(/\s+/g, " ");
  return t.length > n ? t.slice(0, n - 1).trimEnd() + "…" : t;
}

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
  const { locale: raw, path } = await params;
  const locale: PublicLocale = isPublicLocale(raw) ? raw : "fa";
  const slug = path.join("/");
  const [page, chrome] = await Promise.all([
    fetchSitePage(slug, locale).catch(() => null),
    fetchSiteChrome().catch(() => null),
  ]);
  if (!page) return { title: "Not found" };
  const meta = (page.meta ?? {}) as Record<string, unknown>;
  const base = siteBaseUrl(chrome);
  const title = metaStr(meta, "title") ?? page.title;
  const description = trunc(metaStr(meta, "description") ?? chrome?.description);
  const pageNoIndex = metaBool(meta, "noindex") || (metaStr(meta, "robots") ?? "").toLowerCase().includes("noindex");
  const index = (chrome?.robots_index ?? true) && !pageNoIndex;
  const ogImage = metaStr(meta, "og_image") ?? chrome?.og_image_url ?? chrome?.logo_url ?? undefined;
  const { locales, primary } = effectiveLocale(chrome, locale);
  const publicPath = locale === primary ? `/${slug}` : `/${locale}/${slug}`;
  const canonical = base ? `${base}${publicPath}` : undefined;
  const url = canonical;
  const languages: Record<string, string> = {};
  for (const loc of locales) {
    languages[publicHreflang(loc)] = loc === primary ? `${base}/${slug}` : `${base}/${loc}/${slug}`;
  }

  return {
    ...(base ? { metadataBase: new URL(base) } : null),
    title,
    description,
    ...(canonical ? { alternates: { canonical, languages } } : null),
    robots: index ? { index: true, follow: true } : { index: false, follow: false },
    openGraph: {
      title: metaStr(meta, "og_title") ?? title,
      description: description ?? metaStr(meta, "og_description"),
      ...(url ? { url } : null),
      siteName: chrome?.title ?? undefined,
      locale: locale === "en" ? "en_US" : "fa_IR",
      type: "article",
      ...(page.published_at ? { publishedTime: page.published_at } : null),
      ...((page.updated_at ?? page.published_at) ? { modifiedTime: (page.updated_at ?? page.published_at) as string } : null),
      ...(ogImage ? { images: [{ url: ogImage }] } : null),
    },
    twitter: {
      card: ogImage ? "summary_large_image" : "summary",
      title: metaStr(meta, "og_title") ?? title,
      ...(description ? { description } : null),
      ...(ogImage ? { images: [ogImage] } : null),
    },
    ...(chrome?.favicon_url ? { icons: { icon: chrome.favicon_url } } : null),
  };
}

const JSON_LD_ESCAPES: Record<string, string> = { "<": "\\u003c", ">": "\\u003e", "&": "\\u0026" };

function safeJsonLd(value: unknown): string {
  return JSON.stringify(value).replace(/[<>&]/g, (c) => JSON_LD_ESCAPES[c]);
}

function textFromBlocks(blocks: unknown): string {
  const out: string[] = [];
  const walk = (v: unknown): void => {
    if (typeof v === "string") {
      const t = v.replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim();
      if (t && t.length > 1) out.push(t);
    } else if (Array.isArray(v)) {
      for (const x of v) walk(x);
    } else if (v && typeof v === "object") {
      for (const x of Object.values(v as Record<string, unknown>)) walk(x);
    }
  };
  walk(blocks);
  return out.join(" ").slice(0, 500);
}

function buildJsonLd(chrome: SiteChrome | null, page: SitePage, slug: string, base: string | null, locale: PublicLocale) {
  const inLanguage = locale === "en" ? "en-US" : "fa-IR";
  const meta = ((page.meta ?? {}) as Record<string, unknown>);
  const desc =
    (typeof meta.description === "string" && meta.description) ||
    chrome?.description ||
    textFromBlocks(page.blocks) ||
    undefined;
  const pageUrl = base ? `${base}/${slug}` : undefined;
  const image = (typeof meta.og_image === "string" && meta.og_image) || chrome?.og_image_url || chrome?.logo_url || undefined;
  const graph: Record<string, unknown>[] = [
    {
      "@type": "WebSite",
      name: chrome?.title ?? page.title,
      ...(base ? { url: base } : null),
      inLanguage,
      ...(chrome?.ai_summary || chrome?.description ? { description: chrome.ai_summary || chrome.description } : null),
    },
    {
      "@type": "Article",
      headline: page.title,
      ...(desc ? { description: desc, abstract: desc } : null),
      inLanguage,
      ...(pageUrl ? { url: pageUrl, mainEntityOfPage: pageUrl } : null),
      ...(page.published_at ? { datePublished: page.published_at } : null),
      ...((page.updated_at ?? page.published_at) ? { dateModified: page.updated_at ?? page.published_at } : null),
      ...(image ? { image } : null),
      author: { "@type": "Organization", name: chrome?.title ?? page.title },
      publisher: {
        "@type": "Organization",
        name: chrome?.title ?? page.title,
        ...(chrome?.logo_url ? { logo: chrome.logo_url } : null),
      },
    },
  ];
  if (chrome?.phone || chrome?.email || chrome?.address) {
    graph.push({
      "@type": "Organization",
      name: chrome?.title ?? page.title,
      ...(chrome.phone ? { telephone: chrome.phone } : null),
      ...(chrome.email ? { email: chrome.email } : null),
      ...(chrome.address ? { address: { "@type": "PostalAddress", streetAddress: chrome.address } } : null),
      ...(base ? { url: base } : null),
    });
  }
  return { "@context": "https://schema.org", "@graph": graph };
}

export default async function LocaleSitePageRoute({ params }: Props) {
  const { locale: raw, path } = await params;
  const locale: PublicLocale = isPublicLocale(raw) ? raw : "fa";
  const slug = path.join("/");

  const [page, chrome] = await Promise.all([
    fetchSitePage(slug, locale).catch(() => null),
    fetchSiteChrome().catch(() => null),
  ]);
  if (!(await localeAvailable(locale))) notFound();
  if (!page) notFound();

  const base = siteBaseUrl(chrome);
  const ld = buildJsonLd(chrome, page as SitePage, slug, base, locale);
  const theme = resolveTheme(chrome?.theme?.slug);
  const { locales, primary } = effectiveLocale(chrome, locale);
  const switcher = locales.length > 1 ? { locales, primary } : undefined;

  return (
    <theme.Component
      page={page as SitePage}
      chrome={chrome}
      schemas={chrome?.blocks}
      locale={locale}
      switcher={switcher}
      jsonLd={ld ? <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd(ld) }} /> : undefined}
    />
  );
}
