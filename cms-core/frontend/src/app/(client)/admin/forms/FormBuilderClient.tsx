"use client";
import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { SchemaForm } from "@/components/ui/SchemaForm";
import { Alert, Badge } from "@/components/ui/primitives";
import { jalali } from "@/lib/fa";
import { asPaginator, type BlockPropSchema, type BlockSchema, type FormItem, type FormSubmissionItem } from "@/lib/domain";
import { FormDeleteButton } from "./FormDeleteButton";

const FIELD_TYPES: Record<string, string> = {
  text: "متن",
  email: "ایمیل",
  tel: "تلفن",
  number: "عدد",
  textarea: "متن بلند",
  select: "انتخابی",
  checkbox: "بله/خیر",
};

const FALLBACK_SCHEMA: BlockSchema = {
  type: "object",
  required: ["name", "slug", "destination"],
  properties: {
    name: { type: "string", maxLength: 120 },
    slug: { type: "string", maxLength: 120 },
    destination: { type: "string", enum: ["ticket", "email"], default: "ticket" },
    recipients: { type: "string", maxLength: 500 },
    success_message: { type: "string", maxLength: 300 },
    active: { type: "boolean", default: true },
  },
};

const LABELS: Record<string, string> = {
  name: "نام فرم",
  slug: "اسلاگ",
  destination: "مقصد ارسال",
  recipients: "ایمیل‌های گیرنده (با کاما جدا کنید)",
  success_message: "پیام موفقیت",
  active: "فعال",
};

type FieldDraft = {
  key: string;
  label: string;
  type: string;
  required: boolean;
  placeholder: string;
  optionsText: string;
  maxLength: string;
};

function toDraft(f: FormItem["fields"][number]): FieldDraft {
  return {
    key: f.key,
    label: f.label,
    type: f.type,
    required: Boolean(f.required),
    placeholder: f.placeholder ?? "",
    optionsText: (f.options ?? []).join("، "),
    maxLength: f.max_length ? String(f.max_length) : "",
  };
}

function newDraft(): FieldDraft {
  return { key: "", label: "", type: "text", required: false, placeholder: "", optionsText: "", maxLength: "" };
}

