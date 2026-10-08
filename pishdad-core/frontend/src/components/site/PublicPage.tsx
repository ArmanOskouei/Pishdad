import Link from "next/link";
import type { Metadata } from "next";
import { LOGIN_PATH } from "@/lib/login-path";
import { siteBaseUrl, type SiteChrome } from "@/lib/site";
import { resolveTheme } from "@/themes/registry";
import type { SitePage } from "@/lib/domain";
import {
  DEFAULT_PUBLIC_LOCALE,
  isPublicLocale,
  publicOgLocale,
  publicT,
  type PublicLocale,
} from "@/lib/i18n/public";

/**
 * ECO2 — پوستهٔ مشترکِ صفحاتِ عمومی.
 *
 * همهٔ رشته‌های این پوسته از فرهنگِ لغتِ سایت (`lib/i18n/public`) می‌آیند و
 * زبان از URL می‌رسد. این فایل مسیرِ زبان پایه (`app/page.tsx`) و مسیرِ زبان
 * دوم (`app/[locale]/...`) را یکی نگه می‌دارد تا ترجمه یک‌جا زندگی کند.
 */

/** صفحهٔ خنثی «سایت در حال آماده‌سازی» — نه ۴۰۴ خشک. */
export function PreparingSite({ chrome, locale }: { chrome: SiteChrome | null; locale: PublicLocale }) {
  return (
    <div className="site" style={undefined} dir={locale === "en" ? "ltr" : "rtl"}>
      <main style={{ minBlockSize: "60vh", display: "grid", placeItems: "center", padding: 24 }}>
        <div className="card card-pad" style={{ maxInlineSize: 520, inlineSize: "100%", textAlign: "center" }}>
          <div style={{ fontSize: 44 }} aria-hidden>🏗</div>
          <h1 style={{ fontSize: 19, marginBlock: "8px 4px" }}>{publicT(locale, "home.preparing")}</h1>
          <p style={{ fontSize: 14, color: "var(--text-muted)" }}>{publicT(locale, "home.emptyHint")}</p>
          <Link className="btn btn-primary" href={LOGIN_PATH} style={{ marginBlockStart: 12 }}>
            {publicT(locale, "home.login")}
          </Link>
        </div>
      </main>
    </div>
  );
}

/** متادیتای صفحهٔ آماده‌سازی (noindex). */
export function preparingMetadata(locale: PublicLocale): Metadata {
  return {
    title: publicT(locale, "home.preparingMeta"),
    robots: { index: false, follow: false },
  };
}

/** متادیتای مشترکِ صفحهٔ منتشرشده. */
export function pageMetadata(
  chrome: SiteChrome | null,
  page: SitePage,
  locale: PublicLocale,
  extra?: Partial<Metadata>,
): Metadata {
  const meta = (page.meta ?? {}) as Record<string, unknown>;
  const base = siteBaseUrl(chrome);
  const title = (typeof meta.title === "string" && meta.title) || page.title;
  const description = (typeof meta.description === "string" && meta.description) || chrome?.description || undefined;
  const ogImage = (typeof meta.og_image_url === "string" && meta.og_image_url) || chrome?.og_image_url || chrome?.logo_url || undefined;
  const pageNoIndex =
    meta.noindex === true || String(meta.robots ?? "").toLowerCase().includes("noindex");
  const index = (chrome?.robots_index ?? true) && !pageNoIndex;
  return {
    ...(base ? { metadataBase: new URL(base) } : null),
    title,
    description,
    ...(chrome?.favicon_url ? { icons: { icon: chrome.favicon_url } } : null),
    robots: index ? { index: true, follow: true } : { index: false, follow: false },
    // ECO2 — hreflang: خزنده باید نسخهٔ هر زبانِ همین صفحه را ببیند.
    ...homeAlternates(chrome),
    openGraph: {
      title,
      description,
      siteName: chrome?.title ?? undefined,
      locale: publicOgLocale(locale),
      type: "website",
      ...(base ? { url: base } : null),
      ...(ogImage ? { images: [{ url: ogImage }] } : null),
    },
    ...(ogImage ? { twitter: { card: "summary_large_image", title, images: [ogImage] } } : null),
    ...extra,
  };
}

/**
 * ECO2 — `alternates` صفحهٔ خانه (canonical = ریشه + هر زبان).
 * اگر فقط یک زبان باشد، همان یکی canonical می‌شود و نقشهٔ زبان‌ها یک عضو دارد.
 */
export function homeAlternates(chrome: SiteChrome | null): { alternates: Metadata["alternates"] } | null {
  const base = siteBaseUrl(chrome);
  if (!base) return null;
  const raw = Array.isArray(chrome?.locales) ? chrome!.locales! : [];
  const locales = raw.filter((x): x is PublicLocale => isPublicLocale(x));
  const list = locales.length > 0 ? locales : [DEFAULT_PUBLIC_LOCALE];
  const primary = isPublicLocale(chrome?.primary_locale) ? chrome!.primary_locale! : DEFAULT_PUBLIC_LOCALE;
  const languages: Record<string, string> = {};
  for (const loc of list) {
    languages[loc === "en" ? "en" : "fa"] = loc === primary ? `${base}/` : `${base}/${loc}`;
  }
  return { alternates: { canonical: `${base}/`, languages } };
}

/** زبانِ مؤثر: تنظیمات (locales/primary) وگرنه `locale`. */
export function effectiveLocale(
  chrome: SiteChrome | null,
  fallback: PublicLocale,
): { locale: PublicLocale; locales: PublicLocale[]; primary: PublicLocale } {
  const raw = Array.isArray(chrome?.locales) ? chrome!.locales! : [];
  const locales = raw.filter((x): x is PublicLocale => isPublicLocale(x));
  const primary = isPublicLocale(chrome?.primary_locale) ? chrome!.primary_locale! : DEFAULT_PUBLIC_LOCALE;
  const list = locales.length > 0 ? locales : [primary];
  return { locale: list.includes(fallback) ? fallback : primary, locales: list, primary };
}

/** صفحهٔ خانه با قالبِ فعال — مشترکِ هر دو زبان؛ سوییچر برای قالب اگر دوزبانه باشد. */
export function ThemedHome({
  page,
  chrome,
  banner,
  jsonLd,
  locale,
}: {
  page: SitePage;
  chrome: SiteChrome | null;
  banner?: React.ReactNode;
  jsonLd?: React.ReactNode;
  locale: PublicLocale;
}) {
  const theme = resolveTheme(chrome?.theme?.slug);
  const { locales, primary } = effectiveLocale(chrome, locale);
  const switcher = locales.length > 1 ? { locales, primary } : undefined;
  return (
    <theme.Component page={page} chrome={chrome} schemas={chrome?.blocks} banner={banner} jsonLd={jsonLd} locale={locale} switcher={switcher} isHome />
  );
}

export { DEFAULT_PUBLIC_LOCALE };
