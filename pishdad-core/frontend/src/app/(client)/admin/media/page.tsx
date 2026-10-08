import Link from "next/link";
import { serverApi, serverOne, apiQs } from "@/lib/server-api";
import { asList, asPaginator, type MediaFolder, type MediaItem, type MediaTag, type MediaUsage } from "@/lib/domain";
import { Alert, EmptyState } from "@/components/ui/primitives";
import { Pagination } from "@/components/ui/Pagination";
import { MediaLibrary } from "./MediaLibrary";

/** WF-H6 — ساخت درخت تخت با تودرتویی برای `<select>` (بدون وابستگی خارجی). */
function flatFolders(folders: MediaFolder[]): { id: number; label: string }[] {
  const byParent = new Map<number, MediaFolder[]>();
  for (const f of folders) {
    const key = f.parent_id ?? 0;
    byParent.set(key, [...(byParent.get(key) ?? []), f]);
  }
  const out: { id: number; label: string }[] = [];
  const walk = (parent: number, depth: number) => {
    for (const f of byParent.get(parent) ?? []) {
      out.push({ id: f.id, label: `${"　".repeat(depth)}${depth > 0 ? "└ " : ""}${f.name}` });
      walk(f.id, depth + 1);
    }
  };
  walk(0, 0);
  return out;
}

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
  if (sp.type) params.type = sp.type;
  if (sp.folder) params.folder = sp.folder;
  if (sp.tag) params.tag = sp.tag;
  if (trashed) params.trashed = "only";

  let paginator = asPaginator<MediaItem>(null);
  let usage: MediaUsage | null = null;
  let folders: MediaFolder[] = [];
  let tags: MediaTag[] = [];
  let error: string | null = null;
  try {
    const json = await serverApi<unknown>(`/v1/admin/media${apiQs(sp, ["search", "mime", "type", "folder", "tag"], { per_page: 24, page, ...(trashed ? { trashed: "only" } : {}) })}`);
    paginator = asPaginator<MediaItem>(json);
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری.";
  }
  try {
    usage = await serverOne<MediaUsage>("/v1/admin/media/usage");
  } catch {
    usage = null;
  }
  try {
    folders = asList<MediaFolder>(await serverApi<unknown>("/v1/admin/media/folders"));
  } catch {
    folders = [];
  }
  try {
    tags = asList<MediaTag>(await serverApi<unknown>("/v1/admin/media/tags"));
  } catch {
    tags = [];
  }
  const folderOptions = flatFolders(folders);

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
          <label htmlFor="mf">پوشه</label>
          <select id="mf" name="folder" className="select" defaultValue={sp.folder ?? ""}>
            <option value="">همه</option>
            <option value="none">بدون پوشه</option>
            {folderOptions.map((f) => <option key={f.id} value={f.id}>{f.label}</option>)}
          </select>
        </div>
        <div className="field">
          <label htmlFor="mt">برچسب</label>
          <select id="mt" name="tag" className="select" defaultValue={sp.tag ?? ""}>
            <option value="">همه</option>
            {tags.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
          </select>
        </div>
        <div className="field">
          <label htmlFor="mm">نوع</label>
          <select id="mm" name="type" className="select" defaultValue={sp.type ?? sp.mime ?? ""}>
            <option value="">همه</option>
            <option value="image">تصویر</option>
            <option value="video">ویدئو</option>
            <option value="audio">صوت</option>
            <option value="application">سند</option>
          </select>
        </div>
        <button className="btn btn-ghost" type="submit">اعمال</button>
      </form>

      {error ? <Alert tone="red">{error}</Alert> : <MediaLibrary initial={paginator} trashedView={trashed} usage={usage} folders={folders} tags={tags} />}
      {error && paginator.data.length === 0 ? <div className="card card-pad"><EmptyState title="خطا در بارگذاری" /></div> : null}

      <Pagination page={paginator.current_page} lastPage={paginator.last_page} total={paginator.total} base="/admin/media" params={params} />
    </div>
  );
}
