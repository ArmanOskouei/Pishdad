/**
 * F3 — لایه داده سایت عمومی (ISR).
 * همه فراخوانی‌ها بدون احراز هویت‌اند (endpointهای public بک‌اند)؛
 * کش با تگ `pages` تا Route Handler امن revalidate باطلش کند.
 */

import type { LayoutData, SitePage, SiteStatus, SocialItem } from "@/lib/domain";

const UPSTREAM =
  process.env.INTERNAL_API_URL ??
  process.env.NEXT_PUBLIC_API_URL ??
  "http://localhost:8080/api";

export const SITE_REVALIDATE = 300; // ثانیه — ISR معقول برای صفحات عمومی
export const SITE_TAG = "pages";

async function get<T>(path: string, tags: string[]): Promise<T | null> {
  try {
    const res = await fetch(`${UPSTREAM}${path}`, {
      headers: { Accept: "application/json" },
      next: { tags, revalidate: SITE_REVALIDATE },
    });
    if (!res.ok) return null;
    const json = await res.json().catch(() => ({}));
    return ((json as { data?: unknown })?.data ?? json) as T;
  } catch {
    return null;
  }
}

/** صفحه منتشرشده (فقط published — تضمین سمت بک‌اند). */
export function fetchSitePage(slug: string): Promise<SitePage | null> {
  return get<SitePage>(`/v1/site/pages/${encodeURIComponent(slug)}`, [SITE_TAG, `page:${slug}`]);
}

/** وضعیت اشتراک برای رندر تعلیق خودکار (۱.۱۶). null = نامشخص/سرویس پایین. */
export function fetchSiteStatus(siteId: string): Promise<SiteStatus | null> {
  return get<SiteStatus>(`/v1/site/status?site_id=${encodeURIComponent(siteId)}`, [SITE_TAG, "site-status"]);
}

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
  address?: string | null;
  locale?: string | null;
  timezone?: string | null;
  /** کار دوم (افزودنی): صفحه خانه تنظیم‌شده + اسلاگ منتشرشده آن. */
  homepage_page_id?: number | null;
  homepage_slug?: string | null;
  header: LayoutData;
  footer: LayoutData;
  socials: SocialItem[];
  theme?: { name: string; slug: string; version: string; globals?: Record<string, string> } | null;
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

export function fetchSitePages(): Promise<SitePageIndex[]> {
  return get<SitePageIndex[]>(`/v1/site/pages`, [SITE_TAG]).then((v) => v ?? []);
}

/** صفحه خانه عمومی (کار دوم): homepage_page_id ← home ← جدیدترین؛ null = چیزی منتشر نشده. */
export function fetchHomepage(): Promise<SitePage | null> {
  const siteId = process.env.NEXT_PUBLIC_SITE_ID ?? "";
  const qs = siteId ? `?site_id=${encodeURIComponent(siteId)}` : "";
  return get<SitePage>(`/v1/site/homepage${qs}`, [SITE_TAG, "site-homepage"]);
}
/** نشانی پایه سایت: اولویت با تنظیمات (site_url)، بعد env، بعد null. */
export function siteBaseUrl(chrome: Pick<SiteChrome, "site_url"> | null): string | null {
  const fromSettings = chrome?.site_url?.trim().replace(/\/+$/, "");
  if (fromSettings) return fromSettings;
  const fromEnv = (process.env.NEXT_PUBLIC_SITE_URL ?? "").trim().replace(/\/+$/, "");
  return fromEnv || null;
}
/** کروم واقعی سایت از اندپوینت عمومی (سبک، بدون احراز هویت) + fallback به پیش‌فرض‌ها وقتی خالی است. */
export async function fetchSiteChrome(): Promise<SiteChrome> {
  const siteId = process.env.NEXT_PUBLIC_SITE_ID ?? "";
  const qs = siteId ? `?site_id=${encodeURIComponent(siteId)}` : "";
  const live = await get<SiteChrome>(`/v1/site/chrome${qs}`, [SITE_TAG, "site-chrome"]);
  if (!live) return CHROME_DEFAULTS;
  return {
    ...CHROME_DEFAULTS,
    ...live,
    header: live.header?.widgets ? live.header : CHROME_DEFAULTS.header,
    footer: live.footer?.widgets ? live.footer : CHROME_DEFAULTS.footer,
    socials: Array.isArray(live.socials) ? live.socials : [],
  };
}
