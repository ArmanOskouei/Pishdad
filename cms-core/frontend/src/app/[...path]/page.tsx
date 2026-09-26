import { notFound } from "next/navigation";
import type { Metadata } from "next";
import { fetchSiteChrome, fetchSitePage, fetchSiteStatus, siteBaseUrl, type SiteChrome } from "@/lib/site";
import { BlockRenderer } from "@/components/site/BlockRenderer";
import { SiteFooter, SiteHeader, themeVars } from "@/components/site/Chrome";
import type { SidebarData, SitePage } from "@/lib/domain";

/**
 * F3 — سایت عمومی (روت [...path]).
 * - فقط صفحات published (تضمین بک‌اند) + ISR ۳۰۰ ثانیه + تگ `pages`.
 * - اگر site/status = suspended → رندر ۱.۱۶ به‌جای سایت (بدون لینک /admin).
 * - هدر/فوتر واقعی از `/v1/site/chrome` (عنوان/لوگو/شبکه‌ها/لی‌آوت همان نصب)؛
 *   وقتی خالی است fallback به پیش‌فرض‌ها. استایل فقط با توکن‌های globals تم فعال.
 * - ستون‌های کناری (ارجاع زنده پریست) با همان BlockRenderer؛ در موبایل زیر محتوا.
 */

export const revalidate = 300;

/** اندپوینت فهرست عمومی صفحات در بک‌اند نیست → on-demand ISR (dynamicParams). */
export async function generateStaticParams(): Promise<Array<{ path: string[] }>> {
  return [];
}

type Props = { params: Promise<{ path: string[] }> };

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

