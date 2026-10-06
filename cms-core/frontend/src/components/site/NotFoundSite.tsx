import { resolveTheme } from "@/themes/registry";
import { localizeHref, publicT, type PublicLocale } from "@/lib/i18n/public";
import type { SitePage } from "@/lib/domain";
import type { SiteChrome } from "@/lib/site";
import type { NotFoundSuggestion } from "@/lib/not-found";
import { effectiveLocale } from "./PublicPage";

/**
 * WF-M18 — صفحهٔ ۴۰۴ قالب‌دار.
 *
 * از **همان کامپوننتِ قالبِ فعال** (`resolveTheme`) استفاده می‌کند و از شکافِ
 * `content` می‌گذارد تا هدر/فوتر/توکن‌های رنگ و تایپوگرافیِ قالب دقیقاً مثل
 * بقیهٔ صفحات سایت بمانند. فرمِ جستجو یک فرمِ سادهٔ GET است و به صفحهٔ جستجوی
 * موجود (`/search?q=`) می‌رود — بدون JS و بدون CDN.
 */
export function NotFoundSite({
  chrome,
  locale,
  suggestions,
}: {
  chrome: SiteChrome | null;
  locale: PublicLocale;
  suggestions: NotFoundSuggestion[];
}) {
  const theme = resolveTheme(chrome?.theme?.slug);
  const { locales, primary } = effectiveLocale(chrome, locale);
  const switcher = locales.length > 1 ? { locales, primary } : undefined;

  const page: SitePage = {
    title: publicT(locale, "notFound.title"),
    slug: "",
    blocks: [],
  };

  const content = (
    <section
      className="site-notfound"
      style={{ maxInlineSize: 560, marginInline: "auto", textAlign: "center", paddingBlock: "40px 16px" }}
    >
      <p
        aria-hidden
        style={{ fontSize: 58, fontWeight: 800, lineHeight: 1, margin: 0, color: "var(--text-muted)", opacity: 0.5 }}
      >
        404
      </p>
      <h1 style={{ fontSize: 24, marginBlock: "14px 10px" }}>{publicT(locale, "notFound.title")}</h1>
      <p style={{ fontSize: 14.5, lineHeight: 1.9, color: "var(--text-muted)", marginBlockEnd: 22 }}>
        {publicT(locale, "notFound.description")}
      </p>

      <form
        role="search"
        action={localizeHref("/search", locale, primary)}
        method="get"
        style={{ display: "flex", gap: 8, justifyContent: "center", flexWrap: "wrap", marginBlockEnd: 8 }}
      >
        <input
          type="search"
          name="q"
          className="input"
          aria-label={publicT(locale, "search.ariaInput")}
          placeholder={publicT(locale, "notFound.searchPlaceholder")}
          style={{ maxInlineSize: 320, inlineSize: "100%" }}
        />
        <button type="submit" className="btn btn-primary">
          {publicT(locale, "notFound.searchCta")}
        </button>
      </form>

      {suggestions.length > 0 ? (
        <nav aria-label={publicT(locale, "notFound.suggested")} style={{ marginBlockStart: 28 }}>
          <h2 style={{ fontSize: 14, fontWeight: 700, color: "var(--text-muted)", marginBlockEnd: 12 }}>
            {publicT(locale, "notFound.suggested")}
          </h2>
          <ul
            style={{
              listStyle: "none",
              padding: 0,
              margin: 0,
              display: "flex",
              flexWrap: "wrap",
              gap: 10,
              justifyContent: "center",
            }}
          >
            {suggestions.map((s) => (
              <li key={s.href}>
                <a className="btn btn-ghost btn-sm" href={s.href}>
                  {s.label}
                </a>
              </li>
            ))}
          </ul>
        </nav>
      ) : null}
    </section>
  );

  return (
    <theme.Component
      page={page}
      chrome={chrome}
      schemas={chrome?.blocks}
      content={content}
      locale={locale}
      switcher={switcher}
    />
  );
}
