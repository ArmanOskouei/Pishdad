import type { ReactNode } from "react";
import { BlockRenderer } from "@/components/site/BlockRenderer";
import { SiteFooter, SiteHeader, themeVars } from "@/components/site/Chrome";
import { SiteBreadcrumbs } from "@/components/site/SiteBreadcrumbs";
import { siteModeBootstrapJs, type SiteMode } from "@/lib/site-mode";
import type { SidebarData } from "@/lib/domain";
import { siteLazyLoadEnabled } from "@/lib/site";
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
  content,
  locale,
  switcher,
  isHome,
}: ThemeProps & { variant: string; layout: ThemeLayoutConfig }) {
  const lang = locale ?? "fa";
  // زبانِ پایهٔ سایت: در حالتِ دو‌زبانه از `switcher` می‌آید و در تک‌زبانه
  // همان زبانِ جاری است (تنها زبان). برای ساختِ لینکِ نسبیِ مسیر راهنما لازم است.
  const primary = switcher?.primary ?? lang;
  // WF-L3 — پیش‌فرضِ سرور از تنظیمِ سایت/قالب؛ `system` سمتِ سرور تیره در
  // نظر گرفته می‌شود و اسکریپت/کلاینت در صورت نیاز ترجیحِ مرورگر را اعمال می‌کند.
  const defaultMode: SiteMode = chrome?.mode === "light" ? "light" : "dark";
  const sidebars = page.sidebars;
  const right = visibleSidebar(sidebars?.right);
  const left = visibleSidebar(sidebars?.left);
  const cols = right && left ? "cols-both" : right ? "cols-right-only" : left ? "cols-left-only" : "";
  const imageLoading: "lazy" | "eager" = siteLazyLoadEnabled(chrome) ? "lazy" : "eager";

  const sidebarNodes: ReactNode = (
    <>
      {right ? (
        <aside className="site-sidebar" aria-label="ستون راست">
          <BlockRenderer blocks={right.blocks} schemas={schemas} imageLoading={imageLoading} />
        </aside>
      ) : null}
      {left ? (
        <aside className="site-sidebar" aria-label="ستون چپ">
          <BlockRenderer blocks={left.blocks} schemas={schemas} imageLoading={imageLoading} />
        </aside>
      ) : null}
    </>
  );

  // WF-M18 — صفحهٔ سیستمی (۴۰۴): محتوای آماده جای بلوک‌ها را می‌گیرد.
  // WF-L3 — مسیر راهنما فقط برای صفحه‌های عمیقِ منتشرشده (نه خانه، نه ۴۰۴).
  const crumbs = !isHome ? (
    <SiteBreadcrumbs slug={page.slug} locale={lang} primary={primary} lastLabel={page.title} />
  ) : null;
  const main: ReactNode = (
    <main style={layout.mainMaxInlineSize ? { maxInlineSize: layout.mainMaxInlineSize } : undefined}>
      {crumbs}
      {content ?? (
        <>
          <article>
            <BlockRenderer blocks={page.blocks ?? []} schemas={schemas} imageLoading={imageLoading} />
          </article>
          {banner}
        </>
      )}
    </main>
  );

  return (
    <div
      className={`site theme-${variant}`}
      data-mode={defaultMode}
      style={themeVars(chrome)}
      dir={lang === "en" ? "ltr" : "rtl"}
      lang={lang}
      suppressHydrationWarning
    >
      <script dangerouslySetInnerHTML={{ __html: siteModeBootstrapJs() }} />
      {jsonLd}
      <SiteHeader chrome={chrome} locale={lang} switcher={switcher} defaultMode={defaultMode} />
      <div className={`site-body ${cols} theme-body-${variant} theme-place-${layout.sidebarPlacement}`.trim()}>
        {layout.sidebarPlacement === "columns" ? sidebarNodes : null}
        {main}
        {layout.sidebarPlacement === "stacked" ? sidebarNodes : null}
      </div>
      <SiteFooter chrome={chrome} locale={lang} />
    </div>
  );
}
