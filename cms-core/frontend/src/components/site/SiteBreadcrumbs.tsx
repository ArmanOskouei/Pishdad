import { buildBreadcrumbTrail } from "@/lib/public-json-ld";
import { publicT, type PublicLocale } from "@/lib/i18n/public";

/**
 * WF-L3 — مسیرِ راهنمایِ دیداریِ صفحاتِ عمومی.
 *
 * ساختار و برچسب‌ها همان `buildBreadcrumbTrail` است که JSON-LD هم از آن
 * تغذیه می‌شود؛ فقط آخرین آیتم (صفحهٔ جاری) به‌جای لینک با `aria-current`
 * رندر می‌شود. بدون هیچ state/fetch — کامپوننتِ سرور، پس برای همهٔ قالب‌ها
 * یکسان است.
 */
export function SiteBreadcrumbs({
  slug,
  locale,
  primary,
  lastLabel,
}: {
  slug: string;
  locale: PublicLocale;
  primary: PublicLocale;
  /** برچسبِ صفحهٔ جاری؛ نبودش یعنی نامِ سگمنت. */
  lastLabel?: string;
}) {
  const items = buildBreadcrumbTrail({ slug, locale, primary, lastLabel });
  if (items.length === 0) return null;

  return (
    <nav className="site-crumbs" aria-label={publicT(locale, "crumbs.aria")}>
      <ol>
        {items.map((item, index) =>
          item.current || !item.href ? (
            <li key={index}>
              <span aria-current="page">{item.label}</span>
            </li>
          ) : (
            <li key={index}>
              <a href={item.href}>{item.label}</a>
            </li>
          ),
        )}
      </ol>
    </nav>
  );
}
