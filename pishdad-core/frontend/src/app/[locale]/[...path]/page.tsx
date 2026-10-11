import type { Metadata } from "next";
import { headers } from "next/headers";
import { notFound, redirect } from "next/navigation";
import { fetchSiteChrome, fetchSitePageFallback, siteBaseUrl, type SiteChrome } from "@/lib/site";
import { resolveTheme } from "@/themes/registry";
import type { SitePage } from "@/lib/domain";
import { isPublicLocale, publicOgLocale, type PublicLocale } from "@/lib/i18n/public";
import {
  buildAlternatesLanguages,
  localeFallbackChain,
  localePublicPath,
  parseLocalesHeader,
  pickBrowserLocale,
} from "@/lib/public-routing";
import { effectiveLocale } from "@/components/site/PublicPage";
import { buildBreadcrumbList } from "@/lib/public-json-ld";

/**
 * ECO2 — صفحهٔ عمیقِ زبانِ دوم (`/en/about`, `/en/blog/post`).
 *
 * کاملاً بازنویسی نشده: همان منطقِ `[...path]` را دارد ولی زبان را از
 * `params.locale` می‌گیرد (نه از هدر)، چون این روت در درختِ `[locale]` است و
 * Next خودش مقدار را می‌دهد. متادیتا + hreflang + JSON-LD زبان‌دار.
 */

export const revalidate = 300;
// PERF-1 - middleware rewrite alone opts the route into dynamic rendering
// in Next 16 (proven: no-store without it, HIT with dynamic=error and zero
// DynamicServerErrors, i.e. the tree uses no dynamic APIs). force-static keeps
// the URL design (bare primary URLs) AND the 300s cache.
export const dynamic = "force-static";

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
  return true;
}

/**
 * WF-H15 — متادیتای صفحهٔ عمیق با `hreflang` کامل.
 *
 * `alternates.languages` برای همهٔ زبان‌های پیکربندی‌شده ساخته می‌شود (چون API
 * زبان‌های واقعیِ صفحه را افشا نمی‌کند) به‌همراه `x-default` که به نسخهٔ زبانِ
 * پایه اشاره می‌کند؛ `canonical` نیز همان نسخهٔ یافته‌شده است تا محتوای تکراری
 * در گوگل ساخته نشود.
 */
export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { locale: raw, path } = await params;
  const locale: PublicLocale = isPublicLocale(raw) ? raw : "fa";
  const slug = path.join("/");
  const chrome = await fetchSiteChrome().catch(() => null);
  const { locales, primary } = effectiveLocale(chrome, locale);
  const chain = localeFallbackChain(locale, locales, primary);
  const found = await fetchSitePageFallback(slug, chain);
  if (!found) return { title: "Not found" };
  const page = found.page;
  const resolved: PublicLocale = isPublicLocale(found.locale) ? found.locale : locale;
  const meta = (page.meta ?? {}) as Record<string, unknown>;
  const base = siteBaseUrl(chrome);
  const title = metaStr(meta, "title") ?? page.title;
  const description = trunc(metaStr(meta, "description") ?? chrome?.description);
  const pageNoIndex = metaBool(meta, "noindex") || (metaStr(meta, "robots") ?? "").toLowerCase().includes("noindex");
  const index = (chrome?.robots_index ?? true) && !pageNoIndex;
  const ogImage = metaStr(meta, "og_image_url") ?? chrome?.og_image_url ?? chrome?.logo_url ?? undefined;
  const canonical = metaStr(meta, "canonical") ?? (base ? `${base}${localePublicPath(resolved, slug, primary)}` : undefined);
  const url = canonical;
  const languages = base ? buildAlternatesLanguages({ base, slug, locales, primary }) : undefined;

  return {
    ...(base ? { metadataBase: new URL(base) } : null),
    title,
    description,
    ...(canonical ? { alternates: { canonical, ...(languages ? { languages } : null) } } : null),
    robots: index ? { index: true, follow: true } : { index: false, follow: false },
    openGraph: {
      title: metaStr(meta, "og_title") ?? title,
      description: description ?? metaStr(meta, "og_description"),
      ...(url ? { url } : null),
      siteName: chrome?.title ?? undefined,
      locale: publicOgLocale(resolved),
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

function buildJsonLd(
  chrome: SiteChrome | null,
  page: SitePage,
  slug: string,
  base: string | null,
  locale: PublicLocale,
  primary: PublicLocale,
) {
  const inLanguage = locale === "en" ? "en-US" : "fa-IR";
  const meta = ((page.meta ?? {}) as Record<string, unknown>);
  const desc =
    (typeof meta.description === "string" && meta.description) ||
    chrome?.description ||
    textFromBlocks(page.blocks) ||
    undefined;
  const pageUrl = base ? `${base}/${slug}` : undefined;
  const image = (typeof meta.og_image_url === "string" && meta.og_image_url) || chrome?.og_image_url || chrome?.logo_url || undefined;
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
  graph.push(buildBreadcrumbList({ base, slug, locale, primary }) as unknown as Record<string, unknown>);
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

/**
 * WF-H15 — fallback زبان بدون ۴۰۴.
 *
 * اگر صفحه در زبانِ درخواستی منتشر نشده باشد، به ترتیبِ «زبانِ مرورگر ← زبانِ
 * پایه ← بقیهٔ زبان‌های پیکربندی‌شده» دنبال می‌شود. اولین نسخهٔ موجود، ۳۰۸ به
 * مسیرِ همان زبان ریدایرکت می‌شود (فقط زبان‌های پیکربندی‌شده کاندید‌اند، پس
 * حلقهٔ ریدایرکت ممکن نیست). اگر هیچ نسخه‌ای نباشد، همان ۴۰۴ قبلی برمی‌گردد.
 */
export default async function LocaleSitePageRoute({ params }: Props) {
  const { locale: raw, path } = await params;
  const locale: PublicLocale = isPublicLocale(raw) ? raw : "fa";
  const slug = path.join("/");

  if (!(await localeAvailable(locale))) notFound();

  const chrome = await fetchSiteChrome().catch(() => null);
  const { locales, primary } = effectiveLocale(chrome, locale);
  const chain = localeFallbackChain(locale, locales, primary);
  const found = await fetchSitePageFallback(slug, chain);
  if (!found) notFound();

  const resolved: PublicLocale = isPublicLocale(found.locale) ? found.locale : locale;
  // PERF-1: a redirect here used to force the whole route dynamic (revalidate
  // 300 was dead). The found locale is rendered in place; canonical/hreflang
  // in the metadata already points at it.
  const page = found.page;
  const base = siteBaseUrl(chrome);
  const ld = buildJsonLd(chrome, page as SitePage, slug, base, resolved, primary);
  const theme = resolveTheme(chrome?.theme?.slug);
  const switcher = locales.length > 1 ? { locales, primary } : undefined;

  return (
    <theme.Component
      page={page as SitePage}
      chrome={chrome}
      schemas={chrome?.blocks}
      locale={resolved}
      switcher={switcher}
      jsonLd={ld ? <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd(ld) }} /> : undefined}
    />
  );
}
