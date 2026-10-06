/**
 * WF-C7 — کمک‌های خالصِ بایگانی بلاگ (بدون React/Next) تا با `node --test`
 * سنجیده شوند. مسیرها مدلِ زبانِ سایت (a) را رعایت می‌کنند: زبانِ پایه بدون
 * پیشوند، زبانِ دوم زیر `/{locale}`.
 *
 * ⚠️ import نسبیِ `.ts` عمدی است (هم‌خط `public-routing.ts`): `node --test`
 * بدون resolver اجرا می‌شود و specifier بدون پسوند resolve نمی‌شود.
 */
import { DEFAULT_PUBLIC_LOCALE, type PublicLocale } from "./i18n/public/index.ts";

export const BLOG_PER_PAGE = 10;

/** `?page=` را به عددِ مثبتِ معتبر تبدیل می‌کند؛ هر چیز دیگر ⇒ ۱. */
export function parseBlogPage(value: unknown): number {
  const raw = Array.isArray(value) ? value[0] : value;
  const n = typeof raw === "string" ? Number.parseInt(raw, 10) : typeof raw === "number" ? raw : NaN;
  return Number.isFinite(n) && n >= 1 ? Math.floor(n) : 1;
}

/** پنجرهٔ شماره‌صفحه‌ها حول صفحهٔ جاری (برای صفحه‌بندی). */
export function paginationPages(current: number, last: number, span = 2): number[] {
  const c = current >= 1 ? Math.floor(current) : 1;
  const l = last >= 1 ? Math.floor(last) : 1;
  if (l <= 1) return [1];
  const from = Math.max(1, c - span);
  const to = Math.min(l, c + span);
  const out: number[] = [];
  for (let i = from; i <= to; i++) out.push(i);
  return out;
}

/** مسیرِ پایهٔ بایگانی برای زبانِ داده‌شده (بدون اسلشِ پایانی). */
export function blogBasePath(locale: PublicLocale, primary: PublicLocale = DEFAULT_PUBLIC_LOCALE): string {
  return locale === primary ? "/blog" : `/${locale}/blog`;
}

export function blogArchiveHref(
  locale: PublicLocale,
  primary: PublicLocale = DEFAULT_PUBLIC_LOCALE,
  page = 1,
): string {
  const base = blogBasePath(locale, primary);
  return page > 1 ? `${base}?page=${Math.floor(page)}` : base;
}

/** اسلاگِ نوشته: پیشوندِ `blog/` و اسلشِ ابتدایی حذف می‌شود (هر دو قرارداد). */
export function blogSlug(slug: string): string {
  const bare = slug.replace(/^\/+/, "");
  return /^blog\//i.test(bare) ? bare.slice(5) : bare;
}

export function blogPostHref(
  locale: PublicLocale,
  primary: PublicLocale,
  slug: string,
): string {
  return `${blogBasePath(locale, primary)}/${encodeURIComponent(blogSlug(slug))}`;
}

export function blogCategoryHref(
  locale: PublicLocale,
  primary: PublicLocale,
  category: string,
): string {
  return `${blogBasePath(locale, primary)}/category/${encodeURIComponent(category)}`;
}

export function blogTagHref(locale: PublicLocale, primary: PublicLocale, tag: string): string {
  return `${blogBasePath(locale, primary)}/tag/${encodeURIComponent(tag)}`;
}

export function blogFeedHref(locale: PublicLocale, primary: PublicLocale = DEFAULT_PUBLIC_LOCALE): string {
  return `${blogBasePath(locale, primary)}/feed.xml`;
}
