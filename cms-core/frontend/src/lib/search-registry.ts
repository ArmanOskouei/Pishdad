/**
 * رجیستری سراسری جستجوی پنل ادمین + ایندکس ایستا + سورس‌های داینامیک داخلی.
 *
 * قرارداد پلاگین (مستندات کامل: docs/SEARCH-PLUGIN-CONTRACT.md):
 *   window.__ADMIN_SEARCH__.registerSearchSource({ plugin, pages, capabilities, fetchItems? })
 *
 * سورس‌های داینامیک داخلی (صفحات/فایل‌ها/تیکت‌ها) هم از همین رجیستری
 * استفاده می‌کنند (dogfood) — هیچ مسیر ویژه‌ای برای هسته وجود ندارد.
 */

import { normalizeFa, queryTokens } from "./search-normalize";
import { asPaginator, type MediaItem, type PageItem, type Ticket } from "./domain";

/* ── تایپ‌ها ── */

export type SearchGroup = "pages" | "content" | "files" | "other";

export const SEARCH_GROUP_FA: Record<SearchGroup, string> = {
  pages: "صفحات",
  content: "محتوای سایت",
  files: "فایل‌ها",
  other: "سایر",
};

export const SEARCH_GROUP_ORDER: SearchGroup[] = ["pages", "content", "files", "other"];

/** یک برگه/قابلیت ایستا: عنوان + مسیر + آیکون + زیرنویس + مترادف‌ها. */
export type StaticPageEntry = {
  title: string;
  path: string;
  icon: string;
  group: SearchGroup;
  subtitle?: string;
  /** ۲-۴ مترادف/قابلیت فارسی — همان چیزی که کاربر تایپ می‌کند. */
  capabilities: string[];
};

export type SearchHit = {
  id: string;
  plugin: string;
  title: string;
  subtitle?: string;
  /** مسیر نمایشی فارسی (مثلاً «پنل › فایل‌ها») — پیش‌فرض از path ساخته می‌شود. */
  trail?: string;
  path: string;
  icon: string;
  group: SearchGroup;
};

export type RegisterPayload = {
  /** شناسه یکتای پلاگین (مثلاً "blog"). هسته از "core" استفاده می‌کند. */
  plugin: string;
  /** برگه‌های ایستا — هیچ داده حساسی (توکن/ایمیل/مبلغ) در این‌جا مجاز نیست. */
  pages?: StaticPageEntry[];
  /** قابلیت‌های کلی پلاگین (برای مستندسازی + امتیاز جزئی در تطبیق). */
  capabilities?: string[];
  /** سورس داینامیک: آیتم‌های رکوردی (پست‌ها، اعضا، …) با لینک مستقیم. */
  fetchItems?: (q: string) => Promise<SearchHit[]>;
};

export type SearchSource = Required<Pick<RegisterPayload, "plugin">> &
  Pick<RegisterPayload, "pages" | "capabilities" | "fetchItems">;

type RegistryApi = {
  sources: SearchSource[];
  registerSearchSource: (p: RegisterPayload) => () => void;
};

declare global {
  interface Window {
    __ADMIN_SEARCH__?: RegistryApi;
  }
}

function registry(): RegistryApi {
  if (typeof window === "undefined") {
    // SSR: رجیستری حافظه‌ای موقت (هایدریشن کلاینت دوباره ثبت می‌کند).
    return (globalThis as unknown as { __ssr?: RegistryApi }).__ssr ?? {
      sources: [],
      registerSearchSource: () => () => undefined,
    };
  }
  if (!window.__ADMIN_SEARCH__) {
    const sources: SearchSource[] = [];
    window.__ADMIN_SEARCH__ = {
      sources,
      registerSearchSource: (p: RegisterPayload) => {
        if (!p || typeof p.plugin !== "string" || !p.plugin) {
          throw new Error("registerSearchSource: فیلد plugin الزامی است.");
        }
        const entry: SearchSource = {
          plugin: p.plugin,
          pages: Array.isArray(p.pages) ? p.pages : [],
          capabilities: Array.isArray(p.capabilities) ? p.capabilities : [],
          fetchItems: typeof p.fetchItems === "function" ? p.fetchItems : undefined,
        };
        // ثبت مجدد همان پلاگین، نسخه قبلی را جایگزین می‌کند.
        const i = sources.findIndex((s) => s.plugin === entry.plugin);
        if (i >= 0) sources[i] = entry;
        else sources.push(entry);
        return () => {
          const j = sources.findIndex((s) => s.plugin === entry.plugin);
          if (j >= 0) sources.splice(j, 1);
        };
      },
    };
  }
  return window.__ADMIN_SEARCH__;
}

