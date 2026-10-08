/**
 * F3 — لایه داده سایت عمومی (ISR).
 * همه فراخوانی‌ها بدون احراز هویت‌اند (endpointهای public بک‌اند)؛
 * کش با تگ `pages` تا Route Handler امن revalidate باطلش کند.
 */

import { cache } from "react";
import {
  asPaginator,
  type LayoutData,
  type Paginator,
  type SidebarData,
  type SitePage,
  type SiteStatus,
  type SocialItem,
} from "@/lib/domain";
import {
  applyAssetBase,
  applyMediaBase,
  normalizeAssetBase,
  rebaseBlockMedia,
  resolveAssetBase,
} from "@/lib/asset-base";
import { buildRssFeed, type FeedItem } from "@/lib/public/rss-feed";
import { blogFeedHref } from "@/lib/blog";
import { DEFAULT_PUBLIC_LOCALE, isPublicLocale, type PublicLocale } from "@/lib/i18n/public";
import { effectiveValue } from "./site-config.ts";

export { applyAssetBase, applyMediaBase, normalizeAssetBase, resolveAssetBase } from "@/lib/asset-base";

const UPSTREAM =
  effectiveValue("INTERNAL_API_URL", "") ||
  effectiveValue("PISHDAD_PUBLIC_API_URL", "") ||
  (process.env.NEXT_PUBLIC_API_URL ?? "").trim() ||
  "http://localhost:8080/api";

export const SITE_REVALIDATE = 300; // ثانیه — ISR معقول برای صفحات عمومی
export const SITE_TAG = "pages";

/**
 * کلیدِ سراسریِ «کش سایت» از بک‌اند (بدون کش؛ مموایزِ per-request با `cache()`).
 *
 * خاموش ⇒ همهٔ دادهٔ سایت با `no-store` می‌آید (تنظیمات بی‌درنگ روی سایت دیده
 * می‌شود با یک رفرش ساده). روشن ⇒ ISR + باطل‌سازیِ تگی (سریع‌تر، کم‌مصرف‌تر).
 */
export const siteCacheEnabled = cache(async (): Promise<boolean> => {
  try {
    const res = await fetch(`${UPSTREAM}/v1/site/cache`, {
      headers: { Accept: "application/json" },
      cache: "no-store",
    });
    if (!res.ok) return true;
    const j = (await res.json().catch(() => ({}))) as { data?: { enabled?: boolean } };
    return (j?.data?.enabled ?? true) !== false;
  } catch {
    return true;
  }
});

async function get<T>(path: string, tags: string[], fresh = false): Promise<T | null> {
  const cached = await siteCacheEnabled();
  try {
    const res = await fetch(`${UPSTREAM}${path}`, {
      headers: { Accept: "application/json" },
      ...(fresh || !cached ? { cache: "no-store" as const } : { next: { tags, revalidate: SITE_REVALIDATE } }),
    });
    if (!res.ok) return null;
    const json = await res.json().catch(() => ({}));
    return ((json as { data?: unknown })?.data ?? json) as T;
  } catch {
    return null;
  }
}

/** صفحه منتشرشده (فقط published — تضمین سمت بک‌اند). F4.5: locale اختیاری. */
export function fetchSitePage(slug: string, locale?: string): Promise<SitePage | null> {
  const qs = locale ? `?locale=${encodeURIComponent(locale)}` : "";
  return get<SitePage>(`/v1/site/pages/${encodeURIComponent(slug)}${qs}`, [SITE_TAG, `page:${slug}`]).then(
    rebasePageMedia,
  );
}

/**
 * WF-H15 — واکشی صفحه با `fallback` زبان.
 *
 * نسخهٔ هر زبان مستقل است؛ اگر صفحه در زبانِ درخواستی منتشر نشده باشد،
 * به ترتیبِ `chain` زبان‌های دیگر امتحان می‌شوند. زبانِ واقعیِ یافته‌شده هم
 * برگردانده می‌شود تا مسیر بتواند در صورت تفاوت، canonical/redirect را درست
 * بسازد. `null` یعنی صفحه در هیچ‌یک از زبان‌های کاندید منتشر نشده است.
 */