export function FormBuilderClient({
  initialForm,
  settingsSchema,
}: {
  initialForm?: FormItem;
  settingsSchema?: BlockSchema | null;
}) {
  const toast = useToast();
  const router = useRouter();
  const schema = settingsSchema ?? FALLBACK_SCHEMA;

  const [settings, setSettings] = useState<Record<string, unknown>>(() => ({
    name: initialForm?.name ?? "",
    slug: initialForm?.slug ?? "",
    destination: initialForm?.destination ?? "ticket",
    recipients: (initialForm?.recipients ?? []).join("، "),
    success_message: initialForm?.success_message ?? "",
    active: initialForm?.active ?? true,
  }));
  const [fields, setFields] = useState<FieldDraft[]>(() =>
    initialForm?.fields?.length ? initialForm.fields.map(toDraft) : [toDraft({ key: "name", label: "نام", type: "text", required: true })],
  );
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [previewValue, setPreviewValue] = useState<Record<string, unknown>>({});

  const formId = initialForm?.id ?? null;
  const [submissions, setSubmissions] = useState<FormSubmissionItem[]>([]);
  const [subPage, setSubPage] = useState(1);
  const [subLast, setSubLast] = useState(1);
  const [subTotal, setSubTotal] = useState(0);

  const loadSubmissions = useCallback(async () => {
    if (!formId) return;
    try {
      const json = await authed<unknown>(`/v1/admin/forms/${formId}/submissions?page=${subPage}`);
      const p = asPaginator<FormSubmissionItem>(json);
      setSubmissions(p.data);
      setSubLast(p.last_page);
      setSubTotal(p.total);
    } catch {
      setSubmissions([]);
    }
  }, [formId, subPage]);

  useEffect(() => { void loadSubmissions(); }, [loadSubmissions]);

  const setField = (index: number, patch: Partial<FieldDraft>) =>
    setFields((prev) => prev.map((f, i) => (i === index ? { ...f, ...patch } : f)));

  const move = (index: number, dir: -1 | 1) =>
    setFields((prev) => {
      const next = [...prev];
      const target = index + dir;
      if (target < 0 || target >= next.length) return prev;
      [next[index], next[target]] = [next[target], next[index]];
      return next;
    });

  const save = async () => {
    const name = String(settings.name ?? "").trim();
    const slug = String(settings.slug ?? "").trim();
    if (!name) { setError("نام فرم الزامی است."); return; }
    const cleanFields = fields.map((f) => ({
      key: f.key.trim(),
      label: f.label.trim(),
      type: f.type,
      required: f.required,
      ...(f.placeholder.trim() ? { placeholder: f.placeholder.trim() } : {}),
      ...(f.type === "select" ? { options: f.optionsText.split(/[,،\n]/).map((s) => s.trim()).filter(Boolean) } : {}),
      ...(f.maxLength.trim() ? { max_length: Number(f.maxLength) } : {}),
    }));
    if (cleanFields.some((f) => !f.key || !f.label)) { setError("کلید و برچسب همهٔ فیلدها الزامی است."); return; }
    if (cleanFields.some((f) => f.type === "select" && (!f.options || f.options.length === 0))) {
      setError("فیلد انتخابی باید حداقل یک گزینه داشته باشد.");
      return;
    }

    setSaving(true);
    setError(null);
    const body = {
      name,
      ...(slug ? { slug } : {}),
      fields: cleanFields,
      destination: settings.destination ?? "ticket",
      recipients: String(settings.recipients ?? "").split(/[,،\n]/).map((s) => s.trim()).filter(Boolean),
      success_message: String(settings.success_message ?? "").trim(),
      active: Boolean(settings.active),
    };
    try {
      if (formId) {
        await authed(`/v1/admin/forms/${formId}`, { method: "PUT", body });
        toast("فرم ذخیره شد.", "ok");
        router.refresh();
      } else {
        const created = await authed<FormItem>("/v1/admin/forms", { method: "POST", body });
        toast("فرم ساخته شد.", "ok");
        router.push(`/admin/forms/${created.id}`);
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : "ذخیره ناموفق بود.");
    } finally {
      setSaving(false);
    }
  };

  const previewFields = fields.filter((f) => f.key.trim());
  const previewSchema: BlockSchema = (() => {
    const properties: Record<string, BlockPropSchema> = {};
    const required: string[] = [];
    for (const f of previewFields) {
      if (f.required) required.push(f.key);
      const max = f.maxLength.trim() ? Number(f.maxLength) : 0;
      properties[f.key] =
        f.type === "email" ? { type: "string", maxLength: 200 }
        : f.type === "tel" ? { type: "string", maxLength: 30 }
        : f.type === "number" ? { type: "number" }
        : f.type === "textarea" ? { type: "string", maxLength: max > 160 ? max : 2000 }
        : f.type === "select" ? { type: "string", enum: f.optionsText.split(/[,،\n]/).map((s) => s.trim()).filter(Boolean) }
        : f.type === "checkbox" ? { type: "boolean" }
        : { type: "string", maxLength: max || 120 };
    }
    return { type: "object", required, properties };
  })();
  const previewLabels: Record<string, string> = {};
  for (const f of previewFields) previewLabels[f.key] = f.label || f.key;

  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 16 }}>
      <section className="card card-pad">
        <div className="card-title">تنظیمات فرم</div>
        <SchemaForm schema={schema} value={settings} onChange={setSettings} labels={LABELS} />
      </section>

      <section className="card card-pad">
        <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
          <div className="card-title" style={{ margin: 0 }}>فیلدهای فرم</div>
          <div style={{ flex: 1 }} />
          <button type="button" className="btn btn-ghost btn-sm" onClick={() => setFields((p) => [...p, newDraft()])}>افزودن فیلد</button>
        </div>
        {fields.length === 0 ? <p style={{ color: "var(--text-muted)", fontSize: 13 }}>هنوز فیلدی اضافه نشده است.</p> : null}
        <div style={{ display: "flex", flexDirection: "column", gap: 10, marginBlockStart: 10 }}>
          {fields.map((f, i) => (
            <div key={i} className="card" style={{ padding: 10, border: "1px solid var(--border)" }}>
              <div className="grid c2" style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 8 }}>
                <div className="field"><label>کلید (انگلیسی)</label>
                  <input className="input" dir="ltr" value={f.key} onChange={(e) => setField(i, { key: e.target.value })} /></div>
                <div className="field"><label>برچسب</label>
                  <input className="input" value={f.label} onChange={(e) => setField(i, { label: e.target.value })} /></div>
                <div className="field"><label>نوع</label>
                  <select className="select" value={f.type} onChange={(e) => setField(i, { type: e.target.value })}>
                    {Object.entries(FIELD_TYPES).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                  </select></div>
                <div className="field"><label>راهنما (placeholder)</label>
                  <input className="input" value={f.placeholder} onChange={(e) => setField(i, { placeholder: e.target.value })} /></div>
              </div>
              {f.type === "select" ? (
                <div className="field"><label>گزینه‌ها (با کاما جدا کنید)</label>
                  <input className="input" value={f.optionsText} onChange={(e) => setField(i, { optionsText: e.target.value })} /></div>
              ) : null}
              <div style={{ display: "flex", alignItems: "center", gap: 10, flexWrap: "wrap" }}>
                <label style={{ display: "flex", gap: 6, alignItems: "center", fontSize: 13 }}>
                  <input type="checkbox" checked={f.required} onChange={(e) => setField(i, { required: e.target.checked })} /> الزامی
                </label>
                <div className="field" style={{ margin: 0, maxInlineSize: 140 }}><label>حداکثر طول</label>
                  <input className="input" type="number" dir="ltr" value={f.maxLength} onChange={(e) => setField(i, { maxLength: e.target.value })} /></div>
                <div style={{ flex: 1 }} />
                <button type="button" className="btn btn-ghost btn-sm" onClick={() => move(i, -1)} disabled={i === 0} aria-label="بالا">↑</button>
                <button type="button" className="btn btn-ghost btn-sm" onClick={() => move(i, 1)} disabled={i === fields.length - 1} aria-label="پایین">↓</button>
                <button type="button" className="btn btn-ghost btn-sm" onClick={() => setFields((p) => p.filter((_, x) => x !== i))}>حذف</button>
              </div>
            </div>
          ))}
        </div>
      </section>

      {previewFields.length ? (
        <section className="card card-pad">
          <div className="card-title">پیش‌نمایش فرم (SchemaForm)</div>
          <SchemaForm schema={previewSchema} value={previewValue} onChange={setPreviewValue} labels={previewLabels} />
        </section>
      ) : null}

      {error ? <Alert tone="red">{error}</Alert> : null}

      <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
        <button type="button" className="btn btn-primary" onClick={() => void save()} disabled={saving}>{saving ? "…" : "ذخیره فرم"}</button>
        {formId ? <a className="btn btn-ghost" href={`/api/forms/${formId}/export`}>دانلود پاسخ‌ها (CSV)</a> : null}
        {formId ? <FormDeleteButton formId={formId} name={String(settings.name ?? "")} redirect /> : null}
        <span style={{ fontSize: 12, color: "var(--text-muted)" }}>
          این فرم با بلوک «فرم» و اسلاگ <span dir="ltr">{String(settings.slug || "—")}</span> در صفحه‌ها قابل استفاده است.
          {previewFields.length ? ` (${previewFields.length} فیلد)` : ""}
        </span>
      </div>

      {formId ? (
        <section className="card card-pad">
          <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
            <div className="card-title" style={{ margin: 0 }}>پاسخ‌ها</div>
            <Badge tone="gray">{String(subTotal)}</Badge>
            <div style={{ flex: 1 }} />
            {subPage > 1 ? <button className="btn btn-ghost btn-sm" onClick={() => setSubPage((p) => Math.max(1, p - 1))}>قبلی</button> : null}
            {subPage < subLast ? <button className="btn btn-ghost btn-sm" onClick={() => setSubPage((p) => p + 1)}>بعدی</button> : null}
          </div>
          {submissions.length === 0 ? (
            <p style={{ color: "var(--text-muted)", fontSize: 13 }}>هنوز پاسخی ثبت نشده است.</p>
          ) : (
            <div className="table-wrap">
              <table className="tbl">
                <thead>
                  <tr>
                    <th>تاریخ</th>
                    {initialForm?.fields?.map((f) => <th key={f.key}>{f.label}</th>)}
                    <th>IP</th>
                  </tr>
                </thead>
                <tbody>
                  {submissions.map((s) => (
                    <tr key={s.id}>
                      <td>{jalali(s.created_at)}</td>
                      {initialForm?.fields?.map((f) => (
                        <td key={f.key}>{typeof s.payload?.[f.key] === "boolean" ? (s.payload[f.key] ? "بله" : "خیر") : String(s.payload?.[f.key] ?? "—")}</td>
                      ))}
                      <td><span dir="ltr">{s.ip ?? "—"}</span></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      ) : null}
    </div>
  );
}