export function registerSearchSource(p: RegisterPayload): () => void {
  return registry().registerSearchSource(p);
}

export function getSearchSources(): SearchSource[] {
  if (typeof window !== "undefined" && window.__ADMIN_SEARCH__) return window.__ADMIN_SEARCH__.sources;
  return registry().sources;
}

/* ── ایندکس ایستای هسته (از SPEC صفحات استخراج شده؛ بدون داده حساس) ── */

const CORE_STATIC_PAGES: StaticPageEntry[] = [
  { title: "داشبورد", path: "/admin/dashboard", icon: "▦", group: "other", subtitle: "نمای کلی آمار و میانبرها", capabilities: ["نمای کلی", "آمار سایت", "میانبرها"] },
  { title: "صفحات", path: "/admin/pages", icon: "▤", group: "content", subtitle: "ساخت و ویرایش برگه‌های سایت", capabilities: ["صفحه جدید", "ویرایشگر بلوکی", "انتشار صفحه", "پیش‌نویس"] },
  { title: "فایل‌ها", path: "/admin/media", icon: "🗎", group: "files", subtitle: "آپلود و مدیریت عکس و فایل", capabilities: ["آپلود عکس", "آپلود فایل", "گالری تصاویر", "سطل زباله"] },
  { title: "تیکت‌ها", path: "/admin/tickets", icon: "✉", group: "other", subtitle: "گفتگو با پشتیبانی", capabilities: ["پشتیبانی", "تیکت جدید", "پاسخ تیکت"] },
  { title: "مدیران و نقش‌ها", path: "/admin/managers", icon: "◍", group: "other", subtitle: "افزودن مدیر و سطح دسترسی", capabilities: ["مدیر جدید", "نقش کاربری", "دسترسی‌ها"] },
  { title: "پروفایل من", path: "/admin/profile", icon: "◌", group: "other", subtitle: "نام، رمز و ورود دوعاملی", capabilities: ["تغییر رمز", "ورود دوعاملی", "اطلاعات کاربری"] },
  { title: "اشتراک من", path: "/admin/subscription", icon: "◈", group: "other", subtitle: "پلن، فاکتور و پرداخت", capabilities: ["فاکتور", "پرداخت", "تمدید اشتراک", "ارتقای پلن"] },
  { title: "هدر و فوتر", path: "/admin/header-footer", icon: "☰", group: "content", subtitle: "ویجت‌های سربرگ و پاصفحه", capabilities: ["منوی سایت", "لوگوی هدر", "ویجت فوتر"] },
  { title: "بلوک‌های سایت", path: "/admin/blocks", icon: "▦", group: "content", subtitle: "بلوک‌های پیش‌فرض انواع صفحه", capabilities: ["بلوک صفحه", "چیدمان صفحه"] },
  { title: "قالب سایت", path: "/admin/themes", icon: "◫", group: "content", subtitle: "انتخاب و فعال‌سازی قالب", capabilities: ["قالب جدید", "فعال‌سازی قالب", "پیش‌نمایش قالب"] },
  { title: "قالب پنل", path: "/admin/appearance", icon: "◐", group: "other", subtitle: "رنگ و ظاهر پنل مدیریت", capabilities: ["تم تیره", "رنگ پنل", "حالت روشن"] },
  { title: "پلاگین‌ها", path: "/admin/plugins", icon: "⬡", group: "other", subtitle: "فعال‌سازی افزونه‌ها", capabilities: ["افزونه", "فعال‌سازی پلاگین", "آپدیت پلاگین"] },
  { title: "تنظیمات سایت", path: "/admin/settings", icon: "⚙", group: "other", subtitle: "تغییر عنوان، لوگو و فاوآیکون از این صفحه", capabilities: ["عنوان سایت", "لوگوی سایت", "فاوآیکون", "توضیحات سایت"] },
  { title: "شبکه‌های اجتماعی", path: "/admin/socials", icon: "🔗", group: "other", subtitle: "لینک اینستاگرام، تلگرام و سایر شبکه‌ها", capabilities: ["اینستاگرام", "تلگرام", "لینک شبکه"] },
];

const CORE_CAPABILITIES = ["جستجوی پنل", "میانبر صفحات", "راهنمای قابلیت‌ها"];

/** فقط صفحه پرداخت در حالت تعلیق مجاز است (همان منطق ADMIN_MENU_LOCKED). */
export const LOCKED_VISIBLE_PATHS = ["/admin/subscription"];

