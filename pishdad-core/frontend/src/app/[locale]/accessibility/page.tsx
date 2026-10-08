import type { Metadata } from "next";
import { headers } from "next/headers";
import { notFound } from "next/navigation";
import { SiteFooter, SiteHeader, themeVars } from "@/components/site/Chrome";
import { effectiveLocale } from "@/components/site/PublicPage";
import { DEFAULT_PUBLIC_LOCALE, isPublicLocale, publicT, type PublicLocale } from "@/lib/i18n/public";
import {
  accessibilityContact,
  accessibilityParagraphs,
  ACCESSIBILITY_SLUG,
} from "@/lib/accessibility-statement";
import {
  buildAlternatesLanguages,
  localePublicPath,
  parseLocalesHeader,
  SITE_LOCALES_HEADER,
} from "@/lib/public-routing";
import { fetchSiteChrome, siteBaseUrl } from "@/lib/site";
import { resolveTheme } from "@/themes/registry";

/**
 * WF-M17 — صفحهٔ بیانیهٔ دسترس‌پذیری.
 *
 * همتای `/privacy` (WF-M20) با همان الگو: متن از تنظیمات سایت
 * (`accessibility_statement`) خوانده می‌شود تا مدیر بدون ساخت صفحه ویرایشش
 * کند، و مسیرِ ثابت `accessibility` بر `[...path]` مقدم است (عمدی، مثل
 * `privacy`).
 *
 * متن `no_markup` است، پس به‌صورت پاراگراف‌های متنی رندر می‌شود — بدون
 * `dangerouslySetInnerHTML`. و اگر تنظیم خالی باشد، به‌جای پیامِ «تنظیم نشده»
 * پیش‌فرضِ همان زبان + راه تماس نشان داده می‌شود (بیانیهٔ خالی یعنی نصبی که
 * هیچ چیزی اعلام نکرده).
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

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const locale = resolveLocale((await params).locale);
  const chrome = await fetchSiteChrome().catch(() => null);
  const { locales, primary } = effectiveLocale(chrome, locale);
  const base = siteBaseUrl(chrome);
  const canonical = base ? `${base}${localePublicPath(locale, ACCESSIBILITY_SLUG, primary)}` : undefined;
  const languages = base ? buildAlternatesLanguages({ base, slug: ACCESSIBILITY_SLUG, locales, primary }) : undefined;
  return {
    ...(base ? { metadataBase: new URL(base) } : null),
    title: publicT(locale, "accessibility.title"),
    description: chrome?.description || undefined,
    robots: (chrome?.robots_index ?? true) ? { index: true, follow: true } : { index: false, follow: false },
    ...(canonical ? { alternates: { canonical, ...(languages ? { languages } : null) } } : null),
    ...(chrome?.favicon_url ? { icons: { icon: chrome.favicon_url } } : null),
  };
}

export default async function AccessibilityRoute({ params }: Props) {
  const locale = resolveLocale((await params).locale);
  if (!(await localeAvailable(locale))) notFound();

  const chrome = await fetchSiteChrome().catch(() => null);
  const { locales, primary } = effectiveLocale(chrome, locale);
  const switcher = locales.length > 1 ? { locales, primary } : undefined;
  const variant = resolveTheme(chrome?.theme?.slug).manifest.slug;

  const paragraphs = accessibilityParagraphs(chrome?.accessibility_statement, locale);
  const contact = accessibilityContact(chrome);

  return (
    <div className={`site theme-${variant}`} style={themeVars(chrome)} dir={locale === "en" ? "ltr" : "rtl"} lang={locale}>
      <SiteHeader chrome={chrome} locale={locale} switcher={switcher} />
      <main style={{ maxInlineSize: 800, marginInline: "auto", padding: "40px 24px 64px" }}>
        <h1 style={{ fontSize: 26, marginBlockEnd: 18 }}>{publicT(locale, "accessibility.title")}</h1>
        <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
          {paragraphs.map((p, i) => (
            <p key={i} style={{ margin: 0, lineHeight: 2, fontSize: 15, color: "var(--text)" }}>
              {p}
            </p>
          ))}
        </div>

        <section
          aria-labelledby="accessibility-contact"
          style={{ marginBlockStart: 32, paddingBlockStart: 20, borderBlockStart: "1px solid var(--border)" }}
        >
          <h2 id="accessibility-contact" style={{ fontSize: 17, marginBlockEnd: 10 }}>
            {publicT(locale, "accessibility.contact")}
          </h2>
          {contact.mailto || contact.tel ? (
            <ul style={{ listStyle: "none", margin: 0, padding: 0, display: "flex", flexWrap: "wrap", gap: 18 }}>
              {contact.tel ? (
                <li>
                  <span style={{ color: "var(--text-muted)", fontSize: 13.5 }}>{publicT(locale, "accessibility.phoneLabel")}: </span>
                  <a href={contact.tel} dir="ltr">
                    {chrome?.phone}
                  </a>
                </li>
              ) : null}
              {contact.mailto ? (
                <li>
                  <span style={{ color: "var(--text-muted)", fontSize: 13.5 }}>{publicT(locale, "accessibility.emailLabel")}: </span>
                  <a href={contact.mailto} dir="ltr">
                    {chrome?.email}
                  </a>
                </li>
              ) : null}
            </ul>
          ) : (
            <p style={{ margin: 0, color: "var(--text-muted)" }}>{publicT(locale, "accessibility.noContact")}</p>
          )}
        </section>
      </main>
      <SiteFooter chrome={chrome} locale={locale} />
    </div>
  );
}