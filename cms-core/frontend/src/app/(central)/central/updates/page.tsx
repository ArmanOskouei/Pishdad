import { serverApi, serverOne } from "@/lib/server-api";
import type { CurrentRollout, UpdatesData } from "@/lib/domain";
import { Alert } from "@/components/ui/primitives";
import { UpdatesClient } from "./UpdatesClient";

/** آپدیت‌ها ۲.۶ — جدول نصب‌ها + رول‌اوت پلکانی + rollback. */
export default async function UpdatesPage() {
  let data: UpdatesData | null = null;
  let current: CurrentRollout | null = null;
  let error: string | null = null;
  try {
    const [d, c] = await Promise.all([
      serverOne<UpdatesData>(`/central/updates`),
      serverOne<CurrentRollout>(`/central/updates/rollouts/current`).catch(() => null),
    ]);
    data = d;
    current = c;
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری آپدیت‌ها.";
  }

  return (
    <div>
      <div className="page-head">
        <div><h1>آپدیت‌ها</h1><p>رجیستری نسخه‌ها + رول‌اوت پلکانی (بدون canary) + بازگشت</p></div>
      </div>
      {error || !data ? <Alert tone="red">{error ?? "داده‌ای نیست."}</Alert> : <UpdatesClient initial={data} current={current} />}
    </div>
  );
}
