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

export type StoreItem = {
  id: number;
  name: string;
  slug: string;
  version: string;
  description?: string | null;
  price: number;
  currency: string;
  delivery: DeliveryStatus;
};

/** یک ردیف کاتالوگ که فیلدهای لازم را دارد؟ — برای دفاع در برابر پاسخ ناقص. */
export function asStoreItem(raw: unknown): StoreItem | null {
  if (!raw || typeof raw !== "object") return null;
  const o = raw as Record<string, unknown>;
  const delivery = (o.delivery ?? {}) as Record<string, unknown>;
  if (typeof o.id !== "number" || typeof o.slug !== "string") return null;

  return {
    id: o.id,
    name: typeof o.name === "string" ? o.name : o.slug,
    slug: o.slug,
    version: typeof o.version === "string" ? o.version : "0.0.0",
    description: typeof o.description === "string" ? o.description : null,
    price: typeof o.price === "number" ? o.price : 0,
    currency: typeof o.currency === "string" ? o.currency : "IRT",
    delivery: {
      owned: delivery.owned === true,
      delivered: delivery.delivered === true,
      deliverable: delivery.deliverable === true,
      checksum: typeof delivery.checksum === "string" ? delivery.checksum : null,
    },
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
