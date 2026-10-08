/**
 * WF-H19 — منطق خالص داشبورد ناشر («ناشر من»).
 *
 * جدا از کامپوننت تا `npm test` (که فقط `*.test.ts` را می‌خواند) بتواند
 * نرمال‌سازی پاسخ را بسنجد: پاسخِ ناقص نباید UI را با عدد/ردیفِ جعلی نشان دهد.
 */

export type PublisherReviewStatus = "pending" | "approved" | "rejected" | "unverified" | null;

export type PublisherProfile = {
  id: number;
  name: string;
  slug: string;
  key_fingerprint: string;
  status: string;
  verified_at: string | null;
};

export type PublisherPlugin = {
  id: number;
  name: string;
  slug: string;
  version: string;
  previous_version: string | null;
  price: number;
  currency: string;
  review_status: PublisherReviewStatus;
  review_note: string | null;
  yanked: boolean;
  yank_reason: string | null;
  active: boolean;
  submitted_at: string | null;
  reviewed_at: string | null;
};

export type PublisherSales = {
  currency: string;
  gross: number;
  platform_fee: number;
  publisher_share: number;
  payout: number;
  unsettled: number;
  sales_count: number;
};

export type PublisherPayout = {
  id: number;
  publisher_key_id: number;
  amount: number;
  currency: string;
  status: string;
  note: string | null;
  paid_at: string | null;
  created_at: string | null;
};

export type PublisherDashboard = {
  publishers: PublisherProfile[];
  plugins: PublisherPlugin[];
  sales: PublisherSales;
  payouts: PublisherPayout[];
};

const EMPTY_SALES: PublisherSales = {
  currency: "IRT",
  gross: 0,
  platform_fee: 0,
  publisher_share: 0,
  payout: 0,
  unsettled: 0,
  sales_count: 0,
};

function str(v: unknown, fallback = ""): string {
  return typeof v === "string" ? v : fallback;
}

function num(v: unknown, fallback = 0): number {
  return typeof v === "number" && Number.isFinite(v) ? v : fallback;
}

function reviewStatus(v: unknown): PublisherReviewStatus {
  return v === "pending" || v === "approved" || v === "rejected" || v === "unverified" ? v : null;
}

function asProfiles(raw: unknown): PublisherProfile[] {
  if (!Array.isArray(raw)) return [];
  return raw
    .map((x): PublisherProfile | null => {
      if (!x || typeof x !== "object") return null;
      const o = x as Record<string, unknown>;
      if (typeof o.id !== "number") return null;
      return {
        id: o.id,
        name: str(o.name, str(o.slug, "ناشر")),
        slug: str(o.slug),
        key_fingerprint: str(o.key_fingerprint),
        status: str(o.status, "active"),
        verified_at: typeof o.verified_at === "string" ? o.verified_at : null,
      };
    })
    .filter((x): x is PublisherProfile => x !== null);
}

function asPlugins(raw: unknown): PublisherPlugin[] {
  if (!Array.isArray(raw)) return [];
  return raw
    .map((x): PublisherPlugin | null => {
      if (!x || typeof x !== "object") return null;
      const o = x as Record<string, unknown>;
      if (typeof o.id !== "number" || typeof o.slug !== "string") return null;
      return {
        id: o.id,
        name: str(o.name, o.slug),
        slug: o.slug,
        version: str(o.version, "0.0.0"),
        previous_version: typeof o.previous_version === "string" ? o.previous_version : null,
        price: num(o.price),
        currency: str(o.currency, "IRT"),
        review_status: reviewStatus(o.review_status),
        review_note: typeof o.review_note === "string" ? o.review_note : null,
        yanked: o.yanked === true,
        yank_reason: typeof o.yank_reason === "string" ? o.yank_reason : null,
        active: o.active === true,
        submitted_at: typeof o.submitted_at === "string" ? o.submitted_at : null,
        reviewed_at: typeof o.reviewed_at === "string" ? o.reviewed_at : null,
      };
    })
    .filter((x): x is PublisherPlugin => x !== null);
}

function asSales(raw: unknown): PublisherSales {
  if (!raw || typeof raw !== "object") return { ...EMPTY_SALES };
  const o = raw as Record<string, unknown>;
  return {
    currency: str(o.currency, "IRT"),
    gross: num(o.gross),
    platform_fee: num(o.platform_fee),
    publisher_share: num(o.publisher_share),
    payout: num(o.payout),
    unsettled: num(o.unsettled),
    sales_count: num(o.sales_count),
  };
}

function asPayouts(raw: unknown): PublisherPayout[] {
  if (!Array.isArray(raw)) return [];
  return raw
    .map((x): PublisherPayout | null => {
      if (!x || typeof x !== "object") return null;
      const o = x as Record<string, unknown>;
      if (typeof o.id !== "number") return null;
      return {
        id: o.id,
        publisher_key_id: num(o.publisher_key_id),
        amount: num(o.amount),
        currency: str(o.currency, "IRT"),
        status: str(o.status, "pending"),
        note: typeof o.note === "string" ? o.note : null,
        paid_at: typeof o.paid_at === "string" ? o.paid_at : null,
        created_at: typeof o.created_at === "string" ? o.created_at : null,
      };
    })
    .filter((x): x is PublisherPayout => x !== null);
}

/** پاسخ ناقص ⇒ ساختار خالی/محافظه‌کارانه، نه کرش و نه دادهٔ جعلی. */
export function asPublisherDashboard(raw: unknown): PublisherDashboard {
  const root = (raw as { data?: unknown } | null)?.data ?? raw;
  const o = (root && typeof root === "object" ? root : {}) as Record<string, unknown>;
  return {
    publishers: asProfiles(o.publishers),
    plugins: asPlugins(o.plugins),
    sales: asSales(o.sales),
    payouts: asPayouts(o.payouts),
  };
}