export async function fetchSitePageFallback(
  slug: string,
  chain: readonly string[],
): Promise<{ page: SitePage; locale: string } | null> {
  for (const loc of chain) {
    const page = await fetchSitePage(slug, loc).catch(() => null);
    if (page) return { page, locale: loc };
  }
  return null;
}

/**
 * K6.12 — رجیستری بلوک‌های schema-only که بک‌اند در `chrome.blocks` می‌دهد.
 * کلید = `type`، مقدار = schema بلوک؛ فقط `properties['x-pattern']` مصرف
 * می‌شود. نوع عمداً محلی است تا لایهٔ داده به کامپوننت وابسته نشود.
 */
export type SiteBlockSchema = {
  properties?: Record<string, { default?: unknown; enum?: unknown }> | null;
};
export type SiteBlockSchemas = Record<string, SiteBlockSchema>;

export type SiteChrome = {
  title: string;
  description: string;
  logo_url?: string | null;
  favicon_url?: string | null;
  phone?: string | null;
  email?: string | null;
  /** نشانی پایه سایت (canonical/OG) — از تنظیمات سئو. */
  site_url?: string | null;
  og_image_url?: string | null;
  ai_summary?: string | null;
  robots_index?: boolean;
  /** WF-H1 — متن سفارشی robots.txt (null = پیش‌فرضِ حساس: اجازه + sitemap). */
  robots_txt?: string | null;
  /** WF-H1 — کد تأیید مالکیت Google Search Console (متای سر همهٔ صفحات). */
  google_site_verification?: string | null;
  address?: string | null;
  /** WF-M20 — متن سیاست حریم خصوصی برای صفحهٔ /privacy (بدون HTML). */
  privacy_policy?: string | null;
  /** WF-M17 — متن بیانیهٔ دسترس‌پذیری برای صفحهٔ /accessibility (بدون HTML). */
  accessibility_statement?: string | null;
  locale?: string | null;
  /** ECO2 — زبان‌های موجودِ سایت (زبانِ اصلی اول). تک‌زبانه ⇒ یک عضو. */
  locales?: string[] | null;
  /** ECO2 — زبانی که در ریشهٔ سایت سرو می‌شود. */
  primary_locale?: string | null;
  timezone?: string | null;
  /** کار دوم (افزودنی): صفحه خانه تنظیم‌شده + اسلاگ منتشرشده آن. */
  homepage_page_id?: number | null;
  homepage_slug?: string | null;
  header: LayoutData;
  footer: LayoutData;
  socials: SocialItem[];
  /**
   * F4.1.F — حالتِ رنگیِ سایت.
   *
   * ⚠️ `/v1/site/chrome` هنوز این را پر نمی‌کند (فقط `globals` را می‌دهد)، پس
   * اختیاری است و `app/preview` آن را دفاعی می‌خواند. اگر روزی پر شد، بدون
   * هیچ تغییری در فرانت کار می‌کند.
   */
  mode?: "light" | "dark" | "system" | null;
  theme?: { name: string; slug: string; version: string; globals?: Record<string, string> } | null;
  /** K6.12 — رجیستری بلوک افزونه‌های فعال (schema-only، بدون JS). */
  blocks?: SiteBlockSchemas;
  /**
   * WF-M13 — دامنهٔ دارایی/CDN برای فایل‌های ایستای محلی (بدون اسلش پایانی).
   * خالی/نبود = بدون CDN (رفتار قبلی). عمداً روی فونت اثر ندارد — فونت همیشه
   * محلی است (قانون ضد CDN خارجی).
   */
  asset_domain?: string | null;
  /** WF-M13 — lazy-load تصاویر سایت (پیش‌فرض روشن). */
  lazy_load_enabled?: boolean | null;
  /** WF-M13 — preload فونت وزیرمتن در `<head>` (پیش‌فرض روشن). */
  font_preload_enabled?: boolean | null;
};

