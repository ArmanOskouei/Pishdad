import { localePublicPath } from "./public-routing.ts";
import { publicT, type PublicLocale } from "./i18n/public/index.ts";

/**
 * WF-M3 — سازندهٔ خالصِ `BreadcrumbList` برای JSON-LD صفحاتِ عمومی.
 *
 * عمداً بدون وابستگی به `next`/`react` (مثل `public-routing.ts`) تا هم
 * `[...path]/page.tsx` و هم `node --test` بتوانند از یک منبع بخوانند.
 */

export type BreadcrumbListItem = {
  "@type": "ListItem";
  position: number;
  name: string;
  item?: string;
};

export type BreadcrumbListNode = {
  "@type": "BreadcrumbList";
  itemListElement: BreadcrumbListItem[];
};

export type BreadcrumbListOptions = {
  /** نشانی پایه سایت (بدون اسلش پایانی). */
  base?: string | null;
  /** اسلاگِ خامِ مسیر؛ مثلاً `about/team`. */
  slug: string;
  locale: PublicLocale;
  /** زبانِ پایهٔ سایت (مدل a: بدون پیشوند). */
  primary?: PublicLocale;
  /** برچسبِ خانه؛ پیش‌فرض از فرهنگِ لغتِ سایت. */
  homeLabel?: string;
  /** برچسبِ سفارشی برای هر سگمنت (اختیاری). */
  labels?: Record<string, string>;
};

export function slugSegments(slug: string): string[] {
  return slug.replace(/^\/+|\/+$/g, "").split("/").filter(Boolean);
}

function segmentName(segment: string, labels?: Record<string, string>): string {
  const custom = labels?.[segment];
  if (typeof custom === "string" && custom.trim()) return custom.trim();
  try {
    return decodeURIComponent(segment);
  } catch {
    return segment;
  }
}

export function buildBreadcrumbList(opts: BreadcrumbListOptions): BreadcrumbListNode {
  const primary = opts.primary ?? "fa";
  const base = opts.base ? opts.base.replace(/\/+$/, "") : null;
  const href = (slug: string): string | undefined =>
    base ? `${base}${localePublicPath(opts.locale, slug, primary)}` : undefined;

  const homeLabel = opts.homeLabel?.trim() || publicT(opts.locale, "chrome.home");
  const homeHref = href("");
  const itemListElement: BreadcrumbListItem[] = [
    {
      "@type": "ListItem",
      position: 1,
      name: homeLabel,
      ...(homeHref ? { item: homeHref } : null),
    },
  ];

  let acc = "";
  for (const segment of slugSegments(opts.slug)) {
    acc = acc ? `${acc}/${segment}` : segment;
    const url = href(acc);
    itemListElement.push({
      "@type": "ListItem",
      position: itemListElement.length + 1,
      name: segmentName(segment, opts.labels),
      ...(url ? { item: url } : null),
    });
  }

  return { "@type": "BreadcrumbList", itemListElement };
}

/* ── WF-L3 — مسیرِ راهنمایِ دیداری (همان ساختارِ JSON-LD، لینک‌های نسبی) ── */

export type BreadcrumbTrailItem = {
  /** متنِ نمایشی — با همان منطقِ برچسبِ JSON-LD. */
  label: string;
  /** مسیرِ داخلیِ همان زبان؛ آخرین آیتم لینک ندارد. */
  href?: string;
  current: boolean;
};

export type BreadcrumbTrailOptions = {
  /** اسلاگِ خامِ مسیر؛ مثلاً `about/team`. اسلاگِ خالی ⇒ بدون مسیر راهنما. */
  slug: string;
  locale: PublicLocale;
  primary?: PublicLocale;
  homeLabel?: string;
  labels?: Record<string, string>;
  /** برچسبِ آیتمِ آخر (صفحهٔ جاری)؛ پیش‌فرض نامِ سگمنت. */
  lastLabel?: string;
};

/**
 * مسیرِ راهنمایِ دیداری از روی همان `slug` که JSON-LD می‌سازد؛ فقط آخرین
 * آیتم لینک نمی‌گیرد. خانه همیشه اول است و صفحهٔ خانه (اسلاگِ خالی) هیچ
 * مسیری رندر نمی‌کند.
 */
export function buildBreadcrumbTrail(opts: BreadcrumbTrailOptions): BreadcrumbTrailItem[] {
  const primary = opts.primary ?? "fa";
  const segments = slugSegments(opts.slug);
  if (segments.length === 0) return [];

  const href = (slug: string): string => localePublicPath(opts.locale, slug, primary);
  const items: BreadcrumbTrailItem[] = [
    {
      label: opts.homeLabel?.trim() || publicT(opts.locale, "chrome.home"),
      href: href(""),
      current: false,
    },
  ];

  let acc = "";
  segments.forEach((segment, index) => {
    acc = acc ? `${acc}/${segment}` : segment;
    const isLast = index === segments.length - 1;
    if (isLast) {
      items.push({
        label: opts.lastLabel?.trim() || segmentName(segment, opts.labels),
        current: true,
      });
      return;
    }
    items.push({ label: segmentName(segment, opts.labels), href: href(acc), current: false });
  });

  return items;
}
