import Link from "next/link";
import { serverApi, apiQs } from "@/lib/server-api";
import { asPaginator, PAGE_STATUS_FA, type PageItem } from "@/lib/domain";
import { faNum, jalali } from "@/lib/fa";
import { Alert, Badge, EmptyState } from "@/components/ui/primitives";
import { Pagination } from "@/components/ui/Pagination";
import { NewPageButton } from "./NewPageButton";
import { PageDeleteButton } from "./PageDeleteButton";

/** لیست صفحات ۱.۵ — Server Component: جستجو/فیلتر وضعیت/صفحه‌بندی سرورساید واقعی. */
export default async function PagesPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const sp = await searchParams;
  const page = Math.max(1, Number(sp.page ?? 1) || 1);
  const params: Record<string, string> = {};
  if (sp.search) params.search = sp.search;
  if (sp.status) params.status = sp.status;
  params.page = String(page);

  let paginator;
  let error: string | null = null;
  try {
    const json = await serverApi<unknown>(`/v1/admin/pages${apiQs(sp, ["search", "status"], { page })}`);
    paginator = asPaginator<PageItem>(json);
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری.";
    paginator = asPaginator<PageItem>(null);
  }

  return (
    <div>
      <div className="page-head">
        <div><h1>صفحات</h1><p>مدیریت صفحات سایت با ویرایشگر بلوکی</p></div>
        <div style={{ flex: 1 }} />
        <NewPageButton />
      </div>

      <form className="toolbar" method="get" action="/admin/pages">
        <div className="field grow">
          <label htmlFor="q">جستجو</label>
          <input id="q" name="search" className="input" placeholder="عنوان یا اسلاگ…" defaultValue={sp.search ?? ""} />
        </div>
        <div className="field">
          <label htmlFor="st">وضعیت</label>
          <select id="st" name="status" className="select" defaultValue={sp.status ?? ""}>
            <option value="">همه</option>
            <option value="draft">پیش‌نویس</option>
            <option value="published">منتشرشده</option>
          </select>
        </div>
        <button className="btn btn-ghost" type="submit">اعمال</button>
        {(sp.search || sp.status) ? <Link className="btn btn-ghost btn-sm" href="/admin/pages">حذف فیلتر</Link> : null}
      </form>

      {error ? <Alert tone="red">{error}</Alert> : null}

      {paginator.data.length === 0 && !error ? (
        <div className="card card-pad"><EmptyState title="صفحه‌ای نیست" hint="اولین صفحه را بسازید." /></div>
      ) : null}

      {paginator.data.length > 0 ? (
        <div className="table-wrap">
          <table className="tbl tbl-cards">
            <thead><tr><th>عنوان</th><th>اسلاگ</th><th>وضعیت</th><th>نسخه‌ها</th><th>آخرین ویرایش</th><th>اکشن</th></tr></thead>
            <tbody>
              {paginator.data.map((p) => (
                <tr key={p.id}>
                  <td data-label="عنوان"><b>{p.title}</b></td>
                  <td data-label="اسلاگ"><span dir="ltr">{p.slug}</span></td>
                  <td data-label="وضعیت">
                    <Badge tone={p.status === "published" ? "green" : "gray"}>{PAGE_STATUS_FA[p.status] ?? p.status}</Badge>
                  </td>
                  <td data-label="نسخه‌ها">نسخه {faNum(p.revisions_count ?? 0)}</td>
                  <td data-label="آخرین ویرایش">{jalali(p.updated_at)}</td>
                  <td data-label="اکشن">
                    <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
                      <Link className="btn btn-ghost btn-sm" href={`/admin/pages/${p.id}/edit`}>ویرایش</Link>
                      {p.status === "published" ? (
                        <a className="btn btn-ghost btn-sm" href={`/${p.slug}`} target="_blank" rel="noreferrer" title="مشاهده نسخه منتشرشده در سایت">پیش‌نمایش</a>
                      ) : (
                        <Link className="btn btn-ghost btn-sm" href={`/admin/pages/${p.id}/edit`} title="پیش‌نمایش پیش‌نویس در ویرایشگر">پیش‌نمایش</Link>
                      )}
                      <PageDeleteButton pageId={p.id} title={p.title} />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      <Pagination page={paginator.current_page} lastPage={paginator.last_page} total={paginator.total} base="/admin/pages" params={params} />
      {paginator.total > 0 ? <p style={{ fontSize: 12, color: "var(--text-muted)" }}>مجموع: {faNum(paginator.total)} صفحه</p> : null}
    </div>
  );
}