/* ── تطبیق ── */

function tokenMatches(hay: string, token: string): boolean {
  if (!token) return true;
  if (hay.includes(token)) return true;
  // شروع‌کلمه: بعد از فاصله/ابتدا
  const words = hay.split(" ");
  return words.some((w) => w.startsWith(token));
}

function scoreStatic(e: StaticPageEntry, tokens: string[]): number {
  const title = normalizeFa(e.title);
  const sub = normalizeFa(e.subtitle ?? "");
  const caps = e.capabilities.map(normalizeFa);
  const path = normalizeFa(e.path);
  let score = 0;
  for (const t of tokens) {
    let s = 0;
    if (tokenMatches(title, t)) s = Math.max(s, title.startsWith(t) ? 12 : 10);
    for (const c of caps) if (tokenMatches(c, t)) s = Math.max(s, c.startsWith(t) ? 9 : 7);
    if (sub && tokenMatches(sub, t)) s = Math.max(s, 5);
    if (path.includes(t)) s = Math.max(s, 2);
    if (s === 0) return 0; // همه توکن‌ها باید بخورند (AND)
    score += s;
  }
  return score;
}

function staticToHit(e: StaticPageEntry, plugin: string): SearchHit {
  return {
    id: `${plugin}:${e.path}`,
    plugin,
    title: e.title,
    subtitle: e.subtitle ?? e.capabilities[0],
    path: e.path,
    icon: e.icon,
    group: e.group,
  };
}

/* ── سورس‌های داینامیک داخلی (کش ۶۰ثانیه‌ای سمت کلاینت) ── */

const dynCache = new Map<string, { at: number; data: SearchHit[] }>();
const DYN_TTL = 60_000;

async function cachedDyn(key: string, fetcher: () => Promise<SearchHit[]>): Promise<SearchHit[]> {
  const now = Date.now();
  const hit = dynCache.get(key);
  if (hit && now - hit.at < DYN_TTL) return hit.data;
  try {
    const data = await fetcher();
    dynCache.set(key, { at: now, data });
    return data;
  } catch {
    return hit?.data ?? [];
  }
}

async function fetchUserPages(q: string): Promise<SearchHit[]> {
  return cachedDyn(`pages:${q}`, async () => {
    const { authed } = await import("./auth");
    const json = await authed<unknown>(`/v1/admin/pages?per_page=10&search=${encodeURIComponent(q)}`);
    return asPaginator<PageItem>(json).data.map((p) => ({
      id: `core-pages:${p.id}`,
      plugin: "core-pages",
      title: p.title,
      subtitle: `برگه سایت • ${p.slug}`,
      path: `/admin/pages/${p.id}/edit`,
      icon: "▤",
      group: "pages" as SearchGroup,
    }));
  });
}

async function fetchMedia(q: string): Promise<SearchHit[]> {
  return cachedDyn(`media:${q}`, async () => {
    const { authed } = await import("./auth");
    const json = await authed<unknown>(`/v1/admin/media?per_page=10&search=${encodeURIComponent(q)}`);
    return asPaginator<MediaItem>(json).data.map((m) => ({
      id: `core-media:${m.id}`,
      plugin: "core-media",
      title: m.original_name,
      subtitle: "فایل کتابخانه",
      path: `/admin/media?search=${encodeURIComponent(m.original_name)}`,
      icon: "🗎",
      group: "files" as SearchGroup,
    }));
  });
}

async function fetchTickets(q: string): Promise<SearchHit[]> {
  return cachedDyn(`tickets:${q}`, async () => {
    const { authed } = await import("./auth");
    const json = await authed<unknown>(`/v1/admin/tickets?per_page=10&search=${encodeURIComponent(q)}`);
    return asPaginator<Ticket>(json).data.map((t) => ({
      id: `core-tickets:${t.id}`,
      plugin: "core-tickets",
      title: t.subject,
      subtitle: `تیکت #${t.id}`,
      path: `/admin/tickets?ticket=${t.id}`,
      icon: "✉",
      group: "other" as SearchGroup,
    }));
  });
}

/* ── dogfood: ثبت سورس‌های داخلی از همین رجیستری ── */

let builtinDone = false;

