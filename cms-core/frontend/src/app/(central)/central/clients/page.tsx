import { serverApi, apiQs } from "@/lib/server-api";
import { asPaginator } from "@/lib/domain";
import type { CentralClient } from "../types.ts";
import { Alert } from "@/components/ui/primitives";
import { Pagination } from "@/components/ui/Pagination";
import { ClientsTable } from "./ClientsTable";

/** مدیریت مشتریان ۲.۲ — Server Component (لیست + فیلتر) + جدول/Drawer تعاملی. */
export default async function ClientsPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const sp = await searchParams;
  const page = Math.max(1, Number(sp.page ?? 1) || 1);
  const params: Record<string, string> = { page: String(page) };
  if (sp.search) params.search = sp.search;
  if (sp.tier) params.tier = sp.tier;
  if (sp.status) params.status = sp.status;
  if (sp.health) params.health = sp.health;

  let initial = asPaginator<CentralClient>(null);
  let error: string | null = null;
  try {
    const json = await serverApi<unknown>(`/v1/p/central/clients${apiQs(sp, ["search", "tier", "status", "health"], { per_page: 20, page })}`);
    initial = asPaginator<CentralClient>(json);
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری مشتریان.";
  }

  return (
    <div>
      <div className="page-head">
        <div><h1>مشتریان</h1><p>مدیریت نصب‌ها، تیر، تعلیق و پشتیبانی</p></div>
      </div>

      <form className="toolbar" method="get" action="/central/clients">
        <div className="field grow">
          <label htmlFor="cq">جستجو</label>
          <input id="cq" name="search" className="input" placeholder="نام، دامنه، site_id یا ایمیل مالک…" defaultValue={sp.search ?? ""} />
        </div>
        <div className="field">
          <label htmlFor="ct">تیر</label>
          <select id="ct" name="tier" className="select" defaultValue={sp.tier ?? ""}>
            <option value="">همه</option>
            <option value="budget">بودجه</option>
            <option value="standard">استاندارد</option>
            <option value="enterprise">سازمانی</option>
          </select>
        </div>
        <div className="field">
          <label htmlFor="cs">وضعیت</label>
          <select id="cs" name="status" className="select" defaultValue={sp.status ?? ""}>
            <option value="">همه</option>
            <option value="active">فعال</option>
            <option value="suspended">معلق</option>
            <option value="deleted">حذف‌شده</option>
          </select>
        </div>
        <div className="field">
          <label htmlFor="ch">سلامت</label>
          <select id="ch" name="health" className="select" defaultValue={sp.health ?? ""}>
            <option value="">همه</option>
            <option value="healthy">سالم</option>
            <option value="warning">هشدار</option>
            <option value="critical">بحرانی</option>
          </select>
        </div>
        <button className="btn btn-ghost" type="submit">اعمال</button>
      </form>

      {error ? <Alert tone="red">{error}</Alert> : <ClientsTable initial={initial} autoCreate={sp.create === "1"} />}
      <Pagination page={initial.current_page} lastPage={initial.last_page} total={initial.total} base="/central/clients" params={params} />
    </div>
  );
}
