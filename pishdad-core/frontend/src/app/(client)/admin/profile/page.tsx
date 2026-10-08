import { serverOne } from "@/lib/server-api";
import type { SiteChrome } from "@/lib/site";
import type { ProfileData, TwoFactorMethods } from "@/lib/domain";
import { Alert } from "@/components/ui/primitives";
import { ProfileClient } from "./ProfileClient";

/** پروفایل مدیر ۱.۴ — فرم مشخصات + تغییر رمز + 2FA + تنظیمات سریع ظاهر پنل. */
export default async function ProfilePage() {
  let profile: ProfileData | null = null;
  let methods: TwoFactorMethods | null = null;
  let issuer = "CMS";
  let error: string | null = null;

  try {
    const [p, m, chrome] = await Promise.all([
      serverOne<ProfileData>(`/v1/admin/profile`),
      serverOne<TwoFactorMethods>(`/v1/admin/profile/2fa/methods`),
      serverOne<Pick<SiteChrome, "title">>(`/v1/site/chrome`).catch(() => null),
    ]);
    profile = p;
    methods = m;
    issuer = chrome?.title?.trim() || "CMS";
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری پروفایل.";
  }

  return (
    <div>
      <div className="page-head">
        <div><h1>پروفایل من</h1><p>مشخصات، رمز عبور، تأیید دومرحله‌ای و ظاهر پنل</p></div>
      </div>
      {error || !profile ? <Alert tone="red">{error ?? "پروفایل یافت نشد."}</Alert> : <ProfileClient initial={profile} methods={methods} issuer={issuer} />}
    </div>
  );
}