export function ensureBuiltInSources(): void {
  if (builtinDone) return;
  builtinDone = true;
  const api = registry();
  const has = (name: string) => api.sources.some((s) => s.plugin === name);
  if (!has("core")) {
    api.registerSearchSource({ plugin: "core", pages: CORE_STATIC_PAGES, capabilities: CORE_CAPABILITIES });
  }
  if (!has("core-pages")) {
    api.registerSearchSource({ plugin: "core-pages", capabilities: ["برگه‌های ساخته کاربر"], fetchItems: fetchUserPages });
  }
  if (!has("core-media")) {
    api.registerSearchSource({ plugin: "core-media", capabilities: ["جستجو در نام فایل"], fetchItems: fetchMedia });
  }
  if (!has("core-tickets")) {
    api.registerSearchSource({ plugin: "core-tickets", capabilities: ["جستجو در موضوع تیکت"], fetchItems: fetchTickets });
  }
}

/* ── موتور جستجو ── */

export type RunSearchOpts = {
  /** true = حالت تعلیق: فقط صفحه پرداخت + بدون سورس داینامیک. */
  locked?: boolean;
  /** سقف نتایج (دراپ‌داون ۸، صفحه نتایج نامحدود). */
  limit?: number;
  /** شامل داینامیک‌ها هم بشود؟ (پیش‌فرض true) */
  dynamic?: boolean;
};

export async function runSearch(rawQuery: string, opts: RunSearchOpts = {}): Promise<SearchHit[]> {
  ensureBuiltInSources();
  const tokens = queryTokens(rawQuery);
  if (tokens.length === 0) return [];
  const { locked = false, limit = 50, dynamic = true } = opts;
  const sources = getSearchSources();

  const scored: Array<{ hit: SearchHit; score: number }> = [];
  for (const s of sources) {
    for (const p of s.pages ?? []) {
      if (locked && !LOCKED_VISIBLE_PATHS.some((lp) => p.path === lp || p.path.startsWith(`${lp}/`))) continue;
      const score = scoreStatic(p, tokens);
      if (score > 0) scored.push({ hit: staticToHit(p, s.plugin), score });
    }
  }

  let dyn: SearchHit[] = [];
  if (dynamic && !locked && rawQuery.trim().length >= 2) {
    const jobs = sources
      .filter((s) => typeof s.fetchItems === "function")
      .map((s) => s.fetchItems!(rawQuery.trim()));
    const settled = await Promise.all(jobs.map((j) => j.catch(() => [] as SearchHit[])));
    dyn = settled.flat();
  }

  const seen = new Set(scored.map((s) => s.hit.id));
  const merged = [...scored.sort((a, b) => b.score - a.score).map((s) => s.hit)];
  for (const h of dyn) {
    if (!seen.has(h.id)) {
      seen.add(h.id);
      merged.push(h);
    }
  }
  return merged.slice(0, limit);
}

/** وضعیت تعلیق سمت کلاینت (کش ۶۰ثانیه‌ای) — همان منبع SuspensionGuard در layout. */
let lockCache: { at: number; locked: boolean } | null = null;

export async function getSubscriptionLock(): Promise<boolean> {
  const now = Date.now();
  if (lockCache && now - lockCache.at < DYN_TTL) return lockCache.locked;
  try {
    const { authed } = await import("./auth");
    const sub = await authed<{ status?: string }>("/v1/admin/subscription");
    lockCache = { at: now, locked: sub?.status === "suspended" };
  } catch {
    lockCache = { at: now, locked: false };
  }
  return lockCache.locked;
}

// ─────────────────────────────────────────────────────────────
// سورس نمایشی غیرفعال (نمونه قرارداد پلاگین — اجرا نمی‌شود):
//
// import { registerSearchSource } from "@/lib/search-registry";
// registerSearchSource({
//   plugin: "blog",
//   pages: [
//     {
//       title: "نوشته‌های بلاگ", path: "/admin/blog/posts", icon: "✎",
//       group: "content", subtitle: "مدیریت پست‌ها و دسته‌ها",
//       capabilities: ["پست جدید", "دسته‌بندی بلاگ", "انتشار پست"],
//     },
//   ],
//   capabilities: ["مدیریت نوشته‌ها"],
//   fetchItems: async (q) => {
//     const res = await fetch(`/api/proxy/v1/admin/blog/posts?per_page=8&search=${encodeURIComponent(q)}`, { cache: "no-store" });
//     const json = await res.json().catch(() => ({}));
//     const items = Array.isArray(json?.data) ? json.data : [];
//     return items.map((p: { id: number; title: string }) => ({
//       id: `blog:${p.id}`, plugin: "blog", title: p.title,
//       subtitle: "پست بلاگ", path: `/admin/blog/posts/${p.id}/edit`,
//       icon: "✎", group: "content" as const,
//     }));
//   },
// });
// ─────────────────────────────────────────────────────────────
