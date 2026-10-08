/**
 * جستجوی عمومی سایت (کلاینت + صفحه نتایج).
 * منبع پیش‌فرض: GET /api/v1/site/search?q=&per_page= → {title, slug, snippet, type, updated_at}
 * (فقط صفحات منتشرشده: عنوان + متن بلوک‌ها + متا).
 *
 * ── قرارداد provider پلاگین آینده ──
 * هر سورس جدید فقط یک provider اضافه می‌کند؛ هیچ تغییری در UI لازم نیست:
 *
 *   import { registerSiteSearchProvider } from "@/lib/site-search";
 *   registerSiteSearchProvider({
 *     key: "shop",
 *     label: "فروشگاه",
 *     search: async (q, limit) => {
 *       const res = await fetch(`/api/v1/site/shop/search?q=${encodeURIComponent(q)}&per_page=${limit}`);
 *       const json = await res.json().catch(() => ({}));
 *       const rows = Array.isArray(json?.data) ? json.data : [];
 *       return rows.map((r: { title: string; slug: string; snippet?: string; updated_at?: string }) => ({
 *         title: r.title, slug: r.slug, snippet: r.snippet ?? "",
 *         type: "shop", updated_at: r.updated_at ?? null,
 *       }));
 *     },
 *   });
 *
 * قوانین provider:
 * - فقط محتوای منتشرشده/عمومی برگردان (هرگز draft یا داده حساس).
 * - شکل هر هیت: {title, slug, snippet, type, updated_at}؛ type همان key است.
 * - خطا را throw نکن — آرایه خالی برگردان تا بقیه سورس‌ها کار کنند.
 */

import { API_BASE } from "./api";
import { searchReady } from "./search-timing.ts";

export type SiteSearchHit = {
  title: string;
  slug: string;
  snippet: string;
  type: string;
  updated_at?: string | null;
};

export type SiteSearchProvider = {
  /** کلید یکتا = همان type خروجی (مثلاً "page"). */
  key: string;
  /** برچسب فارسی بخش برای نمایش در کارت/دراپ‌داون. */
  label: string;
  search: (q: string, limit: number) => Promise<SiteSearchHit[]>;
};

export const SITE_SEARCH_TYPE_FA: Record<string, string> = {
  page: "صفحه",
};

async function pageProviderSearch(q: string, limit: number): Promise<SiteSearchHit[]> {
  try {
    const res = await fetch(
      `${API_BASE}/v1/site/search?q=${encodeURIComponent(q)}&per_page=${Math.max(1, Math.min(50, limit))}`,
      { headers: { Accept: "application/json" } },
    );
    if (!res.ok) return [];
    const json = await res.json().catch(() => ({}));
    const rows = Array.isArray((json as { data?: unknown })?.data)
      ? ((json as { data: unknown[] }).data as Record<string, unknown>[])
      : [];
    return rows
      .filter((r) => typeof r.title === "string" && typeof r.slug === "string")
      .map((r) => ({
        title: String(r.title),
        slug: String(r.slug),
        snippet: typeof r.snippet === "string" ? r.snippet : "",
        type: typeof r.type === "string" && r.type ? String(r.type) : "page",
        updated_at: typeof r.updated_at === "string" ? (r.updated_at as string) : null,
      }));
  } catch {
    return [];
  }
}

const providers: SiteSearchProvider[] = [
  { key: "page", label: "صفحه", search: pageProviderSearch },
];

/** ثبت سورس جدید (پلاگین آینده) — ثبت مجدد همان key جایگزین می‌شود. */
export function registerSiteSearchProvider(p: SiteSearchProvider): () => void {
  const i = providers.findIndex((s) => s.key === p.key);
  if (i >= 0) providers[i] = p;
  else providers.push(p);
  return () => {
    const j = providers.findIndex((s) => s.key === p.key);
    if (j >= 0) providers.splice(j, 1);
  };
}

export function siteSearchTypeFa(type: string): string {
  const p = providers.find((s) => s.key === type);
  if (p) return p.label;
  return SITE_SEARCH_TYPE_FA[type] ?? type;
}

/** اجرای همه providerها (موازی، تحمل خطا) — سقف limit روی مجموع. */
export async function runSiteSearch(q: string, limit = 10): Promise<SiteSearchHit[]> {
  const query = q.trim();
  // E78 — گیتِ سطحِ موتور: زیر ۳ حرف هیچ ریکوئستی نمی‌زند.
  if (!searchReady(query)) return [];
  const settled = await Promise.all(
    providers.map((p) => p.search(query, limit).catch(() => [] as SiteSearchHit[])),
  );
  const seen = new Set<string>();
  const out: SiteSearchHit[] = [];
  for (const list of settled) {
    for (const h of list) {
      const id = `${h.type}:${h.slug}`;
      if (seen.has(id)) continue;
      seen.add(id);
      out.push(h);
      if (out.length >= limit) return out;
    }
  }
  return out;
}
