import { Shell } from "@/components/layout/Shell";
import { AdminFavicon } from "@/components/layout/AdminFavicon";
import { Onboarding } from "@/components/layout/Onboarding";
import { ADMIN_MENU, mergePluginMenu, type PluginMenuDeclaration } from "@/lib/menu";
import { filterMenuByPlugins } from "@/lib/menu-plugin-visibility";
import { serverOne } from "@/lib/server-api";
import type { ProfileData, SiteSettings } from "@/lib/domain";

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
type PluginMenuResponse = { items?: PluginMenuDeclaration[]; active_slugs?: string[] };

/**
 * منوی افزونه‌ها + فهرستِ افزونه‌های **فعال**.
 *
 * ⭐ چرا `active_slugs` هم لازم است: `items` فقط چیزی را می‌دهد که افزونه
 * *اعلام* کرده. ولی منوی **هسته** هم می‌تواند آیتم‌هایی داشته باشد که متعلق به
 * افزونه‌اند — گزارشِ کاربر همین بود: با غیرفعال‌شدنِ افزونه، آیتمش در منو ماند
 * و به صفحه‌ای لینک می‌کرد که دیگر وجود نداشت.
 *
 * برمی‌گرداند: اعلان‌ها، و `Set` اسلاگ‌های فعال. `null` برای یعنی «نمی‌دانیم»
 * — و فیلتر، fail-closed عمل می‌کند یعنی آیتم‌های افزونه‌ای را پنهان
 * می‌کند. `Set` خالی با `null` فرق دارد: اولی «هیچ افزونه‌ای فعال نیست» است
 * و دومی «نمی‌دانیم».
 */
async function loadPluginMenu(): Promise<{
  items: PluginMenuDeclaration[];
  activeSlugs: Set<string> | null;
}> {
  try {
    const json = await serverOne<PluginMenuResponse>("/v1/admin/plugins/menu");

    return {
      // نبودن `items` یعنی «هیچ»، نه «همه» — همان قاعدهٔ ورودی خالی.
      items: Array.isArray(json?.items) ? json.items : [],
      activeSlugs: Array.isArray(json?.active_slugs) ? new Set(json.active_slugs) : null,
    };
  } catch {
    // خطا ⇒ منو دقیقاً مثل امروز. عمداً هیچ اعلانی به کاربر نمی‌دهیم: یک
    // بنر قرمز برای «منوی افزونه‌ها بارگذاری نشد» در هر صفحهٔ پنل، برای
    // اشکالی که خودش را در ۵ دقیقه درست می‌کند، آزاردهنده‌تر از سکوت است.
    //
    // `activeSlugs: null` یعنی فیلتر، آیتم‌هایِ افزونه‌ایِ منوی هسته را
    // پنهان می‌کند — بهتر از نمایشِ لینکی که به ۴۰۴ می‌رود.
    return { items: [], activeSlugs: null };
  }
}

/** لایه‌بندی پنل مشترک (جهت سپیده) + منوی افزونه‌ها + لوگو/فاوآیکون سایت. */
export default async function AdminLayout({ children }: { children: React.ReactNode }) {
  // این سه قبلاً پشت‌سرهم `await` می‌شدند، یعنی هر صفحهٔ پنل سه
  // رفت‌وبرگشت سریال تحمل می‌کرد. `allSettled` هم‌زمان اجا می‌کند و در عین
  // حال ایزوله‌بودن خطا را نگه می‌دارد: هر کدام جداگانه به `null` تبدیل
  // می‌شود.
  const [siteRes, profileRes, menuRes] = await Promise.allSettled([
    serverOne<SiteSettings>("/v1/admin/settings/site"),
    serverOne<ProfileData>("/v1/admin/profile"),
    loadPluginMenu(),
  ]);

  // هویت بصری پنل از settings/site (نام/لوگو/فاوآیکون واقعی کاربر — بدون هاردکد).
  const site: SiteSettings | null = siteRes.status === "fulfilled" ? siteRes.value : null;
  // تسک ۵: آواتار و نام مدیر جاری برای سایدبار/تاپ‌بار (به‌جای حرف ثابت).
  const profile: ProfileData | null = profileRes.status === "fulfilled" ? profileRes.value : null;
  const pluginMenu: PluginMenuDeclaration[] =
    menuRes.status === "fulfilled" ? menuRes.value.items : [];
  // `null` یعنی «نمی‌دانیم کدام افزونه فعال است» — و فیلتر fail-closed عمل
  // می‌کند. `Set` خالی یعنی «هیچ‌کدام فعال نیست» که نتیجه‌اش یکی است ولی
  // معنی‌اش فرق دارد و در لاگ/دیباگ دیده می‌شود.
  const activePluginSlugs: Set<string> | null =
    menuRes.status === "fulfilled" ? menuRes.value.activeSlugs : null;

  // K6.1 — پرمیشن‌های *همین* مدیر. `profile.permissions` بدون `perm:users.view`
  // هم می‌آید، چون `/v1/admin/profile` همان را برمی‌گرداند.
  //
  // خالی یعنی هیچ، و `mergePluginMenu` عمداً روی ورودی خالی همه را می‌بندد
  // (fail-closed): اگر پرمیشن‌ها به هر دلیلی نرسیدند، آیتم‌های افزونه باید
  // ناپدید شوند، نه اینکه باز شوند. «نمایش دادن چیزی که شاید مجاز نباشی»
  // بدترین حالت ممکن است.
  const permissions = profile?.permissions ?? [];

  // ادغام منو **قبل از** props و روی سرور انجام می‌شود، پس `Sidebar` و
  // `BottomNav` بدون یک بایت تغییر، آیتم‌های افزونه را می‌بینند.
  const menu = filterMenuByPlugins(
    mergePluginMenu(ADMIN_MENU, pluginMenu, permissions).groups,
    activePluginSlugs,
  );

  return (
    <Shell
      groups={menu}
      brand={{ name: "پنل مدیریت", sub: site?.title?.trim() || "پنل مدیریت", logoUrl: site?.logo_url ?? null }}
      // فقط ریشه؛ بقیهٔ مسیر را Shell از رجیستری منو + مسیر جاری می‌سازد.
      // I1-b — ریشه خالی: `Shell` آن را از دیکشنری (`shell.panel`) می‌سازد، وگرنه
      // در زبانِ انگلیسی breadcrumb فارسی می‌ماند.
      crumbs={[]}
      user={profile ? { name: profile.first_name?.trim() || profile.name, avatarUrl: profile.avatar_url ?? null } : null}
      // K6.2 — پروفایل کامل برای دروازهٔ پرمیشن ابزارهای افزونه در هدر.
      // `user` عمداً فقط نام و آواتار است.
      profile={profile}
    >
      <AdminFavicon href={site?.favicon_url ?? null} />
      {/* E8 — آنبوردینگ اولین ورود: فقط یک‌بار، skip در localStorage خودِ کامپوننت. */}
      <Onboarding />
      {children}
    </Shell>
  );
}
