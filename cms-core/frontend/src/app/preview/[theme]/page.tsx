import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { fetchHomepage, fetchSiteChrome, fetchSiteTheme } from "@/lib/site";
import { resolveTheme } from "@/themes/registry";
import type { SitePage } from "@/lib/domain";
import { PreviewControls } from "./PreviewControls";

/**
 * F4.1.F — پیش‌نمایشِ یک قالبِ مشخص روی **سایتِ اصلی**، در صفحه‌ای که آدرسش
 * نامِ همان قالب است: `/preview/<slug>` (مثلاً `/preview/editorial`).
 *
 * چرا این مسیر و نه modal: قالب تعیین‌کنندهٔ **ساختار** است (هدر، عرض، کارت‌ها).
 * پیش‌نمایشِ قبلی همیشه قالبِ فعال را با توکن‌های پنل نشان می‌داد، پس دربارهٔ
 * قالبِ غیرفعال هیچ نمی‌گفت. اینجا همان کامپوننتِ قالب (`resolveTheme`) با
 * محتوای واقعیِ صفحهٔ خانه رندر می‌شود، پس آنچه می‌بینی همان است که پس از
 * فعال‌سازی می‌آید.
 */
export const dynamic = "force-dynamic";
export const revalidate = 0;

export const metadata: Metadata = {
  title: "پیش‌نمایش قالب",
  robots: { index: false, follow: false, nocache: true },
};

type Props = { params: Promise<{ theme: string }> };

export default async function PreviewThemePage({ params }: Props) {
  const { theme: slug } = await params;

  const [tokens, page, chrome] = await Promise.all([
    fetchSiteTheme(slug).catch(() => null),
    fetchHomepage().catch(() => null),
    fetchSiteChrome().catch(() => null),
  ]);

  if (!tokens) notFound();

  // توکن‌های همین قالب را روی chrome سوار می‌کنیم تا `themeVars` آن‌ها را بدهد
  // (به‌جای توکن‌های قالبِ فعال). رنگِ قالب ⇒ پیش‌نمایشِ درست.
  const themed = {
    ...(chrome ?? {}),
    theme: { name: tokens.name, slug: tokens.slug, version: tokens.version ?? "1.0.0", globals: tokens.globals },
  } as typeof chrome;

  const def = resolveTheme(tokens.slug);
  const content: SitePage =
    page ??
    ({ title: tokens.name, slug: "home", blocks: [], sidebars: undefined } as unknown as SitePage);

  return (
    <div>
      <div className="preview-bar">
        <span>
          پیش‌نمایش قالب «<b>{tokens.name}</b>» <small dir="ltr">({tokens.slug})</small>
        </span>
        <div style={{ flex: 1 }} />
        <Link href="/admin/themes">ویرایش در پنل</Link>
        <Link href="/">سایتِ واقعی</Link>
      </div>

      {page ? null : (
        <div style={{ padding: "10px 16px", background: "var(--surface-2)", color: "var(--text-muted)", fontSize: 13 }}>
          صفحهٔ خانه‌ای منتشر نشده؛ ساختار و رنگِ قالب نمایش داده می‌شود.
        </div>
      )}

      <PreviewControls
        colorways={tokens.colorways ?? []}
        layoutTokens={tokens.layout_tokens ?? {}}
        defaultColorway={tokens.default_colorway}
      />

      <def.Component page={content} chrome={themed} schemas={chrome?.blocks} locale="fa" />
    </div>
  );
}
