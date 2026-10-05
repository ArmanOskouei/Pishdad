// نسبی و با پسوند: هم `proxy.ts` (Next) و هم `public-routing.test.ts`
// (`node --test`) همین فایل را بار می‌کنند و alias آنجا resolve نمی‌شود.
import { PUBLIC_LOCALES, DEFAULT_PUBLIC_LOCALE, isPublicLocale, type PublicLocale } from "./i18n/public/index.ts";

/**
 * ECO2 — قواعدِ مسیریابیِ زبانِ سایتِ عمومی (مدل a).
 *
 * این توابع **خالص**اند (بدون `Request`/`Response`) تا میدل‌ور (`proxy.ts`) و
 * مسیرها هر دو از یک منبع بخوانند و `node --test` بتواند بدون Next تستشان کند.
 *
 * مدل a: زبانِ پایه در ریشه (`/about`) و زبانِ دوم زیر پیشوند (`/en/about`).
 */

export const SITE_LOCALE_HEADER = "x-site-locale";
export const SITE_LOCALES_HEADER = "x-site-locales";
export const SITE_PRIMARY_LOCALE_HEADER = "x-site-primary-locale";

/** پنل و API هرگز زبانِ سایت نمی‌گیرند. */
const NON_SITE_SEGMENTS = new Set([
  "admin",
  "central",
  "api",
  "preview",
  "suspended",
  "_next",
  "favicon.ico",
  "robots.txt",
  "sitemap.xml",
  "llms.txt",
  "manifest.webmanifest",
  "icons",
  "fonts",
]);

export function firstSegment(pathname: string): string {
  return pathname.split("/").filter(Boolean)[0] ?? "";
}

/** این مسیر متعلق به سایتِ عمومی است (نه پنل/API/فایل ثابت)؟ */
export function isPublicSitePath(pathname: string): boolean {
  const seg = firstSegment(pathname);
  if (!seg) return true;
  if (NON_SITE_SEGMENTS.has(seg)) return false;
  // فایل ثابت (دارای پسوند) سایت نیست.
  return !/\.[a-z0-9]+$/i.test(seg);
}

/**
 * زبانِ مؤثرِ یک مسیر + مسیرِ بدون پیشوند (اسلاگِ خام).
 *
 * - `/en/about` → `{ locale: "en", bare: "/about", hadPrefix: true }`
 * - `/about`    → زبانِ پیش‌فرض (که `primary` تعیین می‌کند)
 */
export function splitLocalePrefix(
  pathname: string,
  primary: PublicLocale = DEFAULT_PUBLIC_LOCALE,
): { locale: PublicLocale; bare: string; hadPrefix: boolean } {
  const seg = firstSegment(pathname);
  if (isPublicLocale(seg)) {
    const rest = pathname.slice(seg.length + 1).replace(/^\/+/, "");
    return { locale: seg, bare: rest ? `/${rest}` : "", hadPrefix: true };
  }
  return { locale: primary, bare: pathname || "/", hadPrefix: false };
}

/**
 * ECO2 — تصمیمِ مسیریابیِ سایتِ عمومی (خالص، بدون Next) — همان چیزی که
 * `proxy.ts` اجرا می‌کند:
 *
 * - `redirect` : پیشوندِ زبانِ پایه (`/fa/about` → `/about`) یا پیشوندِ زبانِ
 *   نصب‌نشده (حالتِ تک‌زبانه: `/en/about` → `/about`) ⇒ ۳۰۸، تا محتوای تکراری نسازیم.
 * - `rewrite`  : مسیرِ بدونِ پیشوند (`/about`) ⇒ بازنویسیِ داخلی به `/{primary}/about`
 *   تا `[locale]` تنها رندرکنندهٔ عمومی بماند.
 * - `serve`    : پیشوندِ زبانِ دومِ فعال (`/en/about`) ⇒ همان مسیر.
 */
export type LocaleRouteAction = "rewrite" | "redirect" | "serve";

export interface LocaleRouteDecision {
  action: LocaleRouteAction;
  /** زبانِ مؤثرِ مسیر — برای هدرها. */
  locale: PublicLocale;
  /** مسیرِ مقصدِ rewrite/redirect؛ در `serve` تعریف‌نشده. */
  path?: string;
}

export function resolveLocaleRoute(
  pathname: string,
  locales: readonly PublicLocale[],
  primary: PublicLocale,
): LocaleRouteDecision {
  const { locale, bare, hadPrefix } = splitLocalePrefix(pathname, primary);
  const available = locales.length > 0 ? locales : [primary];

  if (hadPrefix && locale === primary) {
    return { action: "redirect", locale, path: bare || "/" };
  }
  if (hadPrefix && !available.includes(locale)) {
    return { action: "redirect", locale: primary, path: bare || "/" };
  }
  if (!hadPrefix) {
    return { action: "rewrite", locale: primary, path: `/${primary}${bare === "/" ? "" : bare}` };
  }
  return { action: "serve", locale };
}

/** فهرستِ زبان‌ها برای هدر؛ زبانی که در پیشوند آمده باید زبانِ دوم باشد. */
export function localesForHeader(locales: readonly PublicLocale[]): string {
  return locales.join(",");
}

export function parseLocalesHeader(value: string | null | undefined): PublicLocale[] {
  if (!value) return [];
  return value
    .split(",")
    .map((s) => s.trim())
    .filter((s): s is PublicLocale => isPublicLocale(s));
}

export { PUBLIC_LOCALES, DEFAULT_PUBLIC_LOCALE };
