import { SOCIAL_FA, type ChromeLinkItem, type SocialItem, type WidgetValue } from "@/lib/domain";
// E66 — چیدمانِ فوتر (بالاتر/ستون‌ها/پایین‌تر) یک منطقِ مشترک با بومِ پنل دارد.
import { distributeFooter, footerColumnCount, interleaveColumns } from "@/lib/footer-layout";
// F0.2: ویجت CTA و آیتم‌های فوتر/ناوبری `href` می‌دهند و تا امروز هیچ
// sanitize‌ای نداشتند ⇒ `javascript:` در هدر *همهٔ صفحات* سایت زنده بود.
// منبع حقیقت سمت سرور `App\Validation\SafeUrl` است؛ این لایه دفاع عمقی برای
// داده‌ای است که پیش از آن در DB نشسته.
import { safeHref } from "@/lib/sanitize";
// K6.4: واژگانِ ویجت‌های هسته و متنِ لاگ/اعلان، در `lib/widget-type.ts` زندگی
// می‌کنند تا لاگ و رفتار و تست یک روایت داشته باشند (و آزمون بتواند بدون
// رندرر React آن‌ها را بخواند).
import {
  chromeWidgetDiagnosticsEnabled,
  describeUnknownWidgetType,
  isCoreChromeWidgetType,
  unknownWidgetNotice,
  unknownWidgetWarning,
} from "@/lib/widget-type";
import type { SiteChrome } from "@/lib/site";
import { publicT, type PublicLocale, type PublicMessageKey } from "@/lib/i18n/public";
import { LanguageSwitcher } from "./LanguageSwitcher";
import { ResponsiveImage, normalizeSources, normalizeSrcset } from "./ResponsiveImage";
import { SiteModeToggle } from "./SiteModeToggle";
import { SiteNav } from "./SiteNav";
import { SiteSearchBox } from "./SiteSearchBox";
import type { SiteMode } from "@/lib/site-mode";

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

/**
 * `diagnostics` یعنی «این رندر در دیداگ است» — پیش‌فرض از محیط می‌آید
 * (`lib/widget-type.ts:chromeWidgetDiagnosticsEnabled`).
 *
 * پارامترِ صریح برای وقتی لازم است که **ادمین/پیش‌نمایش** کروم را رندر کند
 * (`admin/header-footer`): همان کامپوننت، ولی با اعلانِ دیداری، تا اپراتور
 * ببیند ویجتِ افزونه‌اش در سایت اصلاً رندر نمی‌شود. مصرف‌کننده‌های عمومی
 * (`app/page.tsx`، `app/[...path]/page.tsx`، `app/search/page.tsx`) این را پاس
 * نمی‌دهند و رفتارشان همان پیش‌فرض محیط است.
 */
export function ChromeWidget({
  w,
  chrome,
  diagnostics,
  locale = "fa",
}: {
  w: WidgetValue;
  chrome: SiteChrome | null;
  diagnostics?: boolean;
  /** ECO2 — زبانِ سایت؛ از URL می‌آید. پیش‌فرض `fa` یعنی پنل/پیش‌نمایش بدون تغییر. */
  locale?: PublicLocale;
}) {
  const s = (w.settings ?? {}) as Record<string, unknown>;
  const S = (k: string) => (typeof s[k] === "string" ? (s[k] as string) : "");
  const t = (k: PublicMessageKey, values?: Record<string, string | number>) => publicT(locale, k, values);
  switch (w.type) {
    case "logo": {
      const src = typeof s.media_url === "string" && s.media_url ? (s.media_url as string) : (chrome?.logo_url ?? null);
      const showTitle = s.show_title !== false;
      const h = s.size === "sm" ? 28 : s.size === "lg" ? 52 : 40;
      // WF-C4 — اگر بک‌اند نسخه‌های واکنش‌گرا را هم داده باشد، `<picture>`؛
      // وگرنه دقیقاً همان `<img src>` قبلی.
      const srcset = normalizeSrcset(s.media_srcset);
      const sources = normalizeSources(s.media_sources);
      return (
        <a href="/" className="site-brand" aria-label={t("chrome.brandHome")}>
          {src ? (
            <ResponsiveImage
              src={src}
              srcset={srcset}
              sources={sources}
              alt={chrome?.title ?? t("chrome.logoAlt")}
              loading="eager"
              sizes={`${h}px`}
              style={{ blockSize: h, inlineSize: "auto" }}
            />
          ) : null}
          {showTitle ? <b>{chrome?.title ?? t("chrome.defaultTitle")}</b> : null}
        </a>
      );
    }
    case "nav": {
      // آیتم page با title/url زنده از کروم می‌آید (تغییر نام/اسلاگ خودکار منعکس می‌شود).
      const links = Array.isArray(s.links)
        ? (s.links as ChromeLinkItem[])
        : [{ label: t("chrome.home"), href: "/" }];
      const style = typeof s.style === "string" ? s.style : "horizontal";
      return <SiteNav links={links} style={style} locale={locale} />;
    }
    case "search":
      return <SiteSearchBox placeholder={S("placeholder") || undefined} locale={locale} />;
    case "cta":
      return <a className="btn btn-primary btn-sm" href={safeHref(S("href")) ?? "/"}>{S("label") || t("chrome.cta")}</a>;
    case "socials":
      return <Socials socials={chrome?.socials ?? []} locale={locale} />;
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
          {chrome?.phone ? <div>{t("chrome.phone")} <span dir="ltr">{chrome.phone}</span></div> : null}
          {chrome?.email ? <div>{t("chrome.email")} <span dir="ltr">{chrome.email}</span></div> : null}
          {!chrome?.phone && !chrome?.email ? t("chrome.support") : null}
        </div>
      );
    case "newsletter":
      return <p style={{ fontSize: 13, color: "var(--text-muted)", margin: 0 }}>{S("text") || t("chrome.newsletter")}</p>;
    case "copyright":
      return <small style={{ color: "var(--text-muted)" }}>{S("text") || `© ${new Date().getFullYear()} ${chrome?.title ?? ""}`}</small>;
    // K6.4 — `type` ناشناس (شاخهٔ `default` بالا/پایین همین فایل). تصمیم‌ها و
    // دلیلش در `lib/widget-type.ts` است؛ اینجا فقط ویوی همان تصمیم.
    //
    // چرا اینجا **محتوا** ساخته نمی‌شود:
    //  ۱. **هیچ primitive‌ای وجود ندارد.** `site.header_widget` و
    //     `site.footer_widget` در قرارداد با `fields => []` و
    //     `open_schema => false` اعلام شده‌اند
    //     (`PluginPackageContract.php:314,340`) ⇒ `validateDeclaration` برای
    //     هر اعلان خطای `no_schema` می‌دهد (`:829-837`) و رشتهٔ نقطه در هیچ
    //     کدِ runtime بک‌اند دیده نمی‌شود. کانالِ واقعیِ افزونه
    //     `manifest.widgets.{header,footer}` است
    //     (`ManifestRegistry.php:175-206`) که تا همین‌جا زنده است و همین
    //     `switch` متوقفش می‌کند.
    //  ۲. تنها راهِ رندرِ افزونه در سایت عمومی، دادنِ `html`/`js`/`component`
    //     است — که خودِ قرارداد در `FORBIDDEN` (`:118-121`) banned کرده و در
    //     مرورگرِ بازدیدکننده یعنی تصاحبِ صفحه.
    //  ۳. پس تنها چیزِ صادقانه این است: **بگو که رندر نشد.** ساختنِ جایی که
    //     چیزی رندر نمی‌شود همان دروگویی است که K6.10 برای کارتِ داشبورد حذف
    //     کرد.
    //
    // قاعده‌ها (هم‌خط `BlockRenderer`، چون هر دو لایهٔ عمومی‌اند):
    //  ۱. **هرگز throw نمی‌کنیم.** یک ویجتِ خراب نباید هدر/فوترِ همهٔ صفحات
    //     سایت را بیندازد.
    //  ۲. **همیشه لاگ سمتِ سرور.** این تنها کانالی است که در SSR به اپراتور
    //     می‌رسد و از بین نمی‌رود. اگر `type` در `CORE_CHROME_WIDGET_TYPES`
    //     باشد ولی `switch` نخورده، لغزشِ **خودِ ما** است ⇒ یک پله بلندتر:
    //     `error`.
    //  ۳. **اعلانِ دیداری فقط وقتی تشخیص روشن است** (`diagnostics`) — یعنی نه
    //     production، یا production با `CHROME_WIDGET_DIAGNOSTICS=1`، یا پنل
    //     که صریح پاس می‌دهد. برای بازدیدکننده چیزی رندر نمی‌شود (نه داخلِ
    //     سایت لو می‌رود، نه صفحه بد به‌نظر می‌رسد) ولی لاگ حتماً رفته.
    //
    // این شاخه فقط وقتی اجرا می‌شود که هیچ `case`ای نخورده باشد، یعنی
    // **نمی‌تواند** ویجتِ هسته را بپوشاند — امضای `switch` در
    // `widget-type.test.ts` قفل شده است.
    default: {
      if (isCoreChromeWidgetType(w.type)) {
        console.error(unknownWidgetWarning(w.type, "registry-drift"));
      } else {
        console.warn(unknownWidgetWarning(w.type));
      }
      if (!(diagnostics ?? chromeWidgetDiagnosticsEnabled())) return null;
      const named = describeUnknownWidgetType(w.type) || t("widget.emptyName");
      return (
        <span
          role="note"
          data-widget-state="unknown"
          data-widget-type={named}
          style={{ display: "inline-block", padding: 8, border: "1px dashed var(--border)", borderRadius: 10, color: "var(--text-muted)", fontSize: 12.5 }}
        >
          {unknownWidgetNotice(locale, w.type)}
        </span>
      );
    }
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

export function Socials({ socials, locale = "fa" }: { socials: SocialItem[]; locale?: PublicLocale }) {
  const active = socials.filter((x) => x.active);
  if (active.length === 0) return null;
  return (
    <div className="site-socials" aria-label={publicT(locale, "chrome.socialsAria")}>
      {active.map((x) => (
        <a key={x.key} href={safeHref(x.url) ?? "#"} target="_blank" rel="noreferrer" title={socialLabel(x)}>
          <span>{socialLabel(x)}</span>
        </a>
      ))}
    </div>
  );
}

export function SiteHeader({
  chrome,
  diagnostics,
  locale = "fa",
  switcher,
  defaultMode = "dark",
}: {
  chrome: SiteChrome | null;
  diagnostics?: boolean;
  locale?: PublicLocale;
  /**
   * ECO2 — دکمهٔ تغییر زبان را مسیر صریح می‌دهد؛ نبودش یعنی دو زبانه نیست و
   * هیچ دکمه‌ای رندر نمی‌شود (سایتِ تک‌زبانه دقیقاً مثل قبل).
   */
  switcher?: { locales: PublicLocale[]; primary: PublicLocale };
  /** WF-L3 — حالتِ پیش‌فرضِ سایت/قالب برای سوییچِ روشن/تیره. */
  defaultMode?: SiteMode;
}) {
  return (
    <header className="site-header">
      <div className="site-header-inner">
        {(chrome?.header.widgets ?? []).map((w, i) => (
          <ChromeWidget key={i} w={w} chrome={chrome} diagnostics={diagnostics} locale={locale} />
        ))}
        <SiteModeToggle locale={locale} defaultMode={defaultMode} />
        {switcher && switcher.locales.length > 1 ? (
          <LanguageSwitcher locales={switcher.locales} primary={switcher.primary} current={locale} />
        ) : null}
      </div>
    </header>
  );
}

export function SiteFooter({
  chrome,
  diagnostics,
  locale = "fa",
}: {
  chrome: SiteChrome | null;
  diagnostics?: boolean;
  locale?: PublicLocale;
}) {
  // E66 — سه ناحیه: بالاتر از ستون‌ها، ستون‌ها، پایین‌تر از ستون‌ها.
  // همین ماژول در بومِ پنل هم مصرف می‌شود تا ترتیبِ پنل و سایت یکی بماند.
  const widgets = chrome?.footer.widgets ?? [];
  const linksCount = widgets.filter((w) => w.type === "links").length;
  const rawCols = (chrome?.footer.layout as Record<string, unknown> | undefined)?.columns;
  const cols = footerColumnCount(rawCols, linksCount);
  const zones = distributeFooter(widgets, cols);

  const fullWidth = (w: WidgetValue, key: string) => (
    <div key={key} style={{ gridColumn: "1 / -1" }}>
      <ChromeWidget w={w} chrome={chrome} diagnostics={diagnostics} locale={locale} />
    </div>
  );

  return (
    <footer className="site-footer">
      <div
        className="site-footer-inner"
        style={{ display: "grid", gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))` }}
      >
        {zones.above.map((w, i) => fullWidth(w, `above-${i}`))}
        {interleaveColumns(zones.columns).map((w, i) => (
          <div key={`grid-${i}`}>
            <ChromeWidget w={w} chrome={chrome} diagnostics={diagnostics} locale={locale} />
          </div>
        ))}
        {zones.below.map((w, i) => fullWidth(w, `below-${i}`))}
        <Socials socials={chrome?.socials ?? []} locale={locale} />
      </div>
    </footer>
  );
}
