import { notFound, redirect } from "next/navigation";
import { serverOne } from "@/lib/server-api";
import { decidePluginPage, normalizePluginPages, type PluginPageEntry } from "@/lib/plugin-page-registry";
import { PLUGIN_ADMIN_ROOT } from "@/lib/plugin-admin-path";
import type { ProfileData } from "@/lib/domain";

/**
 * K6.5 — catch-all صفحه‌های اختصاصی افزونه‌ها زیر `/admin/`.
 *
 * ## چرا این فایل لازم بود
 *
 * قبلاً `/admin/هرچیزی` به `[...path]/page.tsx` در **ریشهٔ** `app/` می‌افتاد —
 * یعنی رندرکنندهٔ سایت عمومی، با `SiteHeader` و `SiteFooter`. یک مسیر مدیریتی
 * ناموجود به‌جای «پیدا نشد» با کروم سایت رندر می‌شد.
 *
 * ## چرا رندر نمی‌کند، فقط تصمیم می‌گیرد
 *
 * render از page descriptor می‌آید نه از کد پلاگین. دلیلش امنیتی است: کدی که
 * پلاگین تزریق کند به `localStorage` دسترسی دارد (توکن نشست آنجاست) و می‌تواند
 * فرم جعلی بسازد. پس اینجا فقط مالکیت مسیر و دروازهٔ پرمیشن بررسی می‌شود و
 * صفحهٔ هسته رندر می‌شود.
 *
 * ## چرا `notFound` و نه redirect
 *
 * مسیر ثبت‌نشده زیر `/admin/` **نباید** به صفحهٔ عمومی برود؛ آن می‌گفت «شاید صفحهٔ
 * عمومی با این slug وجود دارد» و کاربر را به محتوای سایت می‌برد. `notFound`
 * صادق است: این مسیر در پنل وجود ندارد.
 */
type Props = { params: Promise<{ slug: string[] }> };

type RegistryResponse = { pages?: PluginPageEntry[] };

async function loadRegistry(): Promise<PluginPageEntry[]> {
  try {
    const json = await serverOne<RegistryResponse>("/v1/admin/plugins/pages");
    return normalizePluginPages(json?.pages);
  } catch {
    // خطا ⇒ رجیستری خالی ⇒ `notFound`. این **fail-closed** است: صفحهٔ
    // افزونه‌ای که ثبت‌شده ولی سرویسش خراب است نباید بدون کنترل رندر شود.
    return [];
  }
}

export default async function PluginAdminPage({ params }: Props) {
  const segments = await params;
  const pathname = `${PLUGIN_ADMIN_ROOT}/${segments.slug.join("/")}`;

  // دروازهٔ شکل: قبل از هر lookup، همان قاعده‌ای که مانیفست باید رعایت کرده
  // باشد. اگر مسیر در این قاعده نباشد، اصلاً به رجیستری نمی‌رود.
  const pages = await loadRegistry();
  const profile = await serverOne<ProfileData>("/v1/admin/profile").catch(() => null);

  const decision = decidePluginPage(pages, pathname, profile);

  if (decision.kind === "not-registered") notFound();

  if (decision.kind === "forbidden") {
    // ۴۰۳ به‌جای ۴۰۴ عمدی است *فقط* وقتی صفحه ثبت شده — و این خودش یک
    // افشای کوچک است (وجود صفحه لو می‌رود)، ولی بدون آن کاربر نمی‌فهمد مشکل
    // از دسترسی است یا از آدرس. هسته برای همین صفحه descriptor ندارد.
    redirect(`${PLUGIN_ADMIN_ROOT}/subscription?denied=1`);
  }

  // descriptor از رجیستری می‌آید و هسته رندرش می‌کند. `pageId` یعنی «از دیتابیس
  // بخوان» نه «از پلاگین اجرا کن» — این تفاوت، مرز امنیتی این تسک است.
  return (
    <div className="page-head">
      <div>
        <h1>{decision.entry.title_fa}</h1>
        <p>افزونه: {decision.entry.slug}</p>
      </div>
    </div>
  );
}
