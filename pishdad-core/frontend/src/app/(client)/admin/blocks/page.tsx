import { serverApi } from "@/lib/server-api";
import type { BlockDef, PageTypeItem } from "@/lib/domain";
import { Alert } from "@/components/ui/primitives";
import { BlocksClient } from "./BlocksClient";

/**
 * بلوک‌های سایت ۱.۱۲ — تب نوع صفحه + درگ‌اندروپ + فعال/غیرفعال + ذخیره.
 *
 * قرارداد page-type (فاز پلاگین):
 * - تب‌ها فقط typeهای فعالِ `GET /v1/admin/layouts/page-types` هستند — هیچ
 *   هاردکدی (از جمله `blog`) در فرانت نیست؛ type ناشناس → 404 بک‌اند.
 * - پلاگین‌ها type جدید را از manifest ثبت می‌کنند (بک‌اند: merge در
 *   `config/page_types` هنگام فعال‌سازی) و این صفحه خودکار تب تازه را نشان
 *   می‌دهد؛ override هر type در `page_blocks:user_{id}:{type}` ذخیره می‌شود.
 */
export default async function BlocksPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const sp = await searchParams;

  let types: PageTypeItem[] = [];
  let registry: BlockDef[] = [];
  let error: string | null = null;
  try {
    const [tj, bj] = await Promise.all([
      serverApi<unknown>(`/v1/admin/layouts/page-types`),
      serverApi<unknown>(`/v1/admin/blocks/schema`),
    ]);
    const td = (tj as { data?: unknown })?.data ?? tj;
    types = Array.isArray(td) ? (td as PageTypeItem[]) : [];
    const bd = (bj as { data?: unknown })?.data ?? bj;
    registry = Array.isArray(bd) ? (bd as BlockDef[]) : [];
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری بلوک‌ها.";
  }

  const active = sp.type ?? types[0]?.type ?? "";

  return (
    <div>
      <div className="page-head">
        <div><h1>بلوک‌های سایت</h1><p>بلوک‌های پیش‌فرض هر نوع صفحه — درگ‌اندروپ + فعال/غیرفعال</p></div>
      </div>
      {error ? <Alert tone="red">{error}</Alert> : <BlocksClient types={types} registry={registry} active={active} />}
    </div>
  );
}
