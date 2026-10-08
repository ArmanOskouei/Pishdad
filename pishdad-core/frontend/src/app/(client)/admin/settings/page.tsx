import { serverOne } from "@/lib/server-api";
import type { SiteSettings } from "@/lib/domain";
import { Alert } from "@/components/ui/primitives";
import { SettingsClient } from "./SettingsClient";

/** تنظیمات سایت ۱.۸ — هویت/لوگو/تماس + SaveBar. */
export default async function SettingsPage() {
  let initial: SiteSettings | null = null;
  let error: string | null = null;
  try {
    initial = await serverOne<SiteSettings>(`/v1/admin/settings/site`);
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری تنظیمات.";
  }

  return (
    <div>
      <div className="page-head">
        <div><h1>تنظیمات سایت</h1><p>هویت، لوگو و راه‌های تماس — روی سایت عمومی اعمال می‌شود</p></div>
      </div>
      {error || !initial ? <Alert tone="red">{error ?? "تنظیمات یافت نشد."}</Alert> : <SettingsClient initial={initial} />}
    </div>
  );
}
