/**
 * ECO6 — منطق خالص فروشگاه (کاتالوگ + checkout + تحویل).
 *
 * چرا جدا از کامپوننت: `npm test` فقط روی `*.test.ts` اجرا می‌شود و TSX را
 * نمی‌خواند. منطقی که «آیا این آیتم قابل دانلود است؟» را تعیین می‌کند باید
 * بدون مرورگر قابل سنجش باشد — وگرنه دکمه‌ای که ۴۰۹ می‌دهد بی‌سروصدا می‌ماند.
 */

import { faNum } from "./fa.ts";

/** وضعیت تحویل که بک‌اند در `delivery` برمی‌گرداند (DeliveryService). */
export type DeliveryStatus = {
  owned: boolean;
  delivered: boolean;
  deliverable: boolean;
  checksum: string | null;
};

export type StoreReviewStatus = "pending" | "approved" | "rejected" | null;

export type StoreVersion = {
  version: string;
  changelog: string | null;
  released_at: string | null;
  yanked: boolean;
};

/** WF-H18 — یک نظرِ تأییدشدهٔ خریدار. */
export type StoreReview = {
  id: number;
  rating: number;
  comment: string | null;
  author: string | null;
  created_at: string | null;
};

export type StoreItem = {
  id: number;
  name: string;
  slug: string;
  version: string;
  description?: string | null;
  price: number;
  currency: string;
  active: boolean;
  review_status: StoreReviewStatus;
  delivery: DeliveryStatus;
  /** WF-H16 — دستهٔ افزونه از مانیفست (nullable؛ هاردکد نمی‌شود). */
  category?: string | null;
  /** WF-H16 — تصاویر اعلام‌شده در مانیفست (خالی = چیزی اعلام نشده). */
  screenshots?: string[];
  /** WF-H16 — توضیح کامل (مانیفست، وگرنه ستون description). */
  long_description?: string | null;
  /** WF-H16 — متن تغییرات نسخهٔ جاری (مانیفست). */
  changelog?: string | null;
  /** WF-H18 — میانگین امتیازِ نظرهای تأییدشده (null = هنوز امتیازی نیست). */
  rating?: number | null;
  /** WF-H18 — شمار نظرهای تأییدشده. */
  rating_count?: number;
  /** WF-H18 — شمار نصب فعال (از لایسنس‌های فعال). */
  active_installs?: number;
};

export type StoreDetail = StoreItem & {
  versions: StoreVersion[];
  reviews: StoreReview[];
  can_review: boolean;
  has_reviewed: boolean;
  my_review_status?: StoreReviewStatus;
};

function asUrlList(raw: unknown): string[] {
  return Array.isArray(raw)
    ? raw.filter((x): x is string => typeof x === "string" && x.trim() !== "")
    : [];
}

export function asStoreVersions(raw: unknown): StoreVersion[] {
  if (!Array.isArray(raw)) return [];
  return raw
    .map((x): StoreVersion | null => {
      if (!x || typeof x !== "object") return null;
      const o = x as Record<string, unknown>;
      if (typeof o.version !== "string" || o.version === "") return null;
      return {
        version: o.version,
        changelog: typeof o.changelog === "string" ? o.changelog : null,
        released_at: typeof o.released_at === "string" ? o.released_at : null,
        yanked: o.yanked === true,
      };
    })
    .filter((x): x is StoreVersion => x !== null);
}

/** `meta.categories` — فهرست دسته‌های قابل فیلتر (خالی = دسته‌ای اعلام نشده). */
export function asStoreCategories(raw: unknown): string[] {
  const meta = (raw as { meta?: unknown } | null)?.meta;
  const cats = (meta as { categories?: unknown } | null)?.categories;
  return Array.isArray(cats)
    ? cats.filter((x): x is string => typeof x === "string" && x.trim() !== "")
    : [];
}

/** یک ردیف کاتالوگ که فیلدهای لازم را دارد؟ — برای دفاع در برابر پاسخ ناقص. */
export function asStoreItem(raw: unknown): StoreItem | null {
  if (!raw || typeof raw !== "object") return null;
  const o = raw as Record<string, unknown>;
  const delivery = (o.delivery ?? {}) as Record<string, unknown>;
  if (typeof o.id !== "number" || typeof o.slug !== "string") return null;

  const review = o.review_status;

  return {
    id: o.id,
    name: typeof o.name === "string" ? o.name : o.slug,
    slug: o.slug,
    version: typeof o.version === "string" ? o.version : "0.0.0",
    description: typeof o.description === "string" ? o.description : null,
    price: typeof o.price === "number" ? o.price : 0,
    currency: typeof o.currency === "string" ? o.currency : "IRT",
    active: o.active === true || o.active === 1,
    review_status:
      review === "pending" || review === "approved" || review === "rejected" ? review : null,
    delivery: {
      owned: delivery.owned === true,
      delivered: delivery.delivered === true,
      deliverable: delivery.deliverable === true,
      checksum: typeof delivery.checksum === "string" ? delivery.checksum : null,
    },
    category: typeof o.category === "string" && o.category.trim() !== "" ? o.category : null,
    screenshots: asUrlList(o.screenshots),
    long_description: typeof o.long_description === "string" ? o.long_description : null,
    changelog: typeof o.changelog === "string" ? o.changelog : null,
    rating: typeof o.rating === "number" ? o.rating : null,
    rating_count: typeof o.rating_count === "number" ? o.rating_count : 0,
    active_installs: typeof o.active_installs === "number" ? o.active_installs : 0,
  };
}

