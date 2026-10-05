/**
 * L-B12 — «اشتراک من» اعلانی شد: داده از افزونه، رندر از هسته.
 *
 * ## چرا این فایل وجود دارد
 *
 * صفحهٔ `/admin/subscription` قبلاً پنج route مستقیمِ هسته را می‌خواند و
 * خلاصهٔ وضعیت را با JSX دستی می‌ساخت. حالا داده از catch-all افزونه‌ها
 * می‌آید (`/v1/p/subscription/*`) و خلاصه به `BlockValue[]` اعلانی تبدیل
 * می‌شود که همان `BlockRenderer` هسته رندرش می‌کند — الگوی K7.18/K7.19
 * (`<h1>{title}</h1>` + بلوک‌ها، بدون `next/dynamic`، بدون کد پلاگین در
 * مرورگر، بدون import از vendor).
 *
 * ## چه چیزی اینجا نیست (عمداً)
 *
 * - هیچ JSX و هیچ import از `@/…`: تست‌ها با `node --test` و import نسبی
 *   اجرا می‌شوند و alias حل نمی‌شود (همان قاعدهٔ `menu.ts` و `block-type.ts`).
 * - هیچ ادعایی دربارهٔ نوع‌های `metric/table/grid` مانیفست افزونه: آن‌ها در
 *   واژگان بستهٔ هسته نیستند (`isBuiltinBlockType`) پس این سازنده فقط
 *   `hero/text/quote` می‌دهد — تنها چیزی که `BlockRenderer` واقعاً رندر می‌کند.
 * - پرداخت/ارتقا (کنش، نه نمایش) همچنان جزیره‌های تعاملی `PayFlow` و
 *   `PlanCompare`‌اند؛ این فایل فقط «نمایش» را اعلانی می‌کند.
 */

import type { BlockValue, Invoice, MySubscription } from "./domain.ts";

/* ── مسیرها: نو اول، قدیمی fallback ──
 *
 * routeهای قدیمی `/v1/admin/subscription*` فقط بازآدایه‌اند و هدر
 * `Deprecation: true` + `Link: <…>; rel="successor-version"` می‌فرستند
 * (`routes/api.php`). ترتیب تلاش همیشه «نو، بعد قدیمی» است تا در نصبِ
 * بدون افزونه (افزونهٔ خصوصی روی دیسک نیست) همان قدیمی جواب بدهد.
 */
export const SUBSCRIPTION_API = {
  show: "/v1/p/subscription/show",
  invoices: "/v1/p/subscription/invoices",
  plans: "/v1/p/subscription/plans",
  changePlan: "/v1/p/subscription/change-plan",
  pay: (id: number) => `/v1/p/subscription/invoices/${id}/pay`,
} as const;

export const LEGACY_SUBSCRIPTION_API = {
  show: "/v1/admin/subscription",
  invoices: "/v1/admin/subscription/invoices",
  plans: "/v1/admin/subscription/plans",
  changePlan: "/v1/admin/subscription/change-plan",
  pay: (id: number) => `/v1/admin/subscription/invoices/${id}/pay`,
} as const;

/**
 * استخراج مسیر جانشین از هدر `Link` پاسخِ قدیمی.
 *
 * شکل بک‌اند: `</api/v1/p/subscription/show>; rel="successor-version"`.
 * خروجی مسیر نسبی `/v1/…` است (بدون پیشوند `/api`) تا مستقیم به همان
 * `fetch`‌های موجود بخورد. هر چیز دیگری ⇒ `null` (fail-closed: حدس نمی‌زنیم).
 */
export function parseSuccessorVersion(link: string | null | undefined): string | null {
  if (typeof link !== "string" || link === "") return null;
  const m = /<([^>]+)>\s*;\s*rel="successor-version"/.exec(link);
  if (!m) return null;
  const path = m[1]!.replace(/^\/api(?=\/)/, "");
  return path.startsWith("/v1/p/subscription/") ? path : null;
}

/** ورودیِ ازپیش‌قالب‌بندی‌شده (اعداد/تاریخ‌ها فارسی شده در Server Component). */
export type SubscriptionSummaryInput = {
  planName: string;
  tierFa: string;
  statusFa: string;
  dueFa: string;
  daysFa: string;
  unpaidFa: string;
  suspended: boolean;
  unpaidCount: number;
};

/**
 * خلاصهٔ اشتراک به‌صورت بلوک‌های اعلانی — فقط واژگان هسته.
 *
 * `suspended` یک بلوک `quote` اضافه می‌کند (همان متن بنر قفل، ولی به‌عنوان
 * داده نه JSX). فهرست خالی فاکتور، بلوکی تولید نمی‌کند — «نبودِ فاکتور»
 * اشکال نیست و نباید بنر بگیرد.
 */
export function subscriptionSummaryBlocks(input: SubscriptionSummaryInput): BlockValue[] {
  const blocks: BlockValue[] = [
    {
      type: "hero",
      data: { title: input.planName, subtitle: `${input.statusFa} — ${input.tierFa}` },
    },
    {
      type: "text",
      data: {
        body: `<p>سررسید: <b>${input.dueFa}</b> (${input.daysFa})</p><p>فاکتور پرداخت‌نشده: <b>${input.unpaidFa}</b></p>`,
      },
    },
  ];
  if (input.suspended) {
    blocks.push({
      type: "quote",
      data: {
        text: "اشتراک شما معلق است — سایت مسدود و پنل فقط-پرداخت است. با پرداخت فاکتور، تعلیق خودکار رفع می‌شود.",
      },
    });
  }
  return blocks;
}

/** آیا فاکتور پرداخت‌نشده‌ای هست (برای تصمیم نمایش `PayFlow`، نه رندر). */
export function hasUnpaidInvoice(invoices: readonly Pick<Invoice, "status">[]): boolean {
  return invoices.some((i) => i?.status === "pending");
}

/** استخراج امن فهرست فاکتور از پاسخ paginator یا آرایهٔ خام. */
export function asInvoices(json: unknown): Invoice[] {
  const d = (json as { data?: unknown } | null)?.data ?? json;
  if (Array.isArray(d)) return d as Invoice[];
  const inner = (d as { data?: unknown } | null)?.data;
  if (Array.isArray(inner)) return inner as Invoice[];
  return [];
}

/** نگه‌دارندهٔ نوع برای صفحه: هیچ‌جا `MySubscription` خام به بلوک‌ساز نمی‌رسد. */
export type { MySubscription };