const CHROME_DEFAULTS: SiteChrome = {
  title: "وب‌سایت من",
  description: "",
  header: { widgets: [{ type: "logo", settings: {} }, { type: "nav", settings: {} }], layout: {} },
  footer: {
    widgets: [{ type: "about", settings: {} }, { type: "copyright", settings: {} }],
    layout: {},
  },
  socials: [],
};

/** فهرست سبک صفحات منتشرشده (sitemap.xml و llms.txt) — بدون بلوک‌ها. */
export type SitePageIndex = {
  title: string;
  slug: string;
  is_single?: boolean;
  published_at?: string | null;
  updated_at?: string | null;
};

export function fetchSitePages(locale?: string): Promise<SitePageIndex[]> {
  const qs = locale ? `?locale=${encodeURIComponent(locale)}` : "";
  return get<SitePageIndex[]>(`/v1/site/pages${qs}`, [SITE_TAG]).then((v) => v ?? []);
}

/* ── WF-C7 — بایگانی بلاگ ── */

export const BLOG_TAG = "blog";

/** نوشتهٔ سبک در فهرست/فید بلاگ (بدون بلوک). */
export type BlogPostItem = {
  title: string;
  slug: string;
  excerpt?: string | null;
  image_url?: string | null;
  author?: string | null;
  category?: string | null;
  tags?: string[];
  published_at?: string | null;
  updated_at?: string | null;
};

/** E64 — `image_url` محلیِ کارت‌های بلاگ را با پایهٔ رسانه مطلق می‌کند. */
function rebaseBlogMedia(items: BlogPostItem[]): BlogPostItem[] {
  const base = assetBaseUrl(null);
  if (!base) return items;
  return items.map((item) =>
    typeof item.image_url === "string" ? { ...item, image_url: applyMediaBase(base, item.image_url) } : item,
  );
}

/** فهرست صفحه‌بندی‌شدهٔ نوشته‌های بلاگ (فقط published — تضمین سمت بک‌اند). */
export function fetchBlogPosts(
  locale: string,
  opts: { page?: number; perPage?: number; category?: string; tag?: string } = {},
): Promise<Paginator<BlogPostItem>> {
  const params = new URLSearchParams({ locale });
  if (opts.page) params.set("page", String(opts.page));
  if (opts.perPage) params.set("per_page", String(opts.perPage));
  if (opts.category) params.set("category", opts.category);
  if (opts.tag) params.set("tag", opts.tag);
  return get<unknown>(`/v1/site/blog?${params.toString()}`, [SITE_TAG, BLOG_TAG])
    .then((v) => asPaginator<BlogPostItem>(v))
    .then((page) => ({ ...page, data: rebaseBlogMedia(page.data) }));
}

/**
 * تک‌نوشتهٔ بلاگ: اول اسلاگ خام، بعد `blog/{slug}` (سازگاری با نصب‌هایی که
 * اسلاگ را با پیشوند `blog/` ذخیره کرده‌اند).
 */
export async function fetchBlogPost(slug: string, locale: string): Promise<SitePage | null> {
  const direct = await fetchSitePage(slug, locale).catch(() => null);
  if (direct) return direct;
  return fetchSitePage(`blog/${slug}`, locale).catch(() => null);
}

/** فید RSS بک‌اند عیناً برگردانده می‌شود تا یک منبعِ حقیقت بماند. */
export async function fetchBlogFeedXml(locale?: string): Promise<string | null> {
  const cached = await siteCacheEnabled();
  const qs = locale ? `?locale=${encodeURIComponent(locale)}` : "";
  try {
    const res = await fetch(`${UPSTREAM}/v1/site/blog/feed${qs}`, {
      headers: { Accept: "application/rss+xml, application/xml, text/xml" },
      ...(cached
        ? { next: { tags: [SITE_TAG, BLOG_TAG], revalidate: SITE_REVALIDATE } }
        : { cache: "no-store" as const }),
    });
    if (!res.ok) return null;
    return await res.text();
  } catch {
    return null;
  }
}

/* ── WF-M19 — فید RSS از همهٔ محتوای منتشرشده ── */

export const FEED_TAG = "feed";

export type { FeedItem } from "@/lib/public/rss-feed";

