import { SOCIAL_FA, type ChromeLinkItem, type SocialItem, type WidgetValue } from "@/lib/domain";
// F0.2: ویجت CTA و آیتم‌های فوتر/ناوبری `href` می‌دهند و تا امروز هیچ
// sanitize‌ای نداشتند ⇒ `javascript:` در هدر *همهٔ صفحات* سایت زنده بود.
// منبع حقیقت سمت سرور `App\Validation\SafeUrl` است؛ این لایه دفاع عمقی برای
// داده‌ای است که پیش از آن در DB نشسته.
import { safeHref } from "@/lib/sanitize";
import type { SiteChrome } from "@/lib/site";
import { SiteNav } from "./SiteNav";
import { SiteSearchBox } from "./SiteSearchBox";

/**
 * رندر واقعی مشترک هدر/فوتر سایت عمومی.
 * در صفحات عمومی (app/page + [...path]) و پیش‌نمایش زنده پنل
 * (admin/header-footer با تنظیمات draft) reuse می‌شود.
 */

/** توکن‌های globals تم فعال → CSS var روی wrapper (بدون هاردکد رنگ). */
export function themeVars(chrome: Pick<SiteChrome, "theme"> | null): Record<string, string> {
  const g = chrome?.theme?.globals;
  const out: Record<string, string> = {};
  if (g && typeof g === "object") {
    for (const [k, v] of Object.entries(g)) {
      if (typeof v === "string" && v && /^[a-z0-9-]+$/i.test(k)) out[`--theme-${k}`] = v;
    }
  }
  return out;
}

export function ChromeWidget({ w, chrome }: { w: WidgetValue; chrome: SiteChrome | null }) {
  const s = (w.settings ?? {}) as Record<string, unknown>;
  const S = (k: string) => (typeof s[k] === "string" ? (s[k] as string) : "");
  switch (w.type) {
    case "logo": {
      const src = typeof s.media_url === "string" && s.media_url ? (s.media_url as string) : (chrome?.logo_url ?? null);
      const showTitle = s.show_title !== false;
      const h = s.size === "sm" ? 28 : s.size === "lg" ? 52 : 40;
      return (
        <a href="/" className="site-brand" aria-label={chrome?.title ?? "خانه"}>
          {src ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={src} alt={chrome?.title ?? "لوگو"} style={{ blockSize: h, inlineSize: "auto" }} />
          ) : null}
          {showTitle ? <b>{chrome?.title ?? "وب‌سایت من"}</b> : null}
        </a>
      );
    }
    case "nav": {
      // آیتم page با title/url زنده از کروم می‌آید (تغییر نام/اسلاگ خودکار منعکس می‌شود).
      const links = Array.isArray(s.links)
        ? (s.links as ChromeLinkItem[])
        : [{ label: "خانه", href: "/" }];
      const style = typeof s.style === "string" ? s.style : "horizontal";
      return <SiteNav links={links} style={style} />;
    }
    case "search":
      return <SiteSearchBox placeholder={S("placeholder") || undefined} />;
    case "cta":
      return <a className="btn btn-primary btn-sm" href={safeHref(S("href")) ?? "/"}>{S("label") || "اقدام"}</a>;
    case "socials":
      return <Socials socials={chrome?.socials ?? []} />;
    case "about":
      return <p style={{ fontSize: 13.5, color: "var(--text-muted)", margin: 0 }}>{S("text") || chrome?.description || ""}</p>;
    case "links": {
      const links = Array.isArray(s.links) ? (s.links as ChromeLinkItem[]) : [];
      return (
        <div>
          {S("heading") ? <b style={{ fontSize: 14 }}>{S("heading")}</b> : null}
          <ul style={{ listStyle: "none", padding: 0, margin: "6px 0 0", display: "flex", flexDirection: "column", gap: 6 }}>
            {links.map((l, i) => (
              <FooterLinkItem key={i} link={l} />
            ))}
          </ul>
        </div>
      );
    }
    case "contact":
      return (
        <div style={{ fontSize: 13.5, color: "var(--text-muted)" }}>
          {chrome?.phone ? <div>تلفن: <span dir="ltr">{chrome.phone}</span></div> : null}
          {chrome?.email ? <div>ایمیل: <span dir="ltr">{chrome.email}</span></div> : null}
          {!chrome?.phone && !chrome?.email ? "تماس با پشتیبانی: support@example.ir" : null}
        </div>
      );
    case "newsletter":
      return <p style={{ fontSize: 13, color: "var(--text-muted)", margin: 0 }}>{S("text") || "عضویت در خبرنامه به‌زودی."}</p>;
    case "copyright":
      return <small style={{ color: "var(--text-muted)" }}>{S("text") || `© ${new Date().getFullYear()} ${chrome?.title ?? ""}`}</small>;
    default:
      return null;
  }
}

function FooterLinkItem({ link }: { link: ChromeLinkItem }) {
  const kids = Array.isArray(link.children) ? link.children : [];
  return (
    <li>
      <a href={safeHref(link.url ?? link.href) ?? "#"}>{link.label || link.title || "—"}</a>
      {kids.length > 0 ? (
        <ul style={{ listStyle: "none", paddingInlineStart: 14, margin: "4px 0 0", display: "flex", flexDirection: "column", gap: 4 }}>
          {kids.map((c, j) => (
            <FooterLinkItem key={j} link={c} />
          ))}
        </ul>
      ) : null}
    </li>
  );
}

function socialLabel(x: SocialItem): string {
  if (x.label) return x.label;
  return SOCIAL_FA[x.key] ?? x.key;
}

export function Socials({ socials }: { socials: SocialItem[] }) {
  const active = socials.filter((x) => x.active);
  if (active.length === 0) return null;
  return (
    <div className="site-socials">
      {active.map((x) => (
        <a key={x.key} href={safeHref(x.url) ?? "#"} target="_blank" rel="noreferrer" title={socialLabel(x)}>
          <span>{socialLabel(x)}</span>
        </a>
      ))}
    </div>
  );
}

export function SiteHeader({ chrome }: { chrome: SiteChrome | null }) {
  return (
    <header className="site-header">
      <div className="site-header-inner">
        {(chrome?.header.widgets ?? []).map((w, i) => <ChromeWidget key={i} w={w} chrome={chrome} />)}
      </div>
    </header>
  );
}

export function SiteFooter({ chrome }: { chrome: SiteChrome | null }) {
  // گرید واقعی ستون‌ها: هر ویجت links یک ستون (به ترتیب، راست‌به‌چپ)؛
  // تعداد = footer.layout.columns وگرنه تعداد واقعی (۱ تا ۴) — همان قاعده بک‌اند.
  const widgets = chrome?.footer.widgets ?? [];
  const linksCount = widgets.filter((w) => w.type === "links").length;
  const rawCols = (chrome?.footer.layout as Record<string, unknown> | undefined)?.columns;
  const cols =
    typeof rawCols === "number" && Number.isInteger(rawCols) && rawCols >= 1 && rawCols <= 4
      ? rawCols
      : Math.max(1, Math.min(4, linksCount === 0 ? 1 : linksCount));
  return (
    <footer className="site-footer">
      <div
        className="site-footer-inner"
        style={{ display: "grid", gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))` }}
      >
        {widgets.map((w, i) => (
          <div key={i} style={w.type === "copyright" ? { gridColumn: "1 / -1" } : undefined}>
            <ChromeWidget w={w} chrome={chrome} />
          </div>
        ))}
        <Socials socials={chrome?.socials ?? []} />
      </div>
    </footer>
  );
}
