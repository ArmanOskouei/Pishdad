import Link from "next/link";
import { serverApi, serverOne } from "@/lib/server-api";
import { asList, type BlockDef, type PageItem, type Revision } from "@/lib/domain";
import { Alert } from "@/components/ui/primitives";
import { PageEditor } from "./PageEditor";

/** صفحه ویرایش ۱.۵ — Server Component: جزئیات + رجیستری بلوک‌ها، بعد PageEditor تعاملی. */
export default async function PageEditPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  let page: PageItem | null = null;
  let registry: BlockDef[] = [];
  let error: string | null = null;
  try {
    const [p, r] = await Promise.all([
      serverOne<PageItem>(`/v1/admin/pages/${id}`),
      serverApi<unknown>(`/v1/admin/blocks/schema`),
    ]);
    page = p;
    registry = asList<BlockDef>(r);
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری صفحه.";
  }

  if (error || !page) {
    return (
      <div>
        <div className="page-head"><div><h1>ویرایش صفحه</h1></div><div style={{ flex: 1 }} /><Link className="btn btn-ghost" href="/admin/pages">→ بازگشت</Link></div>
        <Alert tone="red">{error ?? "صفحه یافت نشد."}</Alert>
      </div>
    );
  }

  const initialRevisions = ((page as PageItem & { revisions?: Revision[] }).revisions ?? []).slice()
    .sort((a, b) => b.version - a.version);

  return (
    <div>
      <div className="page-head" style={{ marginBlockEnd: 10 }}>
        <div><h1>ویرایشگر</h1><p><span dir="ltr">{page.slug}</span></p></div>
      </div>
      <PageEditor page={page} registry={registry} initialRevisions={initialRevisions} />
    </div>
  );
}
