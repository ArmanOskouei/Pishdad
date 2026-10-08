/**
 * رجیستری سراسری جستجوی پنل ادمین + ایندکس ایستای هسته.
 *
 * ## B5 — وضعیت امروز
 *
 * قبلاً قرارداد افزونه این بود:
 *   `window.__ADMIN_SEARCH__.registerSearchSource({ plugin, pages, capabilities, fetchItems })`
 *
 * که `fetchItems` یک تابع بود و افزونه از داخلش **از مرورگر** به
 * `/api/proxy/v1/admin/...` می‌زد. این یعنی افزونه می‌توانست هر مسیر دلخواهی
 * از API هسته را صدا بزند — دقیقاً همان چیزی که K1.5.8 می‌خواست جلویش گرفته
 * شود، و بدتر: هر نتیجه یک درخواست جدا از مرورگر بود.
 *
 * حالا مسیر داده‌ای از راه سرور است: `GET /v1/admin/search?q=…`. افزونه فقط
 * یک پیاده‌سازی `SearchableProvider` اعلام می‌کند و سرور آن را اجرا می‌کند.
 *
 * ## چه چیزی هنوز اینجاست
 *
 * **ایندکس ایستا** — فهرست صفحات و قابلیت‌های پنل. داده حساسی ندارد (فقط
 * عنوان و مسیر صفحاتی که کاربر همین حالا می‌بیند) و مسئولیت امنیتی ندارد،
 * پس ماندنش اشکالی ندارد. حذف کامل آن کار جداگانه‌ای است چون سه مصرف‌کننده
 * دارد و ارزشش صرفاً UX است.
 *
 * `fetchItems` از تایپ حذف شده تا **دوباره** کسی آن را اضافه نکند.
 */

import { normalizeFa, queryTokens } from "./search-normalize";
import { searchReady } from "./search-timing.ts";

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
  /**
   * ❌ عمداً وجود ندارد.
   *
   * قبلاً `fetchItems?: (q: string) => Promise<SearchHit[]>` بود و افزونه از
   * داخلش به `/api/proxy/v1/admin/...` می‌زد. حذف شد چون یعنی افزونه می‌توانست
   * هر مسیر دلخواهی از API هسته را از مرورگر صدا بزند.
   *
   * جایگزین: پیاده‌سازی `App\Search\SearchableProvider` را از راه نقطهٔ
   * `core.service_provider` اعلام کنید تا **سرور** نتایج را برگرداند.
   */
};

export type SearchSource = Required<Pick<RegisterPayload, "plugin">> &
  Pick<RegisterPayload, "pages" | "capabilities">;

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
  // F4.2.F — صندوق و ترجیحات. ترجیحات یک ردیفِ جدا است، نه `capability` صندوق:
  // «ترجیح اعلان» عبارتی است که کاربر واقعاً می‌نویسد و اگر فقط capability
  // صندوق بود، جستجویش هیچ نتیجه‌ای نمی‌داد.
  { title: "اعلان‌ها", path: "/admin/notifications", icon: "◔", group: "other", subtitle: "اعلان‌های امنیتی، صورتحساب و افزونه‌ها", capabilities: ["صندوق اعلان", "خوانده‌نشده", "اعلان امنیتی"] },
  { title: "ترجیحات اعلان", path: "/admin/notifications/preferences", icon: "◑", group: "other", subtitle: "کانال هر گروه: ایمیل، پیامک، پوش", capabilities: ["ترجیح اعلان", "کانال اعلان", "پوش", "پیامک", "ایمیل"] },
  { title: "پروفایل من", path: "/admin/profile", icon: "◌", group: "other", subtitle: "نام، رمز و ورود دوعاملی", capabilities: ["تغییر رمز", "ورود دوعاملی", "اطلاعات کاربری"] },
  { title: "هدر و فوتر", path: "/admin/header-footer", icon: "☰", group: "content", subtitle: "ویجت‌های سربرگ و پاصفحه", capabilities: ["منوی سایت", "لوگوی هدر", "ویجت فوتر"] },
  { title: "بلوک‌های سایت", path: "/admin/blocks", icon: "▦", group: "content", subtitle: "بلوک‌های پیش‌فرض انواع صفحه", capabilities: ["بلوک صفحه", "چیدمان صفحه"] },
  { title: "قالب سایت", path: "/admin/themes", icon: "◫", group: "content", subtitle: "انتخاب و فعال‌سازی قالب", capabilities: ["قالب جدید", "فعال‌سازی قالب", "پیش‌نمایش قالب"] },
  { title: "قالب پنل", path: "/admin/appearance", icon: "◐", group: "other", subtitle: "رنگ و ظاهر پنل مدیریت", capabilities: ["تم تیره", "رنگ پنل", "حالت روشن"] },
  { title: "پلاگین‌ها", path: "/admin/plugins", icon: "⬡", group: "other", subtitle: "فعال‌سازی افزونه‌ها", capabilities: ["افزونه", "فعال‌سازی پلاگین", "آپدیت پلاگین"] },
  { title: "تنظیمات سایت", path: "/admin/settings", icon: "⚙", group: "other", subtitle: "تغییر عنوان، لوگو و فاوآیکون از این صفحه", capabilities: ["عنوان سایت", "لوگوی سایت", "فاوآیکون", "توضیحات سایت"] },
  { title: "شبکه‌های اجتماعی", path: "/admin/socials", icon: "🔗", group: "other", subtitle: "لینک اینستاگرام، تلگرام و سایر شبکه‌ها", capabilities: ["اینستاگرام", "تلگرام", "لینک شبکه"] },
];