/**
 * WF-M19 — آیتم‌های فید از endpoint عمومی `site/feed` (همهٔ صفحات منتشرشده).
 *
 * برخلاف `fetchSitePages` این یکی خلاصه و تاریخِ انتشار دارد؛ برخلاف
 * `fetchBlogPosts` به بلاگ محدود نیست.
 *
 * `null` یعنی بک‌اند در دسترس نیست (fail-soft: فیدِ خالی + `no-store`) و با
 * «سایت هیچ محتوای منتشرشده‌ای ندارد» فرق دارد — دومی آرایهٔ خالی است و کش می‌شود.
 */
export function fetchSiteFeedItems(locale?: string): Promise<FeedItem[] | null> {
  const qs = locale ? `?locale=${encodeURIComponent(locale)}` : "";
  return get<FeedItem[]>(`/v1/site/feed${qs}`, [SITE_TAG, FEED_TAG]).then((v) =>
    Array.isArray(v) ? v : null,
  );
}

/** کدام فید؟ ریشه (`/feed.xml`) یا مسیرِ زبان‌دار (`/[locale]/blog/feed.xml`). */
export type SiteFeedKind = "root" | "localized";

/**
 * WF-M19 — XML فید سراسری (همهٔ محتوای منتشرشده، جدیدترین اول).
 *
 * دامنه از تنظیماتِ سایت (`site_url`) خوانده می‌شود و فقط به `origin` درخواست
 * fallback دارد، تا لینک‌های فید هرگز میزبانِ داخلیِ API را لو ندهند.
 * `null` فقط یعنی بک‌اند در دسترس نیست (فیدِ خالی برمی‌گردد، نه ۵۰۰).
 */
export async function buildSiteFeedXml(opts: {
  locale?: PublicLocale;
  origin?: string | null;
  kind?: SiteFeedKind;
}): Promise<string | null> {
  const locale = opts.locale ?? DEFAULT_PUBLIC_LOCALE;

  const [items, chrome] = await Promise.all([
    fetchSiteFeedItems(locale),
    fetchSiteChrome().catch(() => null),
  ]);
  if (items === null) return null;

  const base = siteBaseUrl(chrome) ?? opts.origin ?? null;
  const primaryRaw = chrome?.primary_locale;
  const primary = isPublicLocale(primaryRaw) ? primaryRaw : DEFAULT_PUBLIC_LOCALE;
  const self = (opts.kind ?? "root") === "localized" ? blogFeedHref(locale, primary) : "/feed.xml";

  return buildRssFeed({
    baseUrl: base,
    locale,
    items,
    channel: { title: chrome?.title ?? "", description: chrome?.description ?? "", self },
  });
}

/** صفحه خانه عمومی (کار دوم): homepage_page_id ← home ← جدیدترین؛ null = چیزی منتشر نشده. F4.5: locale اختیاری. */
export function fetchHomepage(locale?: string): Promise<SitePage | null> {
  const siteId = effectiveValue("PISHDAD_SITE_ID", process.env.NEXT_PUBLIC_SITE_ID);
  const params = new URLSearchParams();
  if (siteId) params.set("site_id", siteId);
  if (locale) params.set("locale", locale);
  const qs = params.toString();
  return get<SitePage>(`/v1/site/homepage${qs ? `?${qs}` : ""}`, [SITE_TAG, "site-homepage"]).then(
    rebasePageMedia,
  );
}

/**
 * WF-H15 — واکشی صفحهٔ خانه با `fallback` زبان (هم‌منطق با
 * `fetchSitePageFallback`): اولین زبانی که خانه در آن موجود است برمی‌گردد.
 */
export async function fetchHomepageFallback(
  chain: readonly string[],
): Promise<{ page: SitePage; locale: string } | null> {
  for (const loc of chain) {
    const page = await fetchHomepage(loc).catch(() => null);
    if (page) return { page, locale: loc };
  }
  return null;
}

