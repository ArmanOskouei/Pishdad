/**
 * WF-M19 — سازندهٔ فید RSS 2.0 از **همهٔ** محتوای منتشرشده.
 *
 * عمداً کمکیِ خالص است (بدون React/Next و بدون `fetch`) تا با `node --test`
 * بدون resolver هم سنجیده شود؛ روت داده را می‌دهد و این فقط XML می‌سازد.
 *
 * قراردادهای سخت:
 *
 * - **فقط آیتمِ قابل‌انتشار**: `status` غایب یا `published`، نه `is_private`، و
 *   لینکِ قابل‌ساخت. بقیه بی‌صدا حذف می‌شوند — لایهٔ دفاعیِ روی قفل‌های بک‌اند.
 * - **ترتیب**: جدیدترین `published_at` اول؛ بعد `updated_at`؛ بعد ترتیبِ ورودی
 *   (پایدار، تا دو آیتم با تاریخِ برابر جابه‌جا نشوند).
 * - **URL**: همیشه از دامنهٔ تنظیم‌شدهٔ سایت ساخته می‌شود. `url` خامِ بک‌اند فقط
 *   وقتی استفاده می‌شود که دامنه‌ای نداشته باشیم — وگرنه در نصبِ بدون `site_url`
 *   میزبانِ داخلیِ API داخل فید لو می‌رفت.
 * - `guid` همیشه permalink است و `<language>` همیشه پر می‌شود (RSS معتبر).
 *
 * ⚠️ import نسبیِ `.ts` عمدی است (هم‌خط `blog.ts`): `node --test` بدون resolver
 * اجرا می‌شود و specifier بدون پسوند resolve نمی‌شود.
 */
import { DEFAULT_PUBLIC_LOCALE, type PublicLocale } from "../i18n/public/index.ts";

/** سقفِ آیتمِ فید — هم‌حدِ `FEED_PAGE_LIMIT` سمت بک‌اند (دو سور یک عدد). */
export const FEED_ITEM_LIMIT = 50;

/** یک آیتمِ خامِ فید (همان شکلی که `GET /v1/site/feed` می‌دهد). */
export type FeedItem = {
  title?: string | null;
  slug?: string | null;
  /** مسیرِ نسبی و آگاه به زبان (بدون دامنه) — منبعِ حقیقتِ مسیر. */
  path?: string | null;
  /** مسیرِ مطلقِ همان صفحه؛ فقط وقتی دامنه‌ای در دست نباشد استفاده می‌شود. */
  url?: string | null;
  summary?: string | null;
  page_type?: string | null;
  status?: string | null;
  is_private?: boolean | null;
  published_at?: string | null;
  updated_at?: string | null;
};

export type FeedChannel = {
  title?: string | null;
  link?: string | null;
  description?: string | null;
  /** مسیر یا نشانیِ مطلقِ خودِ فید (برای `atom:link rel="self"`). */
  self?: string | null;
};

