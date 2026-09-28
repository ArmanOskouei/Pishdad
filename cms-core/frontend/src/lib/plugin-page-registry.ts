/**
 * K6.5 — رجیستری صفحه‌های اختصاصی افزونه‌ها در فرانت.
 *
 * ## چه مشکلی را حل می‌کند
 *
 * تا پیش از این، `/admin/<هرچیزی>` به رندرکنندهٔ **سایت عمومی** می‌افتاد —
 * `[...path]/page.tsx` در ریشهٔ `app/` است و `SiteHeader` (`:119`) و
 * `SiteFooter` (`:137`) می‌راند. یعنی یک مسیر مدیریتی ناموجود به‌جای «پیدا
 * نشد»، با کروم سایت عمومی رندر می‌شد. تست K1.6.5 هم دقیقاً همین برداشت غلط
 * را در `docs/TASKS.md` ثبت کرده بود.
 *
 * ## چرا فقط مسیر، نه رندر
 *
 * قرارداد می‌گوید render از page descriptor می‌آید نه از کد پلاگین. اگر پلاگین
 * JS خودش را تزریق می‌کرد، به `localStorage` دسترسی داشت (توکن نشست آنجاست)
 * و می‌توانست فرم جعلی بسازد. پس این فایل فقط **مالکیت مسیر** را نگه می‌دارد
 * و تصمیم می‌گیرد چه چیزی رندر شود.
 */
import type { ProfileData } from "./domain.ts";
import { isPluginAdminPath } from "./plugin-admin-path.ts";

export type PluginPageEntry = {
  slug: string;
  path: string;
  title_fa: string;
  permission?: string | null;
  layout?: string;
};

export type PluginPageDecision =
  | { kind: "render"; entry: PluginPageEntry }
  | { kind: "forbidden"; entry: PluginPageEntry }
  | { kind: "not-registered" };

/** همان الگوی `admin.menu` — کلید مسیر باید یکتا باشد. */
export function normalizePluginPages(raw: unknown): PluginPageEntry[] {
  if (!Array.isArray(raw)) return [];

  const out: PluginPageEntry[] = [];
  const seen = new Set<string>();

  for (const item of raw) {
    if (item === null || typeof item !== "object") continue;
    const e = item as Record<string, unknown>;
    const slug = e.slug;

    // نقطهٔ انتهایی **قبل** از اعتبارسنجی حذف می‌شود. `isPluginAdminPath` با
    // الگوی `^/admin(/[a-z0-9._-]+)*$` نقطهٔ انتهایی را رد می‌کند (segment
    // آخر باید محتوا داشته باشد)، پس `/admin/a/` اگر بعد از اعتبارسنجی
    // نرمال می‌شد هرگز به دست نمی‌رسید و بی‌صدا حذف می‌شد — در حالی که
    // بک‌اند همان مسیر را نرمال و معتبر برمی‌گرداند.
    if (typeof e.path !== "string") continue;
    const path = e.path.replace(/\/+$/, "");

    // قاعدهٔ مسیر از `plugin-admin-path.ts` قرض گرفته می‌شود، نه اینجا
    // تکرار: دو الگوی جدا یعنی جایی که یکی رد کند و دیگری قبول.
    if (!isPluginAdminPath(path)) continue;
    if (typeof slug !== "string" || slug === "") continue;
    if (seen.has(path)) continue;

    seen.add(path);
    out.push({
      slug,
      path,
      title_fa: typeof e.title_fa === "string" && e.title_fa !== "" ? e.title_fa : path,
      permission: typeof e.permission === "string" ? e.permission : null,
      layout: typeof e.layout === "string" ? e.layout : "default",
    });
  }

  return out;
}

/**
 * بلندترین مسیرِ ثبت‌شده را پیدا می‌کند.
 *
 * چرا بلندترین: `/admin/shop/orders` و `/admin/shop` هر دو ثبت می‌شوند. اگر
 * `/admin/shop` جست‌وجو شود، `/admin/shop/orders` **نباید** برنده شود، وگرنه
 * مسیر ثبت‌شدهٔ کوتاه‌تر هیچ‌وقت رندر نمی‌شد. برعکس، برای `/admin/shop/orders/x`
 * هیچ ثبتی وجود ندارد و `notFound` درست است.
 */
export function matchPluginPage(
  pages: readonly PluginPageEntry[],
  pathname: string,
): PluginPageEntry | null {
  const target = pathname.replace(/\/+$/, "");
  let best: PluginPageEntry | null = null;

  for (const page of pages) {
    if (page.path === target) return page;
    if (target.startsWith(`${page.path}/`) && (best === null || page.path.length > best.path.length)) {
      best = page;
    }
  }

  return best;
}

/**
 * تصمیم نهایی رندر — شامل دروازهٔ پرمیشن.
 *
 * `forbidden` و `not-registered` عمداً جدا هستند: اولی یعنی «می‌دانیم این صفحه
 * هست ولی تو حق نداری» و دومی «چنین صفحه‌ای نیست». قاطی‌کردنشان یعنی افشای
 * وجود صفحه‌ای که کاربر نباید از آن خبر داشته باشد.
 *
 * پرمیشن خالی یعنی «هیچ» و عمداً هم allow است: افزونه‌ای که `permission`
 * اعلام نکرده، صفحه‌اش برای هر مدیر وارد‌شده باز است. این برعکس منو است که
 * آنجا هم `permission` غایب باز است ولی **دروازه‌اش fail-closed** است چون
 * دروازه روی خودِ آیتم است نه روی نبودِ پرمیشن.
 */
export function decidePluginPage(
  pages: readonly PluginPageEntry[],
  pathname: string,
  profile: ProfileData | null,
): PluginPageDecision {
  const entry = matchPluginPage(pages, pathname);

  if (entry === null) return { kind: "not-registered" };

  if (entry.permission) {
    const permissions = new Set(profile?.permissions ?? []);
    if (!permissions.has(entry.permission)) {
      return { kind: "forbidden", entry };
    }
  }

  return { kind: "render", entry };
}
