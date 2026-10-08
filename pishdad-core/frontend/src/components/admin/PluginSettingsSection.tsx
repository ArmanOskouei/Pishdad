"use client";

import { useCallback, useEffect, useState } from "react";
import { SchemaForm } from "@/components/ui/SchemaForm";
import { Alert, Badge, EmptyState, SaveBar, Skeleton } from "@/components/ui/primitives";
import { authed } from "@/lib/auth";
import type { BlockSchema } from "@/lib/domain";

/**
 * K6.7 — کارت‌های تنظیمات افزونه‌ها در صفحهٔ `/admin/settings`.
 *
 * این کامپوننت **محل سوار شدن** سه‌تکهٔ K6.7 است. قبل از آن دو تکه آماده بودند
 * ولی هیچ‌کدام رندر نمی‌شدند: `ManifestRegistry::pluginSettingsSchemas()` اسکیما
 * می‌داد، `SchemaForm` فرم را می‌کشید، و بینشان هیچ مسیری نبود. نتیجه همان چیزی بود
 * که K6.10 به خاطرش کارت جعلی را حذف کرد — ساختار بدون رفتار.
 *
 * ## چرا `useEffect` و نه شرط در بدنهٔ رندر
 *
 * قانون پروژه: هر بارگذاری داده در `useEffect` است. شرطی مثل
 * `if (!loaded) void load()` در بدنهٔ رندر یعنی حلقهٔ رندر و خطای Minified
 * React #301 که کل صفحه را می‌کُشت.
 */

type PluginSchema = {
  slug: string;
  key: string;
  title_fa: string;
  group: string;
  schema: BlockSchema;
  source?: string;
};

type SettingsResponse = {
  schemas?: PluginSchema[];
  values?: Record<string, Record<string, unknown>>;
};

type FieldErrors = Record<string, string>;

/** یک کارت: یک افزونه، یک فرم. */
function PluginSettingsCard({
  entry,
  initial,
  onSaved,
}: {
  entry: PluginSchema;
  initial: Record<string, unknown>;
  onSaved: (slug: string, values: Record<string, unknown>) => void;
}) {
  const [value, setValue] = useState<Record<string, unknown>>(initial);
  const [dirty, setDirty] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});

  // مقدار اولیه از بیرون می‌آید (بعد از refetch والد). بدون این، کارتی که دیرتر
  // رندر می‌شود هیچ‌وقت مقدار ذخیره‌شده را نشان نمی‌دهد.
  useEffect(() => {
    setValue(initial);
    setDirty(false);
  }, [initial]);

  const save = useCallback(async () => {
    setBusy(true);
    setError(null);
    setFieldErrors({});
    try {
      await authed(`/v1/admin/plugins/settings/${encodeURIComponent(entry.slug)}`, {
        method: "PUT",
        body: value,
      });
      onSaved(entry.slug, value);
      setDirty(false);
    } catch (e) {
      // خطای اعتبارسنجی کلید-به-کلید می‌آید. اولی زیر فرم، بقیه کنار فیلد.
      const err = e as { status?: number; json?: unknown; message?: string };
      const payload = (err.json ?? {}) as { errors?: FieldErrors };
      const fieldMap = payload.errors ?? {};
      const keys = Object.keys(fieldMap);
      if (keys.length > 0) {
        setFieldErrors(fieldMap);
        setError("بعضی فیلدها مشکل دارند — زیرشان را ببینید.");
      } else {
        setError(err.message ?? "ذخیرهٔ تنظیمات انجام نشد.");
      }
    } finally {
      setBusy(false);
    }
  }, [entry.slug, onSaved, value]);

  return (
    <section className="card" aria-labelledby={`ps-${entry.slug}-${entry.key}`}>
      <div className="card-head">
        <h3 id={`ps-${entry.slug}-${entry.key}`}>{entry.title_fa}</h3>
        <Badge tone="violet">{entry.key}</Badge>
      </div>
      <p className="muted">
        افزونه: <code>{entry.slug}</code>
        {entry.group ? ` — گروه: ${entry.group}` : ""}
      </p>

      {error ? <Alert tone="red">{error}</Alert> : null}

      <SchemaForm
        schema={entry.schema}
        value={value}
        labels={undefined}
        onChange={(next) => {
          setValue(next);
          setDirty(true);
        }}
      />

      {Object.entries(fieldErrors).map(([k, msg]) => (
        <p key={k} className="muted" role="alert" style={{ color: "var(--danger)" }}>
          {k}: {typeof msg === "string" ? msg : Object.values(msg).join("، ")}
        </p>
      ))}

      {/* `SaveBar` وقتی پدیده است نمایش داده می‌شود — نه همیشه. یک نوار
          ذخیرهٔ همیشه‌حاضر برای فرمی که هنوز تغییری نکرده، فقط نویز است. */}
      {dirty ? (
        <SaveBar
          dirtyText="تغییرات ذخیره‌نشده"
          onCancel={() => {
            setValue(initial);
            setDirty(false);
            setFieldErrors({});
            setError(null);
          }}
          onReset={() => {
            setValue(initial);
            setDirty(false);
            setFieldErrors({});
            setError(null);
          }}
          onSave={() => {
            // بدون این نگهبان، کلیک دوباره حین درخواست، دو `PUT` می‌فرستاد و
            // دو نوشتن روی یک ردیف اتفاق می‌افتاد.
            if (busy) return;
            void save();
          }}
        />
      ) : null}
    </section>
  );
}

export function PluginSettingsSection() {
  const [data, setData] = useState<SettingsResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  // وابستگی خالی عمدی: بارگذاری یک‌بار در mount. رفرش دستی نداریم چون صفحهٔ
  // تنظیمات خودش با هر ناوبری دوباره mount می‌شود.
  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        const json = await authed<SettingsResponse>("/v1/admin/plugins/settings");
        if (!cancelled) setData(json);
      } catch (e) {
        // نبودن پرمیشن (`settings.view`) اینجا ۴۰۳ می‌دهد. نمایش «تنظیماتی
        // نیست» برای مدیری که حق ندارد صادقانه‌تر از یک بنر قرمز است: او
        // واقعاً چیزی نمی‌بیند و قرار هم نیست.
        if (!cancelled) {
          setError(e instanceof Error ? e.message : "بارگذاری تنظیمات افزونه‌ها انجام نشد.");
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  if (error) {
    return (
      <section className="card">
        <h3>تنظیمات افزونه‌ها</h3>
        <Alert tone="amber">{error}</Alert>
      </section>
    );
  }

  if (data === null) {
    return (
      <section className="card">
        <h3>تنظیمات افزونه‌ها</h3>
        <Skeleton />
      </section>
    );
  }

  const schemas = Array.isArray(data.schemas) ? data.schemas : [];
  const values = data.values ?? {};

  if (schemas.length === 0) {
    return (
      <section className="card">
        <h3>تنظیمات افزونه‌ها</h3>
        <EmptyState
          title="افزونهٔ تنظیم‌داری فعال نیست"
          hint="افزونه‌ای که فرم تنظیمات اعلام کند اینجا نمایش داده می‌شود."
        />
      </section>
    );
  }

  return (
    <>
      <h3>تنظیمات افزونه‌ها</h3>
      {schemas.map((entry) => (
        <PluginSettingsCard
          key={`${entry.slug}:${entry.key}`}
          entry={entry}
          initial={values[entry.slug] ?? {}}
          onSaved={(slug, next) => {
            setData((prev) =>
              prev
                ? { ...prev, values: { ...(prev.values ?? {}), [slug]: next } }
                : prev,
            );
          }}
        />
      ))}
    </>
  );
}
