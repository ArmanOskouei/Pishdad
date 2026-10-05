import { serverOne } from "@/lib/server-api";
import { OpenCase } from "@/lib/domain";
import type { CentralCharts, CentralStats } from "../types.ts";
import { Alert } from "@/components/ui/primitives";
import { CentralDashboardClient } from "./CentralDashboardClient";

/** داشبورد مرکزی ۲.۱ + دسته ۱: آمار + نمودارها + پرونده‌های باز قابل اکشن + «مشتری جدید». */
export default async function CentralDashboardPage() {
  let stats: CentralStats | null = null;
  let charts: CentralCharts | null = null;
  let cases: OpenCase[] = [];
  let error: string | null = null;
  try {
    const [s, c, o] = await Promise.all([
      serverOne<CentralStats>(`/v1/p/central/dashboard/stats`),
      serverOne<CentralCharts>(`/v1/p/central/dashboard/charts`),
      serverOne<OpenCase[]>(`/v1/p/central/dashboard/open-cases`).catch(() => [] as OpenCase[]),
    ]);
    stats = s;
    charts = c;
    cases = o;
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری داشبورد مرکزی.";
  }

  return (
    <div>
      <div className="page-head">
        <div><h1>داشبورد مرکزی</h1><p>نمای روزانه کارفرما — مشتریان، MRR، درآمد ۱۲ ماهه</p></div>
        <div style={{ flex: 1 }} />
        <a className="btn btn-primary btn-sm" href="/central/clients?create=1">＋ مشتری جدید</a>
      </div>
      {error || !stats || !charts ? (
        <Alert tone="red">{error ?? "داده‌ای نیست."}</Alert>
      ) : (
        <CentralDashboardClient stats={stats} charts={charts} cases={cases} />
      )}
    </div>
  );
}
