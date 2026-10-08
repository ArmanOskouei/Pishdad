import Link from "next/link";
import { serverApi, apiQs } from "@/lib/server-api";
import { asPaginator, type FormItem } from "@/lib/domain";
import { faNum, jalali } from "@/lib/fa";
import { Alert, Badge } from "@/components/ui/primitives";
import { Pagination } from "@/components/ui/Pagination";
import { FormDeleteButton } from "./FormDeleteButton";
import { SampleContentEmptyState } from "@/components/admin/SampleContentEmptyState";

/** WF-H10 — لیست فرم‌های ساخته‌شده (Server Component: جستجو/صفحه‌بندی سرورساید). */
export default async function FormsPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const sp = await searchParams;
  const page = Math.max(1, Number(sp.page ?? 1) || 1);

  let paginator;
  let error: string | null = null;
  try {
    const json = await serverApi<unknown>(`/v1/admin/forms${apiQs(sp, ["search"], { page })}`);
    paginator = asPaginator<FormItem>(json);
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری.";
    paginator = asPaginator<FormItem>(null);
  }

  return (
    <div>
      <div className="page-head">
        <div><h1>فرم‌ساز</h1><p>فرم‌های دلخواه سایت، مقصد ارسال و پاسخ‌های ثبت‌شده</p></div>
        <div style={{ flex: 1 }} />
        <Link className="btn btn-primary" href="/admin/forms/new">فرم جدید</Link>
      </div>

      <form className="toolbar" method="get" action="/admin/forms">
        <div className="field grow">
          <label htmlFor="q">جستجو</label>
          <input id="q" name="search" className="input" placeholder="نام یا اسلاگ…" defaultValue={sp.search ?? ""} />
        </div>
        <button className="btn btn-ghost" type="submit">اعمال</button>
        {sp.search ? <Link className="btn btn-ghost btn-sm" href="/admin/forms">حذف فیلتر</Link> : null}
      </form>

      {error ? <Alert tone="red">{error}</Alert> : null}

      {paginator.data.length === 0 && !error ? (
        <div className="card card-pad">
          <SampleContentEmptyState title="فرمی نیست" hint="اولین فرم را بسازید یا محتوای نمونه (شامل فرم تماس) را یک‌کلیکی بسازید." />
        </div>
      ) : null}

      {paginator.data.length > 0 ? (
        <div className="table-wrap">
          <table className="tbl tbl-cards">
            <thead>
              <tr>
                <th>نام</th><th>اسلاگ</th><th>مقصد</th><th>فیلدها</th><th>پاسخ‌ها</th><th>وضعیت</th><th>آخرین ویرایش</th><th>اکشن</th>
              </tr>
            </thead>
            <tbody>
              {paginator.data.map((f) => (
                <tr key={f.id}>
                  <td data-label="نام"><b>{f.name}</b></td>
                  <td data-label="اسلاگ"><span dir="ltr">{f.slug}</span></td>
                  <td data-label="مقصد"><Badge tone={f.destination === "email" ? "violet" : "gray"}>{f.destination === "email" ? "ایمیل" : "تیکت"}</Badge></td>
                  <td data-label="فیلدها">{faNum(f.fields?.length ?? 0)}</td>
                  <td data-label="پاسخ‌ها">{faNum(f.submissions_count ?? 0)}</td>
                  <td data-label="وضعیت"><Badge tone={f.active ? "green" : "gray"}>{f.active ? "فعال" : "غیرفعال"}</Badge></td>
                  <td data-label="آخرین ویرایش">{jalali(f.updated_at)}</td>
                  <td data-label="اکشن">
                    <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
                      <Link className="btn btn-ghost btn-sm" href={`/admin/forms/${f.id}`}>ویرایش</Link>
                      <a className="btn btn-ghost btn-sm" href={`/api/forms/${f.id}/export`}>خروجی CSV</a>
                      <FormDeleteButton formId={f.id} name={f.name} />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      <Pagination page={paginator.current_page} lastPage={paginator.last_page} total={paginator.total} base="/admin/forms" params={{ page: String(page), ...(sp.search ? { search: sp.search } : {}) }} />
      {paginator.total > 0 ? <p style={{ fontSize: 12, color: "var(--text-muted)" }}>مجموع: {faNum(paginator.total)} فرم</p> : null}
    </div>
  );
}
