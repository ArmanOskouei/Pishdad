import { serverApi } from "@/lib/server-api";
import { asPaginator, type PluginItem } from "@/lib/domain";
import { Alert } from "@/components/ui/primitives";
import { Pagination } from "@/components/ui/Pagination";
import { PluginsClient } from "./PluginsClient";
import { PanelLocksBanner } from "@/components/admin/PanelLocksBanner";

/** پلاگین‌ها ۱.۱۰ — لیست + آپلود ZIP امضاشده + فعال/غیرفعال + Badge آپدیت + حذف. */
export default async function PluginsPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const sp = await searchParams;
  const page = Math.max(1, Number(sp.page ?? 1) || 1);

  let initial = asPaginator<PluginItem>(null);
  let error: string | null = null;
  try {
    const json = await serverApi<unknown>(`/v1/admin/plugins?per_page=20&page=${page}`);
    initial = asPaginator<PluginItem>(json);
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری پلاگین‌ها.";
  }

  return (
    <div>
      <div className="page-head">
        <div><h1>پلاگین‌ها</h1><p>نصب فقط با امضای معتبر Ed25519 — fail-closed سمت سرور</p></div>
      </div>
      {/* K7.16 — قفل‌ها داده‌محور از همان APIهای موجود؛ خالی ⇒ هیچ. */}
      {!error ? <PanelLocksBanner /> : null}
      {error ? <Alert tone="red">{error}</Alert> : <PluginsClient initial={initial} />}
      {!error ? <Pagination page={initial.current_page} lastPage={initial.last_page} total={initial.total} base="/admin/plugins" params={{ page: String(page) }} /> : null}
    </div>
  );
}
