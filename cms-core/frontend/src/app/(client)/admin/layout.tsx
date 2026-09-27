import { Shell } from "@/components/layout/Shell";
import { AdminFavicon } from "@/components/layout/AdminFavicon";
import { SuspensionGuard } from "@/components/layout/SuspensionGuard";
import { ADMIN_MENU, ADMIN_MENU_LOCKED, mergePluginMenu, type PluginMenuDeclaration } from "@/lib/menu";
import { serverOne } from "@/lib/server-api";
import type { MySubscription, ProfileData, SiteSettings } from "@/lib/domain";

/**
 * K6.1 — آیتم‌های `admin.menu` که افزونه‌های فعال اعلام کرده‌اند.
 *
 * ⚠️ **این ورودی هنوز خالی است و تسک K6.1 بابت همین تمام نیست.**
 *
 * چرا: هیچ مسیر سمت‌سروری برای این داده وجود ندارد. `ManifestRegistry`
 * (`cms-core/backend/app/Services/Plugins/ManifestRegistry.php`) متد
 * `menuItems()` ندارد — فقط `permissionModules()` (`:110`)،
 * `widgetSchemas()` (`:153`)، `pageTypes()` (`:226`)،
 * `themeFooterDefaults()` (`:270`) و `footerBase()` (`:311`) — و در
 * `routes/api.php` (بازهٔ `:90-194`) هیچ routeای به منو نمی‌خورد.
 *
 * چرا به `/v1/admin/plugins` (`api.php:184`) وصل نشد، با اینکه تنها منبعِ
 * موجود است — سه دلیل، هر کدام به‌تنهایی کافی:
 *  ۱) `middleware('perm:plugins.view')` ⇒ مدیری که این پرمیشن را ندارد
 *     ۴۰۳ می‌گیرد و منویش بی‌صدا خالی می‌شود.
 *  ۲) `PluginController@index` (`:54-56`) با `paginate(20)` و `latest()`
 *     ⇒ بالای ۲۰ پلاگین، اینکه کدام آیتم‌های منو اصلاً دیده شوند به تاریخ
 *     نصب بستگی دارد، نه به داده.
 *  ۳) `present()` (`:504-510`) کل `manifest` را می‌فرستد ⇒ امضا، کلید
 *     عمومی و جدول‌های اعلام‌شدهٔ هر پلاگین به مرورگر هر مدیری می‌رود.
 *  و بدتر از همه، پرمیشن‌های مدیر جاری از هیچ endpointای برای *خودِ او*
 *  در دسترس نیست (`/v1/admin/profile` فقط `roles` برمی‌گرداند،
 * `ProfileController@show:29-41`) ⇒ دروازهٔ `permission` عملاً اجبارشدنی نبود.
 *
 * پس تا وقتی endpoint زیر اضافه نشده، این آرایه خالی می‌ماند و رفتار پنل
 * دقیقاً مثل امروز است. تنها کاری که این تسک کرده، این است که **ادغام سمت
 * سرور** آماده و متصل است؛ به‌محض اضافه‌شدن endpoint، فقط همین یک ثابت
 * عوض می‌شود و `mergePluginMenu` بی‌هیچ تغییری کار می‌کند.
 */
const PLUGIN_MENU: PluginMenuDeclaration[] = [];

/** لایه‌بندی پنل مشترک (جهت سپیده) + قفل تعلیق (بنر + منوی محدود) + لوگو/فاوآیکون سایت. */
export default async function AdminLayout({ children }: { children: React.ReactNode }) {
  // این سه قبلاً پشت‌سرهم `await` می‌شدند، یعنی هر صفحهٔ پنل سه رفت‌وبرگشت
  // سریال تحمل می‌کند. `allSettled` هم‌زمان اجا می‌کند و در عین حال ایزوله‌بودن
  // خطا را نگه می‌دارد: هر کدام جداگانه به `null` تبدیل می‌شود.
  const [subRes, siteRes, profileRes] = await Promise.allSettled([
    serverOne<MySubscription>("/v1/admin/subscription"),
    serverOne<SiteSettings>("/v1/admin/settings/site"),
    serverOne<ProfileData>("/v1/admin/profile"),
  ]);

  const sub: MySubscription | null = subRes.status === "fulfilled" ? subRes.value : null;
  // هویت بصری پنل از settings/site (نام/لوگو/فاوآیکون واقعی کاربر — بدون هاردکد).
  const site: SiteSettings | null = siteRes.status === "fulfilled" ? siteRes.value : null;
  // تسک ۵: آواتار و نام مدیر جاری برای سایدبار/تاپ‌بار (به‌جای حرف ثابت).
  const profile: ProfileData | null = profileRes.status === "fulfilled" ? profileRes.value : null;

  const suspended = sub?.status === "suspended";
  const pastDue = sub?.status === "past_due";
  // ادغام منو **قبل از** props و روی سرور انجام می‌شود، پس `Sidebar` و
  // `BottomNav` بدون یک بایت تغییر، آیتم‌های افزونه را می‌بینند.
  //
  // منوی تعلیق عمداً ادغام نمی‌شود: `SuspensionGuard` (پایین همین فایل) فقط
  // صفحهٔ پرداخت را باز می‌گذارد و افزودن آیتم به آن قفل را دور می‌زد.
  const menu = suspended ? ADMIN_MENU_LOCKED : mergePluginMenu(ADMIN_MENU, PLUGIN_MENU).groups;
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
