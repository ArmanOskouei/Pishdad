// نسبی و با پسوند: هم `proxy.ts` (Next) و هم `public-routing.test.ts`
// (`node --test`) همین فایل را بار می‌کنند و alias آنجا resolve نمی‌شود.
import {
  PUBLIC_LOCALES,
  DEFAULT_PUBLIC_LOCALE,
  isPublicLocale,
  publicHreflang,
  type PublicLocale,
} from "./i18n/public/index.ts";

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
  "api",
  "preview",
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

/* ── WF-H15 — hreflang و fallback زبان ─────────────────────────────────── */

/**
 * مسیرِ عمومیِ یک صفحه در یک زبان (مدل a): زبان پایه بدون پیشوند و زبان دوم
 * زیر `/{locale}`. `slug` خالی یعنی صفحهٔ خانه؛ برای خانه مسیرِ ریشه `/`.
 */
export function localePublicPath(locale: PublicLocale, slug: string, primary: PublicLocale): string {
  const clean = slug.replace(/^\/+|\/+$/g, "");
  if (locale === primary) return clean ? `/${clean}` : "/";
  return clean ? `/${locale}/${clean}` : `/${locale}`;
}

/**
 * WF-H15 — نقشهٔ `alternates.languages` یک صفحه.
 *
 * برای هر زبانِ موجودِ صفحه یک برچسبِ `hreflang` می‌سازد و `x-default` را به
 * نسخهٔ زبانِ پایه اشاره می‌دهد تا گوگل نسخهٔ مرجع را بشناسد و محتوای تکراری
 * نسازد. زبانِ پایه پیشوند ندارد (مدل a) و زبان دوم `/{locale}` می‌گیرد.
 *
 * ⚠️ فعلاً API بک‌اند زبان‌های واقعیِ یک صفحه را افشا نمی‌کند، پس ورودی همان
 * زبان‌های پیکربندی‌شدهٔ سایت است؛ اگر روزی لیستِ «زبان‌های موجودِ صفحه» از
 * بک‌اند آمد، فقط همین `locales` را با آن عوض کنید (بقیهٔ منطق دست‌نخورده).
 */
export function buildAlternatesLanguages(opts: {
  base: string;
  slug: string;
  locales: readonly PublicLocale[];
  primary: PublicLocale;
  xDefault?: PublicLocale;
}): Record<string, string> {
  const base = opts.base.replace(/\/+$/, "");
  const list = opts.locales.length > 0 ? opts.locales : [opts.primary];
  const out: Record<string, string> = {};
  for (const loc of list) {
    out[publicHreflang(loc)] = `${base}${localePublicPath(loc, opts.slug, opts.primary)}`;
  }
  out["x-default"] = `${base}${localePublicPath(opts.xDefault ?? opts.primary, opts.slug, opts.primary)}`;
  return out;
}

/**
 * WF-H15 — بهترین زبانِ منطبق با `Accept-Language` مرورگر.
 *
 * هدر را بر اساس `q` مرتب می‌کند و هر تگ را به زیرتَگِ اصلی‌اش نگاشت می‌کند
 * (`fa-IR` → `fa`). اولین تطبیقِ درونِ فهرست برگردانده می‌شود وگرنه زبانِ پایه؛
 * پس هرگز زبانی بیرون از پیکربندیِ سایت انتخاب نمی‌شود.
 */
export function pickBrowserLocale(
  acceptLanguage: string | null | undefined,
  locales: readonly PublicLocale[],
  primary: PublicLocale,
): PublicLocale {
  const list = locales.length > 0 ? locales : [primary];
  if (!acceptLanguage) return primary;
  const ranked = acceptLanguage
    .split(",")
    .map((part) => {
      const [rawTag, ...params] = part.trim().split(";");
      const tag = (rawTag ?? "").trim().toLowerCase();
      let q = 1;
      for (const param of params) {
        const m = param.trim().match(/^q=([0-9.]+)$/i);
        if (m) q = Number.parseFloat(m[1]!);
      }
      return { tag, q: Number.isFinite(q) ? q : 0 };
    })
    .filter((entry) => entry.tag !== "")
    .sort((a, b) => b.q - a.q);
  for (const { tag } of ranked) {
    const base = tag.split("-")[0]!;
    const hit = list.find((loc) => loc === base);
    if (hit) return hit;
  }
  return primary;
}

/**
 * WF-H15 — ترتیبِ کاندیدهای زبان برای fallback.
 *
 * زبانِ درخواستی اول، بعد زبانِ مرورگر، بعد زبانِ پایه، بعد بقیهٔ زبان‌های
 * پیکربندی‌شده؛ تکراری‌ها حذف می‌شوند و هرگز زبانی بیرون از `locales` کاندید
 * نمی‌شود تا حلقهٔ ریدایرکت نسازیم. مسیر فقط تا اولین زبانی که صفحه در آن
 * منتشر شده پیش می‌رود.
 */
export function localeFallbackChain(
  requested: PublicLocale,
  locales: readonly PublicLocale[],
  primary: PublicLocale,
  browserPreferred?: PublicLocale | null,
): PublicLocale[] {
  const list = locales.length > 0 ? locales : [primary];
  const ordered: (PublicLocale | null | undefined)[] = [requested, browserPreferred, primary, ...list];
  const seen = new Set<PublicLocale>();
  const chain: PublicLocale[] = [];
  for (const loc of ordered) {
    if (!loc || !list.includes(loc) || seen.has(loc)) continue;
    seen.add(loc);
    chain.push(loc);
  }
  return chain;
}

export { PUBLIC_LOCALES, DEFAULT_PUBLIC_LOCALE };