/** WF-H18 — نظرهای تأییدشده؛ ورودی بد/ناقص دور ریخته می‌شود، نه اینکه UI را بشکند. */
export function asStoreReviews(raw: unknown): StoreReview[] {
  if (!Array.isArray(raw)) return [];
  return raw
    .map((x): StoreReview | null => {
      if (!x || typeof x !== "object") return null;
      const o = x as Record<string, unknown>;
      if (typeof o.id !== "number") return null;
      const rating = typeof o.rating === "number" ? Math.round(o.rating) : 0;
      if (rating < 1 || rating > 5) return null;
      return {
        id: o.id,
        rating,
        comment: typeof o.comment === "string" && o.comment.trim() !== "" ? o.comment : null,
        author: typeof o.author === "string" && o.author.trim() !== "" ? o.author : null,
        created_at: typeof o.created_at === "string" ? o.created_at : null,
      };
    })
    .filter((x): x is StoreReview => x !== null);
}

/** جزئیاتِ یک آیتم + نسخه‌ها + نظرها؛ نبود هرکدام ⇒ مقدار محافظه‌کارانه، نه کرش. */
export function asStoreDetail(raw: unknown): StoreDetail | null {
  const item = asStoreItem(raw);
  if (!item) return null;
  const o = (raw ?? {}) as Record<string, unknown>;
  return {
    ...item,
    versions: asStoreVersions(o.versions),
    reviews: asStoreReviews(o.reviews),
    can_review: o.can_review === true,
    has_reviewed: o.has_reviewed === true,
    my_review_status:
      o.my_review_status === "pending" || o.my_review_status === "approved" || o.my_review_status === "rejected"
        ? o.my_review_status
        : null,
  };
}

export function asStoreList(raw: unknown): StoreItem[] {
  const rows = Array.isArray(raw)
    ? raw
    : Array.isArray((raw as { data?: unknown } | null)?.data)
      ? ((raw as { data: unknown[] }).data)
      : [];
  return rows.map(asStoreItem).filter((x): x is StoreItem => x !== null);
}

/**
 * کدام دکمه به کاربر نشان داده شود؟ تنها منبع حقیقت برای UI.
 *
 * - `delivered` ⇒ دکمه‌ای نیست، پیامِ «قبلاً تحویل شد» (تحویل یک‌باره).
 * - `deliverable` ⇒ دکمهٔ دانلود.
 * - رایگان و نداشته ⇒ «دریافت» (checkout روی قیمت صفر).
 * - پولی و نداشته ⇒ «خرید».
 */
export type StoreAction = "download" | "already_delivered" | "get" | "buy";

export function storeAction(item: StoreItem): StoreAction {
  if (item.delivery.delivered) return "already_delivered";
  if (item.delivery.deliverable) return "download";
  return item.price > 0 ? "buy" : "get";
}

/** قیمت با ارقام فارسی + واحد. صفر ⇒ «رایگان». */
export function formatPrice(price: number, currency: string): string {
  if (price <= 0) return "رایگان";
  const unit = currency === "IRT" ? "تومان" : currency;
  return `${faNum(price.toLocaleString("en-US"))} ${unit}`;
}

/** WF-H18 — امتیاز با ارقام فارسی و یک رقم اعشار؛ نبود ⇒ خط تیره (نه «۰»). */
export function formatRating(rating: number | null | undefined): string {
  if (rating === null || rating === undefined || Number.isNaN(rating)) return "—";
  return faNum(rating.toFixed(1));
}

/** WF-H18 — تعدادِ ستارهٔ پُر (۰..۵) برای نمایش؛ نصف به بالا گرد می‌شود. */
export function filledStars(rating: number | null | undefined): number {
  if (rating === null || rating === undefined || Number.isNaN(rating)) return 0;
  return Math.max(0, Math.min(5, Math.round(rating)));
}
