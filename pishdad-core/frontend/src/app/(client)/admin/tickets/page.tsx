import { serverApi, apiQs } from "@/lib/server-api";
import { asPaginator, type Ticket } from "@/lib/domain";
import { Alert } from "@/components/ui/primitives";
import { TicketsClient } from "./TicketsClient";

/** تیکت‌ها ۱.۷ — Master-Detail: لیست سرورساید + گفتگوی چت. */
export default async function TicketsPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const sp = await searchParams;
  const page = Math.max(1, Number(sp.page ?? 1) || 1);

  let initial = asPaginator<Ticket>(null);
  let error: string | null = null;
  try {
    const json = await serverApi<unknown>(`/v1/admin/tickets${apiQs(sp, ["status", "search", "priority", "label"], { per_page: 20, page })}`);
    initial = asPaginator<Ticket>(json);
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری تیکت‌ها.";
  }

  return (
    <div>
      <div className="page-head">
        <div><h1>تیکت‌ها</h1><p>پشتیبانی — تیکت از پنل یا فرم تماس سایت می‌آید</p></div>
      </div>
      {error ? <Alert tone="red">{error}</Alert> : <TicketsClient initial={initial} status={sp.status ?? ""} search={sp.search ?? ""} priority={sp.priority ?? ""} label={sp.label ?? ""} page={page} selectedId={Number(sp.ticket) || null} />}
    </div>
  );
}