/** نشانی پایه سایت: اولویت با تنظیمات (site_url)، بعد env، بعد null. */
export function siteBaseUrl(chrome: Pick<SiteChrome, "site_url"> | null): string | null {
  const fromSettings = chrome?.site_url?.trim().replace(/\/+$/, "");
  if (fromSettings) return fromSettings;
  const fromEnv = effectiveValue("PISHDAD_SITE_URL", process.env.NEXT_PUBLIC_SITE_URL).replace(/\/+$/, "");
  return fromEnv || null;
}

/**
 * WF-M13 — دامنهٔ دارایی/CDN. اول تنظیمات (`asset_domain`)، بعد
 * `NEXT_PUBLIC_ASSET_URL` به‌عنوان fallbackِ محیطی. خالی ⇒ null (بدون CDN).
 *
 * E64 — اگر هیچ‌کدام نبود، **پایهٔ رسانهٔ زمانِ اجرا** می‌آید. بدونِ این،
 * نشانی‌های محلیِ `/storage/…` که بک‌اند می‌فرستد به میزبانِ فرانت می‌چسبیدند
 * و ۴۰۴ می‌دادند؛ این همان چیزی است که `domain.ts::mediaUrl` از قبل درست
 * انجام می‌داد و این‌جا هم باید یکی باشد.
 */
export function assetBaseUrl(chrome: Pick<SiteChrome, "asset_domain"> | null): string | null {
  return (
    resolveAssetBase(chrome) ??
    normalizeAssetBase(effectiveValue("PISHDAD_ASSET_URL", process.env.NEXT_PUBLIC_ASSET_URL)) ??
    mediaBaseUrl()
  );
}

/**
 * E64 — پایهٔ زمانِ اجرا برای رسانهٔ محلی (`/storage/…` که بک‌اند سرو می‌کند).
 *
 * همان مقداری که `runtime-config.ts` در `__CMS_CONFIG__.mediaUrl` تزریق می‌کند
 * و `domain.ts::mediaUrl` مصرف می‌کند. این‌جا هم از مسیرِ نصب (`runtime.json`)
 * و سپس محیط خوانده می‌شود تا رندرِ **سمتِ سرور** (که به `window` دسترسی
 * ندارد) هم پایه را بداند.
 */
export function mediaBaseUrl(): string | null {
  return normalizeAssetBase(
    effectiveValue("PISHDAD_PUBLIC_MEDIA_URL", process.env.NEXT_PUBLIC_MEDIA_URL),
  );
}

/**
 * E64 — بلوک‌ها و متا/سایدبارِ یک صفحه را با پایهٔ رسانه مطلق می‌کند.
 *
 * چرا این‌جا و نه در رندرر: `BlockRenderer` سمتِ سرور رندر می‌شود و پایهٔ
 * زمانِ اجرا (نصب ← `PISHDAD_PUBLIC_MEDIA_URL`) را نمی‌داند؛ این لایه هم آن را
 * می‌بیند و هم پیش از sanitizer اجرا می‌شود.
 */
function rebasePageMedia(page: SitePage | null): SitePage | null {
  if (!page) return page;
  const base = assetBaseUrl(null);
  if (!base) return page;

  let meta = page.meta;
  if (meta && typeof meta === "object" && !Array.isArray(meta)) {
    const copy: Record<string, unknown> = { ...(meta as Record<string, unknown>) };
    if (typeof copy.og_image_url === "string") {
      copy.og_image_url = applyMediaBase(base, copy.og_image_url);
    }
    meta = copy;
  }

  const sidebars = page.sidebars
    ? { left: rebaseSidebar(page.sidebars.left, base), right: rebaseSidebar(page.sidebars.right, base) }
    : page.sidebars;

  return { ...page, blocks: rebaseBlockMedia(base, page.blocks), meta, sidebars };
}

function rebaseSidebar(sb: SidebarData | null | undefined, base: string): SidebarData | null | undefined {
  if (!sb) return sb;
  return { ...sb, blocks: rebaseBlockMedia(base, sb.blocks) };
}

/** WF-M13 — lazy-load تصاویر سایت روشن است مگر صریحاً خاموش شود. */
export function siteLazyLoadEnabled(chrome: Pick<SiteChrome, "lazy_load_enabled"> | null): boolean {
  return chrome?.lazy_load_enabled !== false;
}

