import { serverOne } from "@/lib/server-api";
import type { SocialItem } from "@/lib/domain";
import { Alert } from "@/components/ui/primitives";
import { SocialsClient } from "./SocialsClient";

/** شبکه‌های اجتماعی ۱.۹ — لیست کلید/آدرس/فعال. */
export default async function SocialsPage() {
  let initial: SocialItem[] = [];
  let error: string | null = null;
  try {
    const data = await serverOne<{ socials: SocialItem[] }>(`/v1/admin/settings/socials`);
    initial = data.socials ?? [];
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری شبکه‌ها.";
  }

  return (
    <div>
      <div className="page-head">
        <div><h1>شبکه‌های اجتماعی</h1><p>آدرس پروفایل‌ها — در فوتر سایت نمایش داده می‌شود</p></div>
      </div>
      {error ? <Alert tone="red">{error}</Alert> : <SocialsClient initial={initial} />}
    </div>
  );
}
