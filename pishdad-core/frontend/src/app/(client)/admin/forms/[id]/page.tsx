import Link from "next/link";
import { serverOne } from "@/lib/server-api";
import type { BlockSchema, FormItem } from "@/lib/domain";
import { Alert } from "@/components/ui/primitives";
import { FormBuilderClient } from "../FormBuilderClient";

/** WF-H10 — ویرایش فرم: تنظیمات + فیلدها + پاسخ‌های ثبت‌شده. */
export default async function EditFormPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  let form: FormItem | null = null;
  let schema: BlockSchema | null = null;
  let error: string | null = null;
  try {
    const [f, s] = await Promise.all([
      serverOne<FormItem>(`/v1/admin/forms/${id}`),
      serverOne<BlockSchema>("/v1/admin/forms/schema"),
    ]);
    form = f;
    schema = s;
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری فرم.";
  }

  if (error || !form) {
    return (
      <div>
        <div className="page-head"><div><h1>ویرایش فرم</h1></div><div style={{ flex: 1 }} /><Link className="btn btn-ghost" href="/admin/forms">→ بازگشت</Link></div>
        <Alert tone="red">{error ?? "فرم یافت نشد."}</Alert>
      </div>
    );
  }

  return (
    <div>
      <div className="page-head">
        <div><h1>ویرایش فرم</h1><p><span dir="ltr">{form.slug}</span></p></div>
        <div style={{ flex: 1 }} />
        <Link className="btn btn-ghost" href="/admin/forms">→ بازگشت</Link>
      </div>
      <FormBuilderClient initialForm={form} settingsSchema={schema} />
    </div>
  );
}
