import { apiQs, serverOne } from "@/lib/server-api";
import type { SearchReportData } from "@/lib/search-report";
import { SearchReportClient } from "./SearchReportClient";

/**
 * WF-M11 — گزارش جستجوی سایت. سرور دادهٔ بازه را می‌خواند و کلاینت فقط
 * انتخابگر بازه و نمایش دارد (بازآرایی با ناوبری، بدون fetch در render).
 */
export default async function SearchReportPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const sp = await searchParams;

  let data: SearchReportData | null = null;
  let error: string | null = null;
  try {
    data = await serverOne<SearchReportData>(`/v1/admin/search-report${apiQs(sp, ["from", "to"])}`);
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری گزارش جستجو.";
  }

  return (
    <SearchReportClient
      initial={data}
      initialFrom={sp.from ?? ""}
      initialTo={sp.to ?? ""}
      error={error}
    />
  );
}
