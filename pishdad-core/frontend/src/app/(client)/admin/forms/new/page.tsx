import Link from "next/link";
import { serverOne } from "@/lib/server-api";
import type { BlockSchema } from "@/lib/domain";
import { FormBuilderClient } from "../FormBuilderClient";

/** WF-H10 — ساخت فرم جدید: SettingsForm (SchemaForm) + سازندهٔ فیلدها. */
export default async function NewFormPage() {
  const schema = await serverOne<BlockSchema>("/v1/admin/forms/schema").catch(() => null);

  return (
    <div>
      <div className="page-head">
        <div><h1>فرم جدید</h1><p>فیلدهای دلخواه را بسازید و مقصد ارسال را انتخاب کنید.</p></div>
        <div style={{ flex: 1 }} />
        <Link className="btn btn-ghost" href="/admin/forms">→ بازگشت</Link>
      </div>
      <FormBuilderClient settingsSchema={schema} />
    </div>
  );
}
