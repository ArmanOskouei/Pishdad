// نسبی و با پسوند: هم روت ۴۰۴ (Next) و هم `not-found.test.ts` (`node --test`)
// این فایل را بار می‌کنند و aliasِ `@/` آنجا resolve نمی‌شود.
import { localizeHref, publicT, type PublicLocale } from "./i18n/public/index.ts";

/**
 * WF-M18 — انتخابِ پیوندهای پیشنهادیِ صفحهٔ ۴۰۴.
 *
 * تابعِ خالص (بدون React/fetch) تا هم روتِ سرور از آن استفاده کند و هم
 * `node --test` بدون کشیدنِ درخت کامپوننت بسنجدش.
 *
 * قاعده: پیوندِ خانه اول، بعد صفحه‌های منتشرشدهٔ sitemap (بدون تکرار و بدون
 * اسلاگِ خالی)، و اگر کمتر از دو پیوند شد، جستجو/بلاگ به‌عنوان پشتیبان تا
 * صفحهٔ ۴۰۴ هرگز «بنی‌بست» نباشد.
 */
export type NotFoundSuggestion = { href: string; label: string };

const MIN_SUGGESTIONS = 2;
const MAX_SUGGESTIONS = 6;

export function notFoundSuggestions(
  pages: readonly { title?: string | null; slug?: string | null }[],
  locale: PublicLocale,
  primary: PublicLocale,
  max: number = MAX_SUGGESTIONS,
): NotFoundSuggestion[] {
  const limit = Number.isFinite(max) && max > 0 ? Math.floor(max) : MAX_SUGGESTIONS;
  const out: NotFoundSuggestion[] = [];
  const seen = new Set<string>();

  const push = (href: string | null | undefined, label: string | null | undefined): void => {
    if (out.length >= limit) return;
    const h = (href ?? "").trim();
    const l = (label ?? "").trim();
    if (!h || !l || seen.has(h)) return;
    seen.add(h);
    out.push({ href: h, label: l });
  };

  push(localizeHref("/", locale, primary), publicT(locale, "chrome.home"));

  for (const page of pages) {
    const slug = (page.slug ?? "").replace(/^\/+|\/+$/g, "");
    if (!slug) continue;
    push(localizeHref(`/${slug}`, locale, primary), page.title);
  }

  if (out.length < MIN_SUGGESTIONS) {
    push(localizeHref("/search", locale, primary), publicT(locale, "search.submit"));
  }
  if (out.length < MIN_SUGGESTIONS) {
    push(localizeHref("/blog", locale, primary), publicT(locale, "blog.archiveTitle"));
  }

  return out;
}