/** نویسه‌هایی که XML 1.0 اصلاً اجازه‌شان نیست (وگرنه کلِ سند بد‌خوان می‌شود). */
const INVALID_XML_CHARS = /[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g;
const ABSOLUTE_HTTP = /^https?:\/\//i;

export function xmlEscape(value: string | null | undefined): string {
  const raw = typeof value === "string" ? value : "";

  return raw
    .replace(INVALID_XML_CHARS, "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&apos;");
}

export function normalizeBase(baseUrl?: string | null): string {
  const raw = typeof baseUrl === "string" ? baseUrl.trim() : "";

  return raw.replace(/\/+$/, "");
}

export function feedLanguage(locale: PublicLocale): string {
  return locale === "en" ? "en" : "fa";
}

/**
 * لینکِ مطلقِ آیتم. `null` یعنی هیچ لینکِ قابل‌اعتمادی نیست و آیتم باید از فید
 * حذف شود — نه اینکه به «ریشهٔ سایت» قلاب شود (که لینک‌های تکراری می‌ساخت).
 *
 * وقتی دامنه‌ای داریم، `url` خامِ بک‌اند **هرگز** برنده نمی‌شود: در نصبِ بدون
 * `site_url` همان میزبانِ داخلیِ API است و لو دادنش یعنی فیدِ خراب. `url` فقط
 * وقتی مصرف می‌شود که دامنه‌ای در دست نباشد.
 */
export function feedItemUrl(item: FeedItem, baseUrl?: string | null): string | null {
  const base = normalizeBase(baseUrl);
  const rawUrl = typeof item.url === "string" ? item.url.trim() : "";
  const rawPath = typeof item.path === "string" ? item.path.trim() : "";
  // مسیرِ نسبی: `path` اول؛ وگرنه `url` اگر خودش مسیرِ سایت باشد.
  const relative = rawPath !== "" ? rawPath : rawUrl.startsWith("/") ? rawUrl : "";
  const bare = relative.replace(/^\/+/, "").replace(/\/+$/, "");
  const absolute = ABSOLUTE_HTTP.test(rawUrl) ? rawUrl : null;

  if (bare !== "") {
    return base === "" ? absolute : `${base}/${bare}`;
  }

  if (base !== "") {
    return relative !== "" ? `${base}/` : null;
  }

  return absolute;
}

/** دروازهٔ انتشار: غایب = منتشرشده (بک‌اند فرستاده)، ولی غیرمنتشر/خصوصی نه. */
export function isFeedItemPublic(item: FeedItem): boolean {
  const status = typeof item.status === "string" ? item.status.trim().toLowerCase() : "";

  if (status !== "" && status !== "published") {
    return false;
  }

  return item.is_private !== true;
}

/** ISO → RFC 2822 (همان چیزی که RSS لازم دارد). دادهٔ خراب ⇒ null. */
export function rfc2822(value?: string | null): string | null {
  const raw = typeof value === "string" ? value.trim() : "";

  if (raw === "") {
    return null;
  }

  const time = Date.parse(raw);

  return Number.isFinite(time) ? new Date(time).toUTCString() : null;
}

function feedSortTime(item: FeedItem): number {
  const raw = item.published_at ?? item.updated_at ?? null;
  const time = typeof raw === "string" ? Date.parse(raw) : Number.NaN;

  return Number.isFinite(time) ? time : Number.NEGATIVE_INFINITY;
}

function plainText(value: string): string {
  return value.replace(/\s+/g, " ").trim();
}

function selfHref(self: string | null | undefined, base: string): string | null {
  const raw = typeof self === "string" ? self.trim() : "";

  if (raw === "") {
    return null;
  }

  if (ABSOLUTE_HTTP.test(raw)) {
    return raw;
  }

  const bare = raw.replace(/^\/+/, "").replace(/\/+$/, "");

  return base === "" ? null : `${base}/${bare}`;
}

/**
 * WF-M19 — XML نهایی فید. آرایهٔ خالی هم یک فیدِ معتبر می‌دهد (فید نباید سایت
 * را بشکند)، پس روت می‌تواند fail-soft تصمیم بگیرد.
 */
export function buildRssFeed(input: {
  channel?: FeedChannel;
  items?: readonly FeedItem[] | null;
  baseUrl?: string | null;
  locale?: PublicLocale;
  limit?: number;
}): string {
  const locale = input.locale ?? DEFAULT_PUBLIC_LOCALE;
  const base = normalizeBase(input.baseUrl);
  const limit = Math.max(0, Math.floor(input.limit ?? FEED_ITEM_LIMIT));
  const channel = input.channel ?? {};

  const rows = (input.items ?? [])
    .map((item, index) => ({ item, index, url: feedItemUrl(item, base) }))
    .filter((row) => isFeedItemPublic(row.item) && row.url !== null)
    .sort((a, b) => feedSortTime(b.item) - feedSortTime(a.item) || a.index - b.index)
    .slice(0, limit);

  let lastBuild: string | null = null;
  const items = rows
    .map(({ item, url }) => {
      const link = (url as string).trim();
      const title = plainText(item.title ?? "") || link;
      const summary = plainText(item.summary ?? "");
      const pub = rfc2822(item.published_at ?? item.updated_at);

      if (pub !== null && lastBuild === null) {
        lastBuild = pub;
      }

      return [
        "  <item>",
        `    <title>${xmlEscape(title)}</title>`,
        `    <link>${xmlEscape(link)}</link>`,
        `    <guid isPermaLink="true">${xmlEscape(link)}</guid>`,
        ...(pub === null ? [] : [`    <pubDate>${xmlEscape(pub)}</pubDate>`]),
        ...(summary === "" ? [] : [`    <description>${xmlEscape(summary)}</description>`]),
        "  </item>",
      ].join("\n");
    })
    .join("\n");

  const channelLink = normalizeBase(channel.link ?? "") || `${base}/`;
  const self = selfHref(channel.self, base);
  const selfTag = self === null ? "" : `  <atom:link href="${xmlEscape(self)}" rel="self" type="application/rss+xml" />\n`;
  const lastBuildTag = lastBuild === null ? "" : `  <lastBuildDate>${xmlEscape(lastBuild)}</lastBuildDate>\n`;

  return (
    '<?xml version="1.0" encoding="UTF-8"?>\n' +
    '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">\n' +
    "<channel>\n" +
    `  <title>${xmlEscape(plainText(channel.title ?? "") || "وب‌سایت")}</title>\n` +
    `  <link>${xmlEscape(channelLink)}</link>\n` +
    `  <description>${xmlEscape(plainText(channel.description ?? ""))}</description>\n` +
    `  <language>${xmlEscape(feedLanguage(locale))}</language>\n` +
    selfTag +
    lastBuildTag +
    (items === "" ? "" : items + "\n") +
    "</channel>\n" +
    "</rss>"
  );
}