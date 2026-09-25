import { serverOne } from "@/lib/server-api";
import type { ThemeItem } from "@/lib/domain";
import { Alert } from "@/components/ui/primitives";
import { ThemesClient } from "./ThemesClient";

/** قالب‌های سایت ۱.۱۳ — گالری + آپلود + فعال‌سازی + پیش‌نمایش. */
export default async function ThemesPage() {
  let initial: ThemeItem[] = [];
  let error: string | null = null;
  try {
    const data = await serverOne<ThemeItem[] | { data: ThemeItem[] }>(`/v1/admin/themes`);
    initial = Array.isArray(data) ? data : (data.data ?? []);
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری قالب‌ها.";
  }

  return (
    <div>
      <div className="page-head">
        <div><h1>قالب سایت</h1><p>ظاهر سایت عمومی — فعال‌سازی با revalidate و purge سمت فرانت</p></div>
      </div>
      {error ? <Alert tone="red">{error}</Alert> : <ThemesClient initial={initial} />}
    </div>
  );
}
