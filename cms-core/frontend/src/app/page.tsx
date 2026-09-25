import type { Metadata } from "next";
import Link from "next/link";
import { fetchHomepage, fetchSiteChrome, fetchSiteStatus, siteBaseUrl } from "@/lib/site";
import { BlockRenderer } from "@/components/site/BlockRenderer";
import { SiteFooter, SiteHeader, themeVars } from "@/components/site/Chrome";
import type { SidebarData, SitePage } from "@/lib/domain";

/**
 * کار دوم — روت `/`: صفحه خانه تنظیم‌شده (homepage_page_id ← home ← جدیدترین).
 * اگر چیزی منتشر نشده: لندینگ خنثی «سایت در حال آماده‌سازی» با لینک لاگین (نه 404 خشک).
 * هدر/فوتر واقعی از `/v1/site/chrome`؛ استایل فقط با توکن‌های globals تم فعال.
 */

export const revalidate = 300;

export async function generateMetadata(): Promise<Metadata> {
  const [page, chrome] = await Promise.all([
    fetchHomepage().catch(() => null),
    fetchSiteChrome().catch(() => null),
  ]);
  const base = siteBaseUrl(chrome);
  const icons = chrome?.favicon_url ? { icon: chrome.favicon_url } : null;
  if (!page) {
    return {
      ...(base ? { metadataBase: new URL(base) } : null),
      ...(icons ? { icons } : null),
      title: "سایت در حال آماده‌سازی",
      robots: { index: false, follow: false },
    };
  }
  const meta = (page.meta ?? {}) as Record<string, unknown>;
  const title = (typeof meta.title === "string" && meta.title) || page.title;
  return {
    ...(base ? { metadataBase: new URL(base) } : null),
    ...(icons ? { icons } : null),
    title,
    description: (typeof meta.description === "string" && meta.description) || chrome?.description || undefined,
    openGraph: {
      title,
      siteName: chrome?.title ?? undefined,
      locale: "fa_IR",
      type: "website",
      ...(base ? { url: base } : null),
    },
  };
}

export default async function Home() {
  const siteId = process.env.NEXT_PUBLIC_SITE_ID ?? "";
  if (siteId) {
    const st = await fetchSiteStatus(siteId).catch(() => null);
    if (st?.status === "suspended") {
      return <SuspendedSite message={st.message} />;
    }
  }

  const [page, chrome] = await Promise.all([
    fetchHomepage().catch(() => null),
    fetchSiteChrome().catch(() => null),
  ]);

  if (!page) {
    return (
      <div className="site" style={themeVars(chrome)}>
        <SiteHeader chrome={chrome} />
        <main style={{ minBlockSize: "60vh", display: "grid", placeItems: "center", padding: 24 }}>
          <div className="card card-pad" style={{ maxInlineSize: 520, inlineSize: "100%", textAlign: "center" }}>
            <div style={{ fontSize: 44 }} aria-hidden>🏗</div>
            <h1 style={{ fontSize: 19, marginBlock: "8px 4px" }}>سایت در حال آماده‌سازی است</h1>
            <p style={{ fontSize: 14, color: "var(--text-muted)" }}>
              هنوز صفحه‌ای منتشر نشده است. به‌زودی محتوای سایت اینجا نمایش داده می‌شود.
            </p>
            <Link className="btn btn-primary" href="/login" style={{ marginBlockStart: 12 }}>ورود به پنل مدیریت</Link>
          </div>
        </main>
        <SiteFooter chrome={chrome} />
      </div>
    );
  }

  const sidebars = (page as SitePage).sidebars;
  const right = visibleSidebar(sidebars?.right);
  const left = visibleSidebar(sidebars?.left);
  const cols =
    right && left ? "cols-both" : right ? "cols-right-only" : left ? "cols-left-only" : "";

  return (
    <div className="site" style={themeVars(chrome)}>
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
