import type { ReactNode } from "react";
import { BlockRenderer } from "@/components/site/BlockRenderer";
import { SiteFooter, SiteHeader, themeVars } from "@/components/site/Chrome";
import type { SidebarData } from "@/lib/domain";
import type { ThemeLayoutConfig, ThemeProps } from "./types";

/**
 * ECO1 — پوستهٔ مشترکِ قالب‌ها.
 *
 * هر قالب دادهٔ یکسان می‌گیرد ولی `layout` خودش را می‌دهد: همین‌جا است که
 * «مینیمال» ستون‌ها را زیر محتوا می‌برد و «تحریری» کنار محتوا با عرض محدود
 * می‌گذارد. رندر بلوک‌ها یکی است تا یک اصلاحِ امنیتیِ `BlockRenderer` سه بار
 * تکرار نشود.
 */
function visibleSidebar(s: SidebarData | null | undefined): SidebarData | null {
  if (!s || !s.enabled) return null;
  if (!Array.isArray(s.blocks) || s.blocks.length === 0) return null;
  return s;
}

export function ThemeShell({
  variant,
  layout,
  page,
  chrome,
  schemas,
  banner,
  jsonLd,
  locale,
  switcher,
}: ThemeProps & { variant: string; layout: ThemeLayoutConfig }) {
  const lang = locale ?? "fa";
  const sidebars = page.sidebars;
  const right = visibleSidebar(sidebars?.right);
  const left = visibleSidebar(sidebars?.left);
  const cols = right && left ? "cols-both" : right ? "cols-right-only" : left ? "cols-left-only" : "";

  const sidebarNodes: ReactNode = (
    <>
      {right ? (
        <aside className="site-sidebar" aria-label="ستون راست">
          <BlockRenderer blocks={right.blocks} schemas={schemas} />
        </aside>
      ) : null}
      {left ? (
        <aside className="site-sidebar" aria-label="ستون چپ">
          <BlockRenderer blocks={left.blocks} schemas={schemas} />
        </aside>
      ) : null}
    </>
  );

  const main: ReactNode = (
    <main style={layout.mainMaxInlineSize ? { maxInlineSize: layout.mainMaxInlineSize } : undefined}>
      <article>
        <BlockRenderer blocks={page.blocks ?? []} schemas={schemas} />
      </article>
      {banner}
    </main>
  );

  return (
    <div className={`site theme-${variant}`} style={themeVars(chrome)} dir={lang === "en" ? "ltr" : "rtl"} lang={lang}>
      {jsonLd}
      <SiteHeader chrome={chrome} locale={lang} switcher={switcher} />
      <div className={`site-body ${cols} theme-body-${variant} theme-place-${layout.sidebarPlacement}`.trim()}>
        {layout.sidebarPlacement === "columns" ? sidebarNodes : null}
        {main}
        {layout.sidebarPlacement === "stacked" ? sidebarNodes : null}
      </div>
      <SiteFooter chrome={chrome} locale={lang} />
    </div>
  );
}