/** WF-M13 — preload فونت روشن است مگر صریحاً خاموش شود. */
export function siteFontPreloadEnabled(chrome: Pick<SiteChrome, "font_preload_enabled"> | null): boolean {
  return chrome?.font_preload_enabled !== false;
}
/** F4.1.F — توکن/چیدمان یک قالبِ مشخص (برای `/preview/{slug}`، حتی غیرفعال). */
export type SiteThemeColorway = {  key: string;
  name: string;
  light: Record<string, string>;
  dark: Record<string, string>;
};

export type SiteThemeTokens = {
  name: string;
  slug: string;
  version?: string;
  layout?: string | null;
  globals?: Record<string, string>;
  builtin?: boolean;
  /** توکن‌های چیدمان (شعاع/فونت/تراکم). */
  layout_tokens?: Record<string, string>;
  /** رنگ‌بندی‌های پیشنهادیِ قالب (هرکدام دو نیمهٔ روشن/تیره). */
  colorways?: SiteThemeColorway[];
  default_colorway?: string | null;
};

export function fetchSiteTheme(slug: string): Promise<SiteThemeTokens | null> {
  return get<SiteThemeTokens>(`/v1/site/theme/${encodeURIComponent(slug)}`, [SITE_TAG, `theme:${slug}`]);
}

/** کروم واقعی سایت از اندپوینت عمومی (سبک، بدون احراز هویت) + fallback به پیش‌فرض‌ها وقتی خالی است. */
export async function fetchSiteChrome(): Promise<SiteChrome> {
  const siteId = effectiveValue("PISHDAD_SITE_ID", process.env.NEXT_PUBLIC_SITE_ID);
  const qs = siteId ? `?site_id=${encodeURIComponent(siteId)}` : "";
  const live = await get<SiteChrome>(`/v1/site/chrome${qs}`, [SITE_TAG, "site-chrome"]);
  if (!live) return CHROME_DEFAULTS;
  const merged: SiteChrome = {
    ...CHROME_DEFAULTS,
    ...live,
    header: live.header?.widgets ? live.header : CHROME_DEFAULTS.header,
    footer: live.footer?.widgets ? live.footer : CHROME_DEFAULTS.footer,
    socials: Array.isArray(live.socials) ? live.socials : [],
  };
  // WF-M13 — دارایی‌های رسانه‌ایِ برند (لوگو/فاوآیکون/OG) روی پایه سرو شوند.
  // E71 — فقط `/storage/…` (رسانهٔ بک‌اند) پایه می‌گیرد؛ داراییِ فرانت
  // (`/pishdad-logo.png`، `/favicon.ico`) روی خودِ فرانت سرو می‌شود و بردن‌شان
  // به میزبانِ بک‌اند ۴۰۴ می‌داد. MinIO/S3 مطلق دست‌نخورده است.
  const base = assetBaseUrl(merged);
  return {
    ...merged,
    logo_url: applyMediaBase(base, merged.logo_url ?? null),
    favicon_url: applyMediaBase(base, merged.favicon_url ?? null),
    og_image_url: applyMediaBase(base, merged.og_image_url ?? null),
  };
}

/**
 * WF-H2 — پیش‌نمایشِ پیش‌نویس با توکنِ اشتراک.
 *
 * عمومی است (بدون احراز هویت) ولی نه کش‌شدنی و نه ایندکس‌شدنی: توکن خودش
 * مجوز است و سرور فقط نسخهٔ همان revision را با `noindex` برمی‌گرداند.
 * `null` یعنی توکن نامعتبر/منقضی/لغو‌شده یا صفحه/نسخه یافت نشد.
 */
export async function fetchSharedPreview(token: string): Promise<SitePage | null> {
  try {
    const res = await fetch(`${UPSTREAM}/v1/site/share/${encodeURIComponent(token)}`, {
      headers: { Accept: "application/json" },
      cache: "no-store",
    });
    if (!res.ok) return null;
    const json = await res.json().catch(() => ({}));
    return ((json as { data?: unknown })?.data ?? json) as SitePage;
  } catch {
    return null;
  }
}
