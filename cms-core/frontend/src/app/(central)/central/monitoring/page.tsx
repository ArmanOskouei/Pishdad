import { serverApi } from "@/lib/server-api";
import { asPaginator, type HealthRow, type UptimeRow } from "@/lib/domain";
import type { CentralLogItem } from "../types.ts";
import { Alert } from "@/components/ui/primitives";
import { MonitoringClient } from "./MonitoringClient";

/** مانیتورینگ ۲.۵ — گرید سلامت نصب‌ها + لاگ متمرکز + آپتایم. */
export default async function MonitoringPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const sp = await searchParams;
  const level = sp.level ?? "";
  const site_id = sp.site_id ?? "";
  // دسته UIUX (افزودنی): جستجوی متنی لاگ.
  const q = sp.q ?? "";
  const qs = new URLSearchParams({ per_page: "25" });
  if (level) qs.set("level", level);
  if (site_id) qs.set("site_id", site_id);
  if (q) qs.set("q", q);

  let grid: HealthRow[] = [];
  let silent: HealthRow[] = [];
  let silentMinutes = 10;
  let logs = asPaginator<CentralLogItem>(null);
  let uptime: UptimeRow[] = [];
  let error: string | null = null;

  try {
    const [h, l, u] = await Promise.all([
      serverApi<{ data: { silent_minutes: number; grid: HealthRow[]; silent: HealthRow[] } }>(`/v1/p/central/monitoring/health`),
      serverApi<unknown>(`/v1/p/central/monitoring/logs?${qs.toString()}`),
      serverApi<{ data: { days: number; clients: UptimeRow[] } }>(`/v1/p/central/monitoring/uptime?days=30`).catch(() => null),
    ]);
    grid = h.data.grid ?? [];
    silent = h.data.silent ?? [];
    silentMinutes = h.data.silent_minutes ?? 10;
    logs = asPaginator<CentralLogItem>(l);
    uptime = u?.data?.clients ?? [];
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری مانیتورینگ.";
  }

  return (
    <div>
      <div className="page-head">
        <div><h1>مانیتورینگ</h1><p>سلامت نصب‌ها (heartbeat) + لاگ متمرکز ۳۰ روز اخیر</p></div>
      </div>
      {error ? <Alert tone="red">{error}</Alert> : (
        <MonitoringClient grid={grid} silent={silent} silentMinutes={silentMinutes} logs={logs} level={level} siteId={site_id} q={q} uptime={uptime} />
      )}
    </div>
  );
}
