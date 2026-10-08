"use client";
import { useState } from "react";
import type { BlockSchema } from "@/lib/domain";
import { MediaPicker } from "./MediaPicker";
import { RichText } from "./RichText";

/**
 * SchemaForm مشترک: رندر فرم از JSON Schema بلوک/ویجت (همان قراردادی که
 * GET /api/v1/admin/blocks/schema و GET /api/v1/admin/widgets/schema می‌دهند).
 * انواع پشتیبانی: string/enum/integer/number/boolean/array(media_ids)/media(image_id/media_id).
 * labels (از ui.labels ویجت) = برچسب فارسی هر فیلد؛ وگرنه خود کلید.
 */
export function SchemaForm({
  schema, value, onChange, labels,
}: {
  schema: BlockSchema;
  value: Record<string, unknown>;
  onChange: (next: Record<string, unknown>) => void;
  labels?: Record<string, string>;
}) {
  const [pickerFor, setPickerFor] = useState<string | null>(null);
  const set = (key: string, v: unknown) => onChange({ ...value, [key]: v });
  const props = schema.properties ?? {};
  const required = new Set(schema.required ?? []);
  const labelFor = (key: string) => `${labels?.[key] ?? key}${required.has(key) ? " *" : ""}`;

  if (Object.keys(props).length === 0) {
    return <p style={{ fontSize: 13, color: "var(--text-muted)" }}>این مورد تنظیم خاصی ندارد.</p>;
  }

  return (
    <div>
      {Object.entries(props).map(([key, p]) => {
        const label = labelFor(key);
        // فیلد رسانه‌ای تکی (image_id / media_id)
        if ((key === "media_id" || key === "image_id") && (p.type === "integer" || !p.type)) {
          const v = typeof value[key] === "number" ? (value[key] as number) : 0;
          return (
            <div className="field" key={key}>
              <label>{label}</label>
              <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
                <span className="chip">{v > 0 ? `فایل #${v}` : "انتخاب نشده"}</span>
                <button type="button" className="btn btn-ghost btn-sm" onClick={() => setPickerFor(key)}>انتخاب تصویر…</button>
                {v > 0 ? <button type="button" className="btn btn-ghost btn-sm" onClick={() => set(key, 0)}>حذف</button> : null}
              </div>
            </div>
          );
        }
        // گالری (media_ids)
        if (key === "media_ids" && p.type === "array") {
          const v = Array.isArray(value[key]) ? (value[key] as number[]) : [];
          return (
            <div className="field" key={key}>
              <label>{label} ({v.length} مورد)</label>
              <div style={{ display: "flex", gap: 6, flexWrap: "wrap", marginBlockEnd: 6 }}>
                {v.map((id) => <span className="chip" key={id}>#{id}<button type="button" onClick={() => set(key, v.filter((x) => x !== id))} aria-label={`حذف ${id}`}>✕</button></span>)}
              </div>
              <button type="button" className="btn btn-ghost btn-sm" onClick={() => setPickerFor(key)}>افزودن تصاویر…</button>
            </div>
          );
        }
        if (p.enum) {
          return (
            <div className="field" key={key}>
              <label>{label}</label>
              <select className="select" value={String(value[key] ?? p.default ?? p.enum[0])} onChange={(e) => set(key, e.target.value)}>
                {p.enum.map((o) => <option key={String(o)} value={String(o)}>{String(o)}</option>)}
              </select>
            </div>
          );
        }
        if (p.type === "boolean") {
          const v = Boolean(value[key] ?? p.default ?? false);
          return (
            <div className="field" key={key}>
              <label>{label}</label>
              <button type="button" role="switch" aria-checked={v} className="switch" aria-label={key} onClick={() => set(key, !v)} />
            </div>
          );
        }
        if (p.type === "integer" || p.type === "number") {
          return (
            <div className="field" key={key}>
              <label>{label}</label>
              <input
                className="input" type="number" dir="ltr" style={{ textAlign: "left" }}
                min={p.minimum} max={p.maximum}
                value={value[key] as number ?? p.default ?? ""}
                onChange={(e) => set(key, e.target.value === "" ? undefined : Number(e.target.value))}
              />
            </div>
          );
        }
        // بدنه ریچ‌تکست (بلوک text) → ویرایشگر Jodit (RTL/fa + allowlist).
        if (key === "body") {
          return (
            <RichText
              key={key}
              label={label}
              value={typeof value[key] === "string" ? (value[key] as string) : ""}
              onChange={(html) => set(key, html)}
            />
          );
        }
        // رشته: متن بلند → textarea
        const long = (p.maxLength ?? 0) > 160 || key === "description" || key === "subtitle";
        if (long) {
          return (
            <div className="field" key={key}>
              <label>{label}</label>
              <textarea
                className="textarea" rows={4} maxLength={p.maxLength}
                value={typeof value[key] === "string" ? (value[key] as string) : ""}
                onChange={(e) => set(key, e.target.value)}
              />
              {p.maxLength ? <span className="hint">حداکثر {p.maxLength} نویسه</span> : null}
            </div>
          );
        }
        return (
          <div className="field" key={key}>
            <label>{label}</label>
            <input
              className="input" type="text" maxLength={p.maxLength} dir="auto"
              value={typeof value[key] === "string" || typeof value[key] === "number" ? String(value[key]) : ""}
              onChange={(e) => set(key, e.target.value)}
            />
          </div>
        );
      })}
      {pickerFor ? (
        <MediaPicker
          open
          multiple={pickerFor === "media_ids"}
          selected={
            pickerFor === "media_ids"
              ? (Array.isArray(value[pickerFor]) ? (value[pickerFor] as number[]) : [])
              : (typeof value[pickerFor] === "number" && (value[pickerFor] as number) > 0 ? [value[pickerFor] as number] : [])
          }
          onChange={(ids) => set(pickerFor, pickerFor === "media_ids" ? ids : (ids[0] ?? 0))}
          onClose={() => setPickerFor(null)}
        />
      ) : null}
    </div>
  );
}
