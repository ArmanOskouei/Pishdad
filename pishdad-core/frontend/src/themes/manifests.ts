import type { ThemeManifest } from "./theme-manifest";
import commerce from "./commerce/theme.json" with { type: "json" };
import editorial from "./editorial/theme.json" with { type: "json" };
import minimal from "./minimal/theme.json" with { type: "json" };

/**
 * ECO1 — رجیستریِ خالصِ قالب‌های درون‌ساخت (بدون React).
 *
 * این فایل تنها منبعِ «کدام اسلاگ‌ها وجود دارند» در فرانت است. رندرر
 * (`registry.tsx`) کامپوننت هر قالب را روی همین اسلاگ‌ها سوار می‌کند.
 *
 * ## چرا رزولو در یک تابعِ خالص است
 *
 * سایتِ زنده ممکن است اسلاگی بگیرد که این نسخه از فرانت نمی‌شناسد (قالبِ
 * حذف‌شده، دادهٔ قدیمی، یا مقدارِ دست‌کاری‌شده). قرارداد این است که چنین
 * اسلاگی **هرگز** خطا ندهد و به قالبِ پیش‌فرض بیفتد — نه صفحهٔ سفید.
 */

/** وقتی شکاف/انتخاب ناشناخته است، این قالب رندر می‌شود. */
export const DEFAULT_THEME_SLUG = "minimal";

export const THEME_MANIFESTS: ThemeManifest[] = [
  minimal as ThemeManifest,
  editorial as ThemeManifest,
  commerce as ThemeManifest,
];

const BY_SLUG: Record<string, ThemeManifest> = Object.fromEntries(
  THEME_MANIFESTS.map((m) => [m.slug, m]),
);

/** اسلاگ‌های شناخته‌شدهٔ کد — به ترتیب. */
export function themeSlugs(): string[] {
  return THEME_MANIFESTS.map((m) => m.slug);
}

/** آیا این اسلاگ یک قالبِ کد‌محورِ شناخته‌شده است؟ */
export function isKnownTheme(slug: string): boolean {
  return Object.prototype.hasOwnProperty.call(BY_SLUG, slug);
}

/**
 * ⭐ اسلاگ → مانیفست، با fallback ایمن.
 *
 * `hasOwnProperty` عمدی است و نه `BY_SLUG[slug] ?? default`: برای ورودی‌ای
 * مثل `"constructor"` یا `"__proto__"`، ایندکسِ ساده یک تابع/آبجکتِ ارثی از
 * `Object.prototype` برمی‌گرداند که **truthy** است و fallback را دور می‌زند.
 * یعنی سایت به‌جای قالبِ پیش‌فرض، چیزی رندر می‌کرد که هیچ ربطی به قالب ندارد.
 */
export function resolveThemeManifest(slug?: string | null): ThemeManifest {
  if (typeof slug === "string" && Object.prototype.hasOwnProperty.call(BY_SLUG, slug)) {
    return BY_SLUG[slug];
  }
  return BY_SLUG[DEFAULT_THEME_SLUG];
}
