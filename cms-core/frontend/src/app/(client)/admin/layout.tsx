import { Shell } from "@/components/layout/Shell";
import { AdminFavicon } from "@/components/layout/AdminFavicon";
import { SuspensionGuard } from "@/components/layout/SuspensionGuard";
import { ADMIN_MENU, ADMIN_MENU_LOCKED } from "@/lib/menu";
import { serverOne } from "@/lib/server-api";
import type { MySubscription, ProfileData, SiteSettings } from "@/lib/domain";

/** لایه‌بندی پنل مشتری (جهت سپیده) + قفل تعلیق (بنر + منوی محدود) + لوگو/فاوآیکون سایت. */
export default async function AdminLayout({ children }: { children: React.ReactNode }) {
  // این سه قبلاً پشت‌سرهم `await` می‌شدند، یعنی هر صفحهٔ پنل سه رفت‌وبرگشت
  // سریال تحمل می‌کرد. `allSettled` هم‌زمان اجا می‌کند و در عین حال ایزوله‌بودن
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
  return (
    <Shell
      groups={suspended ? ADMIN_MENU_LOCKED : ADMIN_MENU}
      brand={{ name: "پنل مدیریت", sub: site?.title?.trim() || "پنل مدیریت", logoUrl: site?.logo_url ?? null }}
      crumbs={["پنل", "مدیریت"]}
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