/** کوتاه‌سازی توضیحات برای SERP (گوگل ~۱۵۵–۱۶۰ نویسه). */
function trunc(s: string | undefined, n = 160): string | undefined {
  if (!s) return undefined;
  const t = s.trim().replace(/\s+/g, " ");
  return t.length > n ? t.slice(0, n - 1).trimEnd() + "…" : t;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const slug = (await params).path.join("/");
  const [page, chrome] = await Promise.all([
    fetchSitePage(slug).catch(() => null),
    fetchSiteChrome().catch(() => null),
  ]);
  if (!page) return { title: "یافت نشد" };
  const meta = (page.meta ?? {}) as Record<string, unknown>;
  const base = siteBaseUrl(chrome);
  const title = metaStr(meta, "title") ?? page.title;
  const description = trunc(metaStr(meta, "description") ?? chrome?.description);
  const pageNoIndex = metaBool(meta, "noindex") || (metaStr(meta, "robots") ?? "").toLowerCase().includes("noindex");
  const index = (chrome?.robots_index ?? true) && !pageNoIndex;
  const canonical = base ? `${base}/${slug}` : undefined;
  const ogImage = metaStr(meta, "og_image") ?? chrome?.og_image_url ?? chrome?.logo_url ?? undefined;
  const url = base ? `${base}/${slug}` : undefined;

  return {
    ...(base ? { metadataBase: new URL(base) } : null),
    title,
    description,
    ...(canonical ? { alternates: { canonical } } : null),
    robots: index ? { index: true, follow: true } : { index: false, follow: false },
    openGraph: {
      title: metaStr(meta, "og_title") ?? title,
      description: description ?? metaStr(meta, "og_description"),
      ...(url ? { url } : null),
      siteName: chrome?.title ?? undefined,
      locale: "fa_IR",
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

export default async function SitePageRoute({ params }: Props) {
  const slug = (await params).path.join("/");

  // تعلیق خودکار: فقط وقتی site_id این نصب مشخص است (env) بررسی می‌شود.
  const siteId = process.env.NEXT_PUBLIC_SITE_ID ?? "";
  if (siteId) {
    const st = await fetchSiteStatus(siteId).catch(() => null);
    if (st?.status === "suspended") {
      return <SuspendedSite message={st.message} />;
    }
  }

  const [page, chrome] = await Promise.all([
    fetchSitePage(slug).catch(() => null),
    fetchSiteChrome().catch(() => null),
  ]);
  if (!page) notFound();

  const sidebars = (page as SitePage).sidebars;
  const right = visibleSidebar(sidebars?.right);
  const left = visibleSidebar(sidebars?.left);
  const cols =
    right && left ? "cols-both" : right ? "cols-right-only" : left ? "cols-left-only" : "";

  const base = siteBaseUrl(chrome);
  const ld = buildJsonLd(chrome, page as SitePage, slug, base);

  return (
    <div className="site" style={themeVars(chrome)}>
      {ld ? <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd(ld) }} /> : null}
      <SiteHeader chrome={chrome} />
      <div className={`site-body ${cols}`.trim()}>
        {right ? (
          <aside className="site-sidebar" aria-label="ستون راست">
            <BlockRenderer blocks={right.blocks} />
          </aside>
        ) : null}
        <main>
          <article>
            <BlockRenderer blocks={(page as SitePage).blocks ?? []} />
          </article>
        </main>
        {left ? (
          <aside className="site-sidebar" aria-label="ستون چپ">
            <BlockRenderer blocks={left.blocks} />
          </aside>
        ) : null}
      </div>
      <SiteFooter chrome={chrome} />
    </div>
  );
}

/** ستون فعال با بلوک واقعی (غیرفعال یا خالی → null تا گرید تک‌ستونه بماند). */
function visibleSidebar(s: SidebarData | null | undefined): SidebarData | null {
  if (!s || !s.enabled) return null;
  if (!Array.isArray(s.blocks) || s.blocks.length === 0) return null;
  return s;
}

/**
 * F0.1 — escape امن برای JSON-LD.
 *
 * مشکل: `JSON.stringify` اسلش `/` را escape **نمی‌کند** (قاعدهٔ JSONP است، نه
 * JSON). پس `</script>` داخل یک رشته، عنصر `<script>` را می‌بندد و هر HTML
 * بعد از آن اجرا می‌شود. ورودی‌ها `page.title` و `chrome.title` هستند که هر دو
 * روی صفحهٔ **عمومی** و برای **بازدیدکنندهٔ ناشناس** رندر می‌شوند.
 *
 * `<` ضروری است و کل مانع breakout است. `>` و `&` دفاعی‌اند.
 *
 * عمداً U+2028/U+2029 اینجا escape نمی‌شوند: هر دو در جاوااسکریپت «پایان خط»
 * هستند و نوشتن‌شان در سورس، regex را می‌شکند؛ و تنها ریسک‌شان تفاوت تفسیر
 * بین مفسر JSON و مفسر HTML است که با escape کردن `<` از بین می‌رود. لایهٔ
 * دوم (`no_markup` در بک‌اند) هم اجازه نمی‌دهد این رشته‌ها اصلاً ذخیره شوند.
 */
const JSON_LD_ESCAPES: Record<string, string> = {
  "<": "\\u003c",
  ">": "\\u003e",
  "&": "\\u0026",
};

function safeJsonLd(value: unknown): string {
  return JSON.stringify(value).replace(/[<>&]/g, (c) => JSON_LD_ESCAPES[c]);
}

/** متن خام بلوک‌ها برای خلاصه AI/GEO (حداکثر ۵۰۰ نویسه، بدون تگ). */
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

/** JSON-LD: WebSite + WebPage/Article (تاریخ‌ها) + Organization (تماس ساخت‌یافته). */
function buildJsonLd(chrome: SiteChrome | null, page: SitePage, slug: string, base: string | null) {
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
      inLanguage: "fa-IR",
      ...(chrome?.ai_summary || chrome?.description ? { description: chrome.ai_summary || chrome.description } : null),
    },
    {
      "@type": "Article",
      headline: page.title,
      ...(desc ? { description: desc, abstract: desc } : null),
      inLanguage: "fa-IR",
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

/** صفحه تعلیق عمومی ۱.۱۶ — برند + پیام + تماس، بدون هیچ لینک پنل. */
function SuspendedSite({ message }: { message: string }) {
  return (
    <main style={{ minBlockSize: "100vh", display: "grid", placeItems: "center", padding: 24, background: "var(--bg)", color: "var(--text)" }}>
      <div className="card card-pad" style={{ maxInlineSize: 520, inlineSize: "100%", textAlign: "center" }}>
        <div style={{ fontSize: 44 }} aria-hidden>⛔</div>
        <h1 style={{ fontSize: 19, marginBlock: "8px 4px" }}>سایت موقتاً در دسترس نیست</h1>
        <p style={{ fontSize: 14, color: "var(--text-muted)" }}>{message}</p>
        <p style={{ fontSize: 12, color: "var(--text-muted)" }}>تماس با پشتیبانی: support@example.ir</p>
      </div>
    </main>
  );
}
