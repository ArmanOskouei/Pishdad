import Link from "next/link";
import { serverApi, apiQs } from "@/lib/server-api";
import { asPaginator } from "@/lib/domain";
import { faNum } from "@/lib/fa";
import { Alert, EmptyState } from "@/components/ui/primitives";
import { Pagination } from "@/components/ui/Pagination";
import { SampleContentEmptyState } from "@/components/admin/SampleContentEmptyState";
import { NewPageButton } from "./NewPageButton";
import { PagesClient, type PageRowItem } from "./PagesClient";

/** لیست صفحات ۱.۵ — Server Component: جستجو/فیلتر وضعیت/صفحه‌بندی سرورساید واقعی. */
export default async function PagesPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const sp = await searchParams;
  const page = Math.max(1, Number(sp.page ?? 1) || 1);
  const trashed = sp.trashed === "only";
  const params: Record<string, string> = { page: String(page) };
  if (sp.search) params.search = sp.search;
  if (trashed) params.trashed = "only";
  if (sp.status && !trashed) params.status = sp.status;
  if (sp.seo && !trashed) params.seo = sp.seo;

  let paginator;
  let error: string | null = null;
  try {
    const path = trashed
      ? `/v1/admin/pages/trash${apiQs(sp, ["search"], { page })}`
      : `/v1/admin/pages${apiQs(sp, ["search", "status", "seo"], { page })}`;
    const json = await serverApi<unknown>(path);
    paginator = asPaginator<PageRowItem>(json);
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری.";
    paginator = asPaginator<PageRowItem>(null);
  }

  return (
    <div>
      <div className="page-head">
        <div><h1>صفحات</h1><p>مدیریت صفحات سایت با ویرایشگر بلوکی</p></div>
        <div style={{ flex: 1 }} />
        <Link className={`btn ${trashed ? "btn-primary" : "btn-ghost"}`} href={trashed ? "/admin/pages" : "/admin/pages?trashed=only"}>
          {trashed ? "→ بازگشت به صفحات" : "سطل زباله"}
        </Link>
        {trashed ? null : <NewPageButton />}
      </div>

      {trashed ? <Alert tone="amber">نمای سطل زباله — صفحات را بازگردانید یا برای همیشه حذف کنید.</Alert> : null}

      <form className="toolbar" method="get" action="/admin/pages">
        {trashed ? <input type="hidden" name="trashed" value="only" /> : null}
        <div className="field grow">
          <label htmlFor="q">جستجو</label>
          <input id="q" name="search" className="input" placeholder="عنوان یا اسلاگ…" defaultValue={sp.search ?? ""} />
        </div>
        {trashed ? null : (
          <div className="field">
            <label htmlFor="st">وضعیت</label>
            <select id="st" name="status" className="select" defaultValue={sp.status ?? ""}>
              <option value="">همه</option>
              <option value="draft">پیش‌نویس</option>
              <option value="published">منتشرشده</option>
            </select>
          </div>
        )}
        {trashed ? null : (
          <div className="field">
            <label htmlFor="seo">سلامت سئو</label>
            <select id="seo" name="seo" className="select" defaultValue={sp.seo ?? ""}>
              <option value="">همه</option>
              <option value="unhealthy">فقط ناسالم</option>
            </select>
          </div>
        )}
        <button className="btn btn-ghost" type="submit">اعمال</button>
        {(sp.search || sp.status || sp.seo) ? <Link className="btn btn-ghost btn-sm" href={trashed ? "/admin/pages?trashed=only" : "/admin/pages"}>حذف فیلتر</Link> : null}
      </form>

      {error ? <Alert tone="red">{error}</Alert> : null}

      {paginator.data.length === 0 && !error ? (
        <div className="card card-pad">
          {trashed
            ? <EmptyState title="سطل زباله خالی است" />
            : <SampleContentEmptyState title="صفحه‌ای نیست" hint="اولین صفحه را بسازید یا محتوای نمونه را یک‌کلیکی بسازید." />}
        </div>
      ) : null}

      {/* WF-M5 — انتخاب گروهی و نوارِ اکشن داخل کلاینت‌کامپوننت زندگی می‌کند. */}
      {paginator.data.length > 0 ? <PagesClient rows={paginator.data} trashed={trashed} /> : null}

      <Pagination page={paginator.current_page} lastPage={paginator.last_page} total={paginator.total} base="/admin/pages" params={params} />
      {paginator.total > 0 ? <p style={{ fontSize: 12, color: "var(--text-muted)" }}>مجموع: {faNum(paginator.total)} صفحه</p> : null}
    </div>
  );
}