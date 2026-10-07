"use client";
import { useEffect, useState } from "react";
import { publicT, type PublicLocale } from "@/lib/i18n/public";
import { ContactForm } from "./ContactForm";
import { publicConfig } from "@/lib/runtime-config";

const API_BASE = publicConfig().apiUrl;

type FormFieldDef = {
  key: string;
  label: string;
  type: "text" | "email" | "tel" | "number" | "textarea" | "select" | "checkbox";
  required?: boolean;
  placeholder?: string;
  options?: string[];
  max_length?: number;
};

type FormDef = {
  name: string;
  slug: string;
  fields: FormFieldDef[];
  success_message?: string | null;
};

function missingRequired(fields: FormFieldDef[], value: Record<string, unknown>): boolean {
  return fields.some((f) => {
    if (!f.required) return false;
    const v = value[f.key];
    if (f.type === "checkbox") return v !== true;
    return v === undefined || v === null || v === "" || (Array.isArray(v) && v.length === 0);
  });
}

/**
 * WF-H10 — بلوک سایت `form`: فرم را با اسلاگ از endpoint عمومی می‌خواند،
 * فیلدهای دلخواهش را رندر می‌کند و به endpoint ثبت پاسخ می‌فرستد.
 *
 * رندرر این‌جا سبک است (بدون SchemaForm) تا ویرایشگر Joditِ پنل وارد بستهٔ
 * سایتِ عمومی نشود؛ سازندهٔ پنل همان SchemaForm را برای پیش‌نمایش زنده دارد.
 * برای بلوک `contact-form` قدیمی که فرم seed‌نشده دارد، به فرم تماس قدیمی
 * fallback می‌کند تا سایت نشکند.
 */
export function FormBlock({
  slug,
  title,
  locale = "fa",
  contactFallback = false,
  showPhone = true,
}: {
  slug: string;
  title?: string;
  locale?: PublicLocale;
  contactFallback?: boolean;
  showPhone?: boolean;
}) {
  const [form, setForm] = useState<FormDef | null>(null);
  const [notFound, setNotFound] = useState(false);
  const [value, setValue] = useState<Record<string, unknown>>({});
  const [website, setWebsite] = useState("");
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const t = (k: Parameters<typeof publicT>[1]) => publicT(locale, k);

  useEffect(() => {
    let alive = true;
    setNotFound(false);
    setForm(null);
    fetch(`${API_BASE}/v1/site/forms/${encodeURIComponent(slug)}`, { headers: { Accept: "application/json" } })
      .then(async (res) => {
        if (res.status === 404) {
          if (alive) setNotFound(true);
          return null;
        }
        const json = await res.json().catch(() => ({}));
        return (json?.data ?? json) as FormDef;
      })
      .then((def) => {
        if (alive && def) setForm(def);
      })
      .catch(() => {
        if (alive) setNotFound(true);
      });
    return () => { alive = false; };
  }, [slug]);

  if (notFound) {
    if (contactFallback) return <ContactForm title={title || t("block.contactForm")} showPhone={showPhone} />;
    return <div className="alert a-red" role="alert">{t("block.formUnavailable")}</div>;
  }

  if (!form) return <div className="card card-pad" aria-busy="true">{t("block.formLoading")}</div>;
  if (done) return <div className="alert a-green" role="status">{done}</div>;

  const set = (key: string, v: unknown) => setValue((prev) => ({ ...prev, [key]: v }));

  const send = async () => {
    if (missingRequired(form.fields, value)) {
      setError(t("block.formRequired"));
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const res = await fetch(`${API_BASE}/v1/site/forms/${encodeURIComponent(slug)}/submit`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ data: value, website: website || undefined }),
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(json?.message ?? t("block.formError"));
      setDone(json?.message ?? form.success_message ?? t("block.formSuccess"));
    } catch (e) {
      setError(e instanceof Error ? e.message : t("block.formError"));
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="card card-pad" aria-label={title || form.name}>
      <div className="card-title">{title || form.name}</div>
      {form.fields.map((f) => (
        <Field key={f.key} field={f} value={value[f.key]} onChange={(v) => set(f.key, v)} />
      ))}
      {/* honeypot ضداسپم — مخفی از انسان */}
      <input
        type="text" name="website" autoComplete="off" tabIndex={-1} aria-hidden
        value={website} onChange={(e) => setWebsite(e.target.value)} style={{ display: "none" }}
      />
      {error ? <div className="alert a-red" role="alert">{error}</div> : null}
      <button className="btn btn-primary" onClick={() => void send()} disabled={busy}>
        {busy ? t("block.formSending") : t("block.formSubmit")}
      </button>
    </section>
  );
}

function Field({
  field,
  value,
  onChange,
}: {
  field: FormFieldDef;
  value: unknown;
  onChange: (v: unknown) => void;
}) {
  const label = `${field.label}${field.required ? " *" : ""}`;

  if (field.type === "checkbox") {
    return (
      <div className="field">
        <label style={{ display: "flex", gap: 8, alignItems: "center" }}>
          <input type="checkbox" checked={value === true} onChange={(e) => onChange(e.target.checked)} />
          {label}
        </label>
      </div>
    );
  }

  if (field.type === "textarea") {
    return (
      <div className="field">
        <label>{label}</label>
        <textarea
          className="textarea" rows={4} required={field.required} maxLength={field.max_length}
          placeholder={field.placeholder}
          value={typeof value === "string" ? value : ""}
          onChange={(e) => onChange(e.target.value)}
        />
      </div>
    );
  }

  if (field.type === "select") {
    return (
      <div className="field">
        <label>{label}</label>
        <select className="select" required={field.required} value={typeof value === "string" ? value : ""} onChange={(e) => onChange(e.target.value)}>
          <option value="">— انتخاب کنید —</option>
          {(field.options ?? []).map((o) => <option key={o} value={o}>{o}</option>)}
        </select>
      </div>
    );
  }

  const numeric = field.type === "number";
  const ltr = field.type === "email" || field.type === "tel" || numeric;
  return (
    <div className="field">
      <label>{label}</label>
      <input
        className="input"
        type={field.type === "email" ? "email" : field.type === "tel" ? "tel" : numeric ? "number" : "text"}
        inputMode={numeric ? "numeric" : undefined}
        dir={ltr ? "ltr" : "auto"}
        style={ltr ? { textAlign: "left" } : undefined}
        required={field.required}
        maxLength={field.max_length}
        placeholder={field.placeholder}
        value={typeof value === "string" || typeof value === "number" ? String(value) : ""}
        onChange={(e) => onChange(e.target.value)}
      />
    </div>
  );
}