const CORE_CAPABILITIES = ["جستجوی پنل", "میانبر صفحات", "راهنمای قابلیت‌ها"];

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
  // سورس‌های داینامیک دیگر از راه رجیستری ثبت نمی‌شوند — از endpoint
  // سمت سرور می‌آیند. ثبت ایستای آن‌ها فقط برای برچسب قابلیت‌ها می‌ماند.
  if (!has("core-pages")) {
    api.registerSearchSource({ plugin: "core-pages", capabilities: ["برگه‌های ساخته کاربر"] });
  }
  if (!has("core-media")) {
    api.registerSearchSource({ plugin: "core-media", capabilities: ["جستجو در نام فایل"] });
  }
  if (!has("core-tickets")) {
    api.registerSearchSource({ plugin: "core-tickets", capabilities: ["جستجو در موضوع تیکت"] });
  }
}

/**
 * نتایج داینامیک از سرور (B5).
 *
 * تنها نقطهٔ مجاز برای دادهٔ رکوردی. قبلاً سه تابع جدا (`fetchUserPages`،
 * `fetchMedia`، `fetchTickets`) هرکدام مستقل به API می‌زدند؛ حالا یک درخواست
 * به `AdminSearchController` می‌رود که providerهای ثبت‌شده — از جمله
 * provider افزونه — را در سرور اجرا می‌کند.
 */
export async function fetchServerSearch(q: string, perPage = 10): Promise<SearchHit[]> {
  const { authed } = await import("./auth");
  const json = await authed<{ data?: SearchHit[] }>(`/v1/admin/search?q=${encodeURIComponent(q)}&per_page=${perPage}`);
  const rows = Array.isArray(json.data) ? json.data : [];

  return rows.map((r) => ({
    ...r,
    // مسیر از سرور می‌آید ولی باید داخل پنل باشد؛ مسیر بیرونی از افزونه
    // پذیرفته نمی‌شود چون به بیرون از پنل لینک می‌سازد.
    path: typeof r.path === "string" && r.path.startsWith("/admin") ? r.path : "/admin",
  }));
}

/* ── موتور جستجو ── */

export type RunSearchOpts = {
  /** سقف نتایج (دراپ‌داون ۸، صفحه نتایج نامحدود). */
  limit?: number;
  /** شامل داینامیک‌ها هم بشود؟ (پیش‌فرض true) */
  dynamic?: boolean;
};

export async function runSearch(rawQuery: string, opts: RunSearchOpts = {}): Promise<SearchHit[]> {
  ensureBuiltInSources();
  const tokens = queryTokens(rawQuery);
  if (tokens.length === 0) return [];
  const { limit = 50, dynamic = true } = opts;
  const sources = getSearchSources();

  const scored: Array<{ hit: SearchHit; score: number }> = [];
  for (const s of sources) {
    for (const p of s.pages ?? []) {
      const score = scoreStatic(p, tokens);
      if (score > 0) scored.push({ hit: staticToHit(p, s.plugin), score });
    }
  }

  let dyn: SearchHit[] = [];
  // E78 — گیتِ سطحِ موتور: زیر ۳ حرف هیچ ریکوئستی نمی‌زند، حتی اگر
  // مصرف‌کننده‌ای گیتِ UI را جا انداخته باشد.
  if (dynamic && searchReady(rawQuery)) {
    // B5: یک درخواست به سرور، به‌جای یکی به‌ازای هر سورس. سرور providerهای
    // ثبت‌شده (از جمله provider افزونه) را اجرا و ادغام می‌کند.
    dyn = await fetchServerSearch(rawQuery.trim(), limit).catch(() => [] as SearchHit[]);
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
