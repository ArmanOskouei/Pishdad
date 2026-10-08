import type { Metadata } from "next";
import { headers } from "next/headers";
import { notFound } from "next/navigation";
import { SiteFooter, SiteHeader, themeVars } from "@/components/site/Chrome";
import { effectiveLocale } from "@/components/site/PublicPage";
import { DEFAULT_PUBLIC_LOCALE, isPublicLocale, publicT, type PublicLocale } from "@/lib/i18n/public";
import {
  buildAlternatesLanguages,
  localePublicPath,
  parseLocalesHeader,
  SITE_LOCALES_HEADER,
} from "@/lib/public-routing";
import { fetchSiteChrome, siteBaseUrl } from "@/lib/site";
import { resolveTheme } from "@/themes/registry";

/**
 * WF-M20 — صفحهٔ سیاست حریم خصوصی.
 *
 * برخلاف صفحات محتوایی (که از DB می‌آیند)، متن این صفحه از تنظیمات سایت
 * (`privacy_policy`) خوانده می‌شود تا مدیر بدون ساخت صفحه ویرایشش کند. مسیرِ
 * ثابت `privacy` بر `[...path]` مقدم است، پس این صفحه هر صفحهٔ DB با اسلاگ
 * `privacy` را پوشش می‌دهد (عمدی).
 *
 * متن `no_markup` است، پس به‌صورت پاراگراف‌های متنی رندر می‌شود — بدون
 * `dangerouslySetInnerHTML`.
 */
export const revalidate = 300;

type Props = { params: Promise<{ locale: string }> };

/** فقط زبانی سرو می‌شود که میدل‌ور اعلام کرده؛ نبودِ هدر = میدل‌ور خاموش. */
async function localeAvailable(locale: PublicLocale): Promise<boolean> {
  const h = await headers();
  const list = parseLocalesHeader(h.get(SITE_LOCALES_HEADER));
  return list.length === 0 || list.includes(locale);
}

function resolveLocale(raw: string): PublicLocale {
  return isPublicLocale(raw) ? raw : DEFAULT_PUBLIC_LOCALE;
}

/** متن به پاراگراف‌ها: جداکنندهٔ اصلی خط خالی، وگرنه هر خط. */
function toParagraphs(text: string): string[] {
  const trimmed = text.trim();
  if (!trimmed) return [];
  const blocks = trimmed.split(/\n\s*\n/).length > 1 ? trimmed.split(/\n\s*\n/) : trimmed.split(/\n/);
  return blocks.map((b) => b.trim()).filter(Boolean);
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const locale = resolveLocale((await params).locale);
  const chrome = await fetchSiteChrome().catch(() => null);
  const { locales, primary } = effectiveLocale(chrome, locale);
  const base = siteBaseUrl(chrome);
  const canonical = base ? `${base}${localePublicPath(locale, "privacy", primary)}` : undefined;
  const languages = base ? buildAlternatesLanguages({ base, slug: "privacy", locales, primary }) : undefined;
  return {
    ...(base ? { metadataBase: new URL(base) } : null),
    title: publicT(locale, "privacy.title"),
    description: chrome?.description || undefined,
    robots: (chrome?.robots_index ?? true) ? { index: true, follow: true } : { index: false, follow: false },
    ...(canonical ? { alternates: { canonical, ...(languages ? { languages } : null) } } : null),
    ...(chrome?.favicon_url ? { icons: { icon: chrome.favicon_url } } : null),
  };
}

export default async function PrivacyRoute({ params }: Props) {
  const locale = resolveLocale((await params).locale);
  if (!(await localeAvailable(locale))) notFound();

  const chrome = await fetchSiteChrome().catch(() => null);
  const { locales, primary } = effectiveLocale(chrome, locale);
  const switcher = locales.length > 1 ? { locales, primary } : undefined;
  const variant = resolveTheme(chrome?.theme?.slug).manifest.slug;

  const paragraphs = toParagraphs(chrome?.privacy_policy ?? "");

  return (
    <div className={`site theme-${variant}`} style={themeVars(chrome)} dir={locale === "en" ? "ltr" : "rtl"} lang={locale}>
      <SiteHeader chrome={chrome} locale={locale} switcher={switcher} />
      <main style={{ maxInlineSize: 800, marginInline: "auto", padding: "40px 24px 64px" }}>
        <h1 style={{ fontSize: 26, marginBlockEnd: 18 }}>{publicT(locale, "privacy.title")}</h1>
        {paragraphs.length > 0 ? (
          <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
            {paragraphs.map((p, i) => (
              <p key={i} style={{ margin: 0, lineHeight: 2, fontSize: 15, color: "var(--text)" }}>
                {p}
              </p>
            ))}
          </div>
        ) : (
          <p style={{ color: "var(--text-muted)" }}>{publicT(locale, "privacy.empty")}</p>
        )}
      </main>
      <SiteFooter chrome={chrome} locale={locale} />
    </div>
  );
}
