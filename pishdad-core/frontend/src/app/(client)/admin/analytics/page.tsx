import { apiQs, serverOne } from "@/lib/server-api";
import type { AnalyticsData } from "@/lib/analytics";
import { AnalyticsClient } from "./AnalyticsClient";

/**
 * WF-H12 — تحلیل سایت عمومی. سرور دادهٔ بازه را می‌خواند و کلاینت فقط
 * انتخابگر بازه و نمایش دارد (بازآرایی با ناوبری، بدون fetch در render).
 */
export default async function AnalyticsPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const sp = await searchParams;

  let data: AnalyticsData | null = null;
  let error: string | null = null;
  try {
    data = await serverOne<AnalyticsData>(`/v1/admin/analytics${apiQs(sp, ["from", "to"])}`);
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری تحلیل.";
  }

  return (
    <AnalyticsClient
      initial={data}
      initialFrom={sp.from ?? ""}
      initialTo={sp.to ?? ""}
      error={error}
    />
  );
}
