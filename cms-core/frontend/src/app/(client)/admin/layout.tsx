import { Shell } from "@/components/layout/Shell";
import { AdminFavicon } from "@/components/layout/AdminFavicon";
import { SuspensionGuard } from "@/components/layout/SuspensionGuard";
import { ADMIN_MENU, ADMIN_MENU_LOCKED, mergePluginMenu, type PluginMenuDeclaration } from "@/lib/menu";
import { serverOne } from "@/lib/server-api";
import type { MySubscription, ProfileData, SiteSettings } from "@/lib/domain";

/**
 * K6.1 — آیتم‌های `admin.menu` که افزونه‌های فعال اعلام کرده‌اند.
 *
 * ⚠️ **این مقدار فقط وقتی پر است که کاربر وارد شده باشد.**
 *
 * داده از `/v1/admin/plugins/menu` می‌آید که پشت `auth:sanctum` است و
 * عمداً `perm:*` ندارد — اگر `perm:plugins.view` داشت، منوی هر مدیری که آن
 * پرمیشن را ندارد بی‌صدا خالی می‌شد و دروازهٔ `permission` در فرانت هرگز
 * امتحان نمی‌شد. به همین دلیل هم به `/v1/admin/plugins` وصل نشده: آن مسیر
 * صفحه‌بندی ۲۰تایی و `latest()` دارد (پس کدام آیتم دیده شود به تاریخ نصب
 * می‌رسید) و `present()` کل مانیفست را می‌فرستد.
 *
 * نکتهٔ امنیتی: پاسخ این مسیر فقط **هفت فیلد لازم برای یک آیتم منو** را
 * دارد — هیچ امضا، کلید عمومی یا فهرست جدولی لو نمی‌رود. فیلتر نهایی همچنان
 * سمت سرور و هنگام اجرای route افزونه انجام می‌شود؛ فیلتر فرانت صرفاً
 * تجربهٔ کاربری است و هیچ‌وقت جای مجوز واقعی را نمی‌گیرد.
 */
type PluginMenuResponse = { items?: PluginMenuDeclaration[] };

async function loadPluginMenu(): Promise<PluginMenuDeclaration[]> {
  try {
    const json = await serverOne<PluginMenuResponse>("/v1/admin/plugins/menu");
    // نبودن `items` یعنی «هیچ»، نه «همه» — همان قاعدهٔ ورودی خالی.
    return Array.isArray(json?.items) ? json.items : [];
  } catch {
    // خطا ⇒ منو دقیقاً مثل امروز. عمداً هیچ اعلانی به کاربر نمی‌دهیم: یک
    // بنر قرمز برای «منوی افزونه‌ها بارگذاری نشد» در هر صفحهٔ پنل، برای
    // اشکالی که خودش را در ۵ دقیقه درست می‌کند، آزاردهنده‌تر از سکوت است.
    return [];
  }
}

/** لایه‌بندی پنل مشترک (جهت سپیده) + قفل تعلیق (بنر + منوی محدود) + لوگو/فاوآیکون سایت. */
export default async function AdminLayout({ children }: { children: React.ReactNode }) {
  // این چهار قبلاً پشت‌سرهم `await` می‌شدند، یعنی هر صفحهٔ پنل چهار
  // رفت‌وبرگشت سریال تحمل می‌کرد. `allSettled` هم‌زمان اجا می‌کند و در عین
  // حال ایزوله‌بودن خطا را نگه می‌دارد: هر کدام جداگانه به `null` تبدیل
  // می‌شود.
  const [subRes, siteRes, profileRes, menuRes] = await Promise.allSettled([
    serverOne<MySubscription>("/v1/admin/subscription"),
    serverOne<SiteSettings>("/v1/admin/settings/site"),
    serverOne<ProfileData>("/v1/admin/profile"),
    loadPluginMenu(),
  ]);

  const sub: MySubscription | null = subRes.status === "fulfilled" ? subRes.value : null;
  // هویت بصری پنل از settings/site (نام/لوگو/فاوآیکون واقعی کاربر — بدون هاردکد).
  const site: SiteSettings | null = siteRes.status === "fulfilled" ? siteRes.value : null;
  // تسک ۵: آواتار و نام مدیر جاری برای سایدبار/تاپ‌بار (به‌جای حرف ثابت).
  const profile: ProfileData | null = profileRes.status === "fulfilled" ? profileRes.value : null;
  const pluginMenu: PluginMenuDeclaration[] = menuRes.status === "fulfilled" ? menuRes.value : [];

  // K6.1 — پرمیشن‌های *همین* مدیر. `profile.permissions` بدون `perm:users.view`
  // هم می‌آید، چون `/v1/admin/profile` همان را برمی‌گرداند.
  //
  // خالی یعنی هیچ، و `mergePluginMenu` عمداً روی ورودی خالی همه را می‌بندد
  // (fail-closed): اگر پرمیشن‌ها به هر دلیلی نرسیدند، آیتم‌های افزونه باید
  // ناپدید شوند، نه اینکه باز شوند. «نمایش دادن چیزی که شاید مجاز نباشی»
  // بدترین حالت ممکن است.
  const permissions = profile?.permissions ?? [];

  const suspended = sub?.status === "suspended";
  const pastDue = sub?.status === "past_due";
  // ادغام منو **قبل از** props و روی سرور انجام می‌شود، پس `Sidebar` و
  // `BottomNav` بدون یک بایت تغییر، آیتم‌های افزونه را می‌بینند.
  //
  // منوی تعلیق عمداً ادغام نمی‌شود: `SuspensionGuard` (پایین همین فایل) فقط
  // صفحهٔ پرداخت را باز می‌گذارد و افزودن آیتم به آن قفل را دور می‌زد.
  const menu = suspended
    ? ADMIN_MENU_LOCKED
    : mergePluginMenu(ADMIN_MENU, pluginMenu, permissions).groups;

  return (
    <Shell
      groups={menu}
      brand={{ name: "پنل مدیریت", sub: site?.title?.trim() || "پنل مدیریت", logoUrl: site?.logo_url ?? null }}
      // فقط ریشه؛ بقیهٔ مسیر را Shell از رجیستری منو + مسیر جاری می‌سازد.
      crumbs={["پنل"]}
      user={profile ? { name: profile.first_name?.trim() || profile.name, avatarUrl: profile.avatar_url ?? null } : null}
    >
      <AdminFavicon href={site?.favicon_url ?? null} />
      {pastDue && !suspended ? (
        <div className="lock-banner" role="alert" style={{ background: "var(--warning-soft)", borderColor: "var(--warning)" }}>
          <span>فاکتور سررسید گذشته دارید — تا {sub?.days_remaining ?? "—"} روز دیگر پنل معلق می‌شود.</span>
          <a className="btn btn-primary btn-sm" href="/admin/subscription" style={{ marginInlineStart: "auto" }}>مشاهده فاکتور</a>
        </div>
      ) : null}
      {suspended ? (
        <div className="lock-banner" role="alert">
          <span>اشتراک معلق است — فقط صفحه «اشتراک من» فعال است.</span>
        </div>
      ) : null}
      <SuspensionGuard locked={suspended}>{children}</SuspensionGuard>
    </Shell>
  );
}
