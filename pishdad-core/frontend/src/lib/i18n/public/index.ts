/**
 * ECO2 — لایهٔ زبانِ سایتِ عمومی.
 *
 * تفاوتِ بنیادی با لایهٔ پنل (`../index.tsx`): زبانِ پنل یک تنظیمِ کلاینتی
 * (`theme.dir` + localStorage) است، ولی زبانِ سایتِ عمومی از **URL** می‌آید
 * (مدل a: زبان پایه در ریشه، زبان دوم زیر `/{locale}`). پس اینجا اصلاً React
 * لازم نیست: یک نگاشتِ خالص که هم کامپوننتِ سرور و هم کمکی‌های سمت سرور
 * می‌توانند بدون context صدا بزنند.
 *
 * ⚠️ importهای نسبیِ `.ts` عمدی‌اند: `npm test` با `node --test` بدون resolver
 * اجرا می‌شود و specifier بدون پسوند resolve نمی‌شود (همان محدودیتی که
 * `menu.ts` را به import نسبی وادار کرده). این ماژول در مسیر اجرای تست هم
 * بار می‌شود (`block-type.test.ts`)، پس aliasِ `@/` اینجا مجاز نیست.
 */
import { publicFa, type PublicMessageKey } from "./fa.ts";
import { publicEn } from "./en.ts";

export type PublicLocale = "fa" | "en";
export type { PublicMessageKey } from "./fa.ts";

/** زبان‌های پشتیبانی‌شدهٔ سایتِ عمومی — تنها منبعِ حقیقت. */
export const PUBLIC_LOCALES: readonly PublicLocale[] = Object.freeze(["fa", "en"]);

export const DEFAULT_PUBLIC_LOCALE: PublicLocale = "fa";

export function isPublicLocale(v: unknown): v is PublicLocale {
  return v === "fa" || v === "en";
}

/** `dir` از زبان می‌آید، نه از جای دیگر: `en` ⇔ `ltr`، `fa` ⇔ `rtl`. */
export function publicDir(locale: PublicLocale): "ltr" | "rtl" {
  return locale === "en" ? "ltr" : "rtl";
}

/** برچسبِ `hreflang`/OG برای هر زبان. */
export function publicHreflang(locale: PublicLocale): string {
  return locale === "en" ? "en" : "fa";
}

export function publicOgLocale(locale: PublicLocale): string {
  return locale === "en" ? "en_US" : "fa_IR";
}

export function publicHtmlLang(locale: PublicLocale): string {
  return locale === "en" ? "en" : "fa";
}

/**
 * ECO2 — لینکِ داخلیِ سایت را برای زبانِ فعلی می‌سازد (مدل a).
 *
 * زبان پایه در ریشه می‌ماند (`/about`) و زبان دوم پیشوند می‌گیرد
 * (`/en/about`). فقط لینک‌های **داخلی** (شروع با `/`) و **بدون پیشوندِ زبانِ
 * موجود** تغییر می‌کنند؛ `http(s)`، `#`، `mailto:` و مسیرهای `/admin/...`
 * (پنل و نه سایت عمومی) دست‌نخورده می‌مانند تا دکمهٔ زبان چیزی را نشکند.
 */
export function localizeHref(
  href: string,
  locale: PublicLocale,
  primary: PublicLocale = DEFAULT_PUBLIC_LOCALE,
): string {
  if (locale === primary) return href;
  if (!href.startsWith("/") || href.startsWith("//")) return href;
  const rest = href.replace(/^\/+/, "");
  const first = rest.split("/")[0]!;
  if (isPublicLocale(first)) return href; // از قبل پیشوند دارد
  if (first === "admin") return href; // پنل، نه سایت. ریشه‌های دیگرِ پنل از `PISHDAD_PANEL_ROOTS` می‌آیند و گیتِ واقعی پروکسی است، نه این تابع.
  return rest ? `/${locale}/${rest}` : `/${locale}`;
}

const DICT: Record<PublicLocale, Record<string, string>> = { fa: publicFa, en: publicEn };

/** جای‌گذاریِ `{n}` — الگوی مسطح، بدون i18n سنگین (هم‌خط لایهٔ پنل). */
export function formatPublic(template: string, values?: Record<string, string | number>): string {
  if (!values) return template;
  return template.replace(/\{(\w+)\}/g, (whole, k: string) =>
    k in values ? String(values[k]) : whole,
  );
}

/**
 * `t` همیشه رشته برمی‌گرداند: کلیدِ ناشناخته به خودِ کلید می‌رسد نه `undefined`
 * (وگرنه در React کلِ عنصر بی‌صدا ناپدید می‌شود).
 */
export function publicT(
  locale: PublicLocale,
  key: PublicMessageKey,
  values?: Record<string, string | number>,
): string {
  return formatPublic(DICT[locale]?.[key] ?? publicFa[key] ?? key, values);
}
