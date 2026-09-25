import Link from "next/link";
import { serverApi, serverOne, apiQs } from "@/lib/server-api";
import { asPaginator, type MediaItem, type MediaUsage } from "@/lib/domain";
import { Alert, EmptyState } from "@/components/ui/primitives";
import { Pagination } from "@/components/ui/Pagination";
import { MediaLibrary } from "./MediaLibrary";

/** کتابخانه فایل ۱.۶ — Server Component (لیست) + MediaLibrary تعاملی. */
export default async function MediaPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const sp = await searchParams;
  const page = Math.max(1, Number(sp.page ?? 1) || 1);
  const trashed = sp.trashed === "only";
  const params: Record<string, string> = { page: String(page) };
  if (sp.search) params.search = sp.search;
  if (sp.mime) params.mime = sp.mime;
  if (trashed) params.trashed = "only";

  let paginator = asPaginator<MediaItem>(null);
  let usage: MediaUsage | null = null;
  let error: string | null = null;
  try {
    const json = await serverApi<unknown>(`/v1/admin/media${apiQs(sp, ["search", "mime"], { per_page: 24, page, ...(trashed ? { trashed: "only" } : {}) })}`);
    paginator = asPaginator<MediaItem>(json);
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری.";
  }
  try {
    usage = await serverOne<MediaUsage>("/v1/admin/media/usage");
  } catch {
    usage = null;
  }

  return (
    <div>
      <div className="page-head">
        <div><h1>فایل‌ها</h1><p>کتابخانه مدیا — آپلود مستقیم با presign</p></div>
        <div style={{ flex: 1 }} />
        <Link className={`btn ${trashed ? "btn-primary" : "btn-ghost"}`} href={trashed ? "/admin/media" : "/admin/media?trashed=only"}>
          {trashed ? "→ بازگشت به کتابخانه" : "سطل زباله"}
        </Link>
      </div>

      <form className="toolbar" method="get" action="/admin/media">
        {trashed ? <input type="hidden" name="trashed" value="only" /> : null}
        <div className="field grow">
          <label htmlFor="mq">جستجو</label>
          <input id="mq" name="search" className="input" placeholder="نام فایل…" defaultValue={sp.search ?? ""} />
        </div>
        <div className="field">
          <label htmlFor="mm">نوع</label>
          <select id="mm" name="mime" className="select" defaultValue={sp.mime ?? ""}>
            <option value="">همه</option>
            <option value="image">تصویر</option>
            <option value="video">ویدئو</option>
            <option value="audio">صوت</option>
            <option value="application">سند</option>
          </select>
        </div>
        <button className="btn btn-ghost" type="submit">اعمال</button>
      </form>

      {error ? <Alert tone="red">{error}</Alert> : <MediaLibrary initial={paginator} trashedView={trashed} usage={usage} />}
      {error && paginator.data.length === 0 ? <div className="card card-pad"><EmptyState title="خطا در بارگذاری" /></div> : null}

      <Pagination page={paginator.current_page} lastPage={paginator.last_page} total={paginator.total} base="/admin/media" params={params} />
    </div>
  );
}
