"use client";

import { useCallback, useMemo, useState } from "react";
import { saveConfig, testConnection, type SetupInput, type TestResult } from "./actions";

/**
 * نصب‌کنندهٔ وبِ فرانت (E54) — پاسِ نهایی.
 *
 * ## ⚠️ درسی که این فایل را بازنویسی کرد
 *
 * نسخهٔ قبلی با کلاس‌های **Tailwind** نوشته شده بود (`rounded-2xl`,
 * `border-neutral-200`, `text-neutral-500`, …). این پروژه هرگز Tailwind
 * نداشته: نه dependency، نه `tailwind.config.*`، نه `@tailwind`، نه postcss.
 * پس هر `className` فقط یک رشته بود و صفحه به HTMLِ **بی‌استایل** تبدیل می‌شد.
 *
 * حالا استایل از کلاس‌های معناییِ `globals.css` (`.card`، `.field`، `.input`،
 * `.btn`، `.alert`، `.badge`، `.dot`، …) به‌همراه بلوکِ `.setup-*` می‌آید که
 * همان توکن‌های رنگِ پروژه را می‌خوانند. پس در هر قالب/جهتِ روشنی درست دیده
 * می‌شود و هیچ فریم‌ورکِ CSS یا CDNای در میان نیست.
 *
 * ## اصولِ چیدمان (چرا این‌طور و نه آن‌طور)
 *
 * ۱. **دو ستون، نه یک ستونِ بلند.** فرم در ستونِ اصلی است و راهنما و کنش‌ها در
 *    ستونِ **چسبانِ** کناری؛ در موبایل روی هم می‌افتند. جداکردنِ «مرجعِ کار» از
 *    «کاری که باید بکنی» همان چیزی است که صفحه را خوانا می‌کند.
 * ۲. **راهنما دو دکمه است، نه دوازده.** «لوکال»/«سرور» کلِ فرم را یک‌جا پر
 *    می‌کنند؛ بقیهٔ کارِ راهنما فقط *نشان‌دادن* است، نه دکمه‌داشتن.
 * ۳. **نتیجه کنارِ دکمه.** پاسخِ آزمون/ذخیره همان‌جا زیرِ دکمه‌ها می‌آید، نه
 *    جایی که چشم نیست.
 *
 * ## قیدهای پروژه که رعایت شده
 *
 * - **هیچ `setState`/بارگذاری‌ای در بدنهٔ رندر نیست** (قانونِ ضدِ React #301 =
 *   حلقهٔ رندر و کرش). همه‌چیز رویدادمحور است، پس `useEffect` هم لازم نیست.
 * - **هیچ CDN/فریم‌ورکِ CSS**: آیکون‌ها SVGِ درون‌خطی‌اند.
 * - RTL و متنِ فارسی؛ مقدارهای لاتین `dir="ltr"`.
 */

type PresetId = "local" | "server";

/** مقادیرِ آمادهٔ هر وضعیت — آنچه واقعاً باید در فرم بنشیند. */
const PRESETS: Record<PresetId, Pick<SetupInput, "apiUrl" | "internalApiUrl" | "siteUrl" | "mediaUrl" | "locales" | "primaryLocale">> = {
  local: {
    apiUrl: "http://localhost:8080/api",
    internalApiUrl: "http://localhost:8080/api",
    siteUrl: "http://localhost:3000",
    mediaUrl: "http://localhost:8080",
    locales: "fa",
    primaryLocale: "fa",
  },
  server: {
    apiUrl: "https://api.example.com/api",
    internalApiUrl: "http://backend:8080/api",
    siteUrl: "https://example.com",
    mediaUrl: "https://api.example.com",
    locales: "fa",
    primaryLocale: "fa",
  },
};

/** برچسب‌های خوانای ردیف‌های مرجع + نامِ کلیدِ `.env` متناظرشان. */
const REF_ROWS: Array<{ key: keyof (typeof PRESETS)["local"]; label: string; env: string }> = [
  { key: "apiUrl", label: "نشانیِ API", env: "CMS_PUBLIC_API_URL" },
  { key: "internalApiUrl", label: "نشانیِ داخلی", env: "CMS_INTERNAL_API_URL" },
  { key: "siteUrl", label: "نشانیِ سایت", env: "CMS_SITE_URL" },
  { key: "mediaUrl", label: "رسانه", env: "CMS_PUBLIC_MEDIA_URL" },
  { key: "locales", label: "زبان‌ها", env: "CMS_SITE_LOCALES" },
  { key: "primaryLocale", label: "زبانِ پایه", env: "CMS_SITE_PRIMARY_LOCALE" },
];

/**
 * نگاشتِ فیلدِ فرم به کلیدِ `.env` — یک جا، تا هم بلوکِ `.env` و هم «کپیِ
 * راهنما» از آن بسازند و دو فهرستِ موازی کهنه نشود.
 */
const ENV_KEYS: Array<[keyof SetupInput, string]> = [
  ["apiUrl", "CMS_PUBLIC_API_URL"],
  ["internalApiUrl", "CMS_INTERNAL_API_URL"],
  ["siteUrl", "CMS_SITE_URL"],
  ["locales", "CMS_SITE_LOCALES"],
  ["primaryLocale", "CMS_SITE_PRIMARY_LOCALE"],
  ["mediaUrl", "CMS_PUBLIC_MEDIA_URL"],
  ["revalidateSecret", "REVALIDATE_SECRET"],
  ["vapidPublicKey", "CMS_PUBLIC_VAPID_PUBLIC_KEY"],
  ["siteId", "CMS_SITE_ID"],
];

/* ── آیکون‌های درون‌خطی (هیچ CDN) ───────────────────────────────── */

const STROKE = {
  fill: "none",
  stroke: "currentColor",
  strokeWidth: 1.8,
  strokeLinecap: "round" as const,
  strokeLinejoin: "round" as const,
};

function IconCheck() {
  return (
    <svg width="16" height="16" viewBox="0 0 20 20" aria-hidden="true">
      <path d="M4 10.5l4 4 8-8" {...STROKE} />
    </svg>
  );
}

function IconAlert() {
  return (
    <svg width="16" height="16" viewBox="0 0 20 20" aria-hidden="true">
      <path d="M10 3.2l7.5 13.3h-15L10 3.2z" {...STROKE} />
      <path d="M10 8.2v3.6M10 14.2v.6" {...STROKE} />
    </svg>
  );
}

function IconEye({ off = false }: { off?: boolean }) {
  return (
    <svg width="16" height="16" viewBox="0 0 20 20" aria-hidden="true">
      <path d="M2.5 10S5.5 4.8 10 4.8 17.5 10 17.5 10 14.5 15.2 10 15.2 2.5 10 2.5 10z" {...STROKE} />
      <circle cx="10" cy="10" r="2.4" {...STROKE} />
      {off ? <path d="M3.5 3.5l13 13" {...STROKE} /> : null}
    </svg>
  );
}

function IconChevron() {
  return (
    <svg className="setup-chev" width="16" height="16" viewBox="0 0 20 20" aria-hidden="true">
      <path d="M5.5 8l4.5 4.5L14.5 8" {...STROKE} />
    </svg>
  );
}

/* ── اجزای کوچک ─────────────────────────────────────────────────── */

/** فیلدِ فرم: برچسبِ `for`دار + نشانهٔ لازم/اختیاری + پیامِ خطا/هشدار + راهنما. */
function Field({
  id,
  label,
  required = false,
  optional = false,
  hint,
  error,
  warn,
  children,
}: {
  id: string;
  label: string;
  required?: boolean;
  optional?: boolean;
  /** راهنما یک خطِ کوتاه است؛ شناسه‌اش برای `aria-describedby` ثابت است. */
  hint: React.ReactNode;
  error?: string;
  warn?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="field">
      <label htmlFor={id}>
        {label}
        {required ? (
          <span className="setup-req" aria-hidden="true">
            *
          </span>
        ) : null}
        {optional ? <span className="setup-opt">اختیاری</span> : null}
      </label>
      {children}
      {error ? (
        <p id={`${id}-err`} className="setup-field-error">
          {error}
        </p>
      ) : null}
      {!error && warn ? <p className="setup-field-warn">{warn}</p> : null}
      <p id={`${id}-hint`} className="hint">
        {hint}
      </p>
    </div>
  );
}

/** سرِ یک بخشِ شماره‌دار. */
function Section({
  step,
  title,
  lead,
  children,
}: {
  step: number;
  title: string;
  lead: string;
  children: React.ReactNode;
}) {
  return (
    <section className="card card-pad">
      <header className="setup-card-head">
        <span className="setup-step" aria-hidden="true">
          {step}
        </span>
        <div>
          <h2>{title}</h2>
          <p>{lead}</p>
        </div>
      </header>
      {children}
    </section>
  );
}

/* ── اعتبارسنجیِ سبکِ کلاینت ─────────────────────────────────────── */

function isHttpUrl(raw: string): boolean {
  try {
    const url = new URL(raw.trim());
    return url.protocol === "http:" || url.protocol === "https:";
  } catch {
    return false;
  }
}

/**
 * فقط برای نشان‌دادنِ زودهنگامِ فیلدِ مشکل‌دار (`aria-invalid` + پیامِ کنارِ
 * همان فیلد). **منبعِ حقیقت همان `actions.ts` است** — اینجا فهرستِ زبان‌های
 * مجاز تکرار نمی‌شود (تکرار یعنی دو حقیقت که یکی کهنه می‌شود)؛ خطاهای مربوط به
 * «زبانِ ناشناخته» از پاسخِ سرور در جعبهٔ نتایج دیده می‌شوند.
 */
function clientErrors(form: SetupInput): Partial<Record<keyof SetupInput, string>> {
  const errors: Partial<Record<keyof SetupInput, string>> = {};

  if (!isHttpUrl(form.apiUrl)) errors.apiUrl = "نشانی باید با http:// یا https:// شروع شود.";
  if (form.internalApiUrl.trim() && !isHttpUrl(form.internalApiUrl)) errors.internalApiUrl = "نشانیِ داخلی معتبر نیست.";
  if (form.siteUrl.trim() && !isHttpUrl(form.siteUrl)) errors.siteUrl = "نشانیِ سایت معتبر نیست.";
  if (form.mediaUrl.trim() && !isHttpUrl(form.mediaUrl)) errors.mediaUrl = "نشانیِ رسانه معتبر نیست.";

  const locales = form.locales
    .split(",")
    .map((s) => s.trim())
    .filter(Boolean);
  if (locales.length === 0) errors.locales = "حداقل یک زبان لازم است.";

  const primary = form.primaryLocale.trim();
  if (!primary) errors.primaryLocale = "زبانِ پایه را وارد کنید.";
  else if (locales.length > 0 && !locales.includes(primary)) errors.primaryLocale = "زبانِ پایه باید یکی از زبان‌های فعال باشد.";

  if (!form.revalidateSecret.trim()) errors.revalidateSecret = "رازِ revalidate لازم است.";

  return errors;
}

/* ── فرمِ اصلی ──────────────────────────────────────────────────── */

export function SetupForm({ defaults, writable }: { defaults: SetupInput; writable: boolean }) {
  const [form, setForm] = useState<SetupInput>(defaults);
  const [preset, setPreset] = useState<PresetId>("local");
  const [testing, setTesting] = useState(false);
  const [saving, setSaving] = useState(false);
  const [test, setTest] = useState<TestResult | null>(null);
  const [save, setSave] = useState<{ ok: boolean; message: string; errors?: string[] } | null>(null);
  const [attempted, setAttempted] = useState(false);
  const [showAdvanced, setShowAdvanced] = useState(false);
  const [showEnv, setShowEnv] = useState(false);
  const [revealSecret, setRevealSecret] = useState(false);
  const [copied, setCopied] = useState<string | null>(null);

  // ⚠️ همهٔ `setState`ها داخلِ رویدادند — هیچ‌کدام در بدنهٔ رندر صدا زده
  // نمی‌شوند (قانونِ ضدِ React #301).
  const set = useCallback(<K extends keyof SetupInput>(key: K, value: SetupInput[K]) => {
    setForm((prev) => ({ ...prev, [key]: value }));
  }, []);

  const copy = useCallback(async (value: string, tag: string) => {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(tag);
      window.setTimeout(() => setCopied((current) => (current === tag ? null : current)), 1500);
    } catch {
      // بسترِ ناامن (http روی دامنهٔ غیرِ localhost) یا نبودِ اجازه.
      setCopied(null);
    }
  }, []);

  /** پر کردنِ کلِ فرم از یک پیش‌تنظیم. */
  const applyPreset = useCallback((id: PresetId) => {
    setPreset(id);
    setForm((prev) => ({ ...prev, ...PRESETS[id] }));
  }, []);

  const onTest = useCallback(async () => {
    setTesting(true);
    setTest(null);
    setSave(null);
    try {
      const result = await testConnection(form);
      setTest(result);
      // نشانیِ تصحیح‌شده را می‌نشانیم تا کاربر نفهمد اشتباه نوشته بوده — و
      // همان را در فرم می‌گذاریم تا ذخیره با نشانیِ کارآمد انجام شود.
      if (result.ok && result.apiUrl && result.apiUrl !== form.apiUrl.trim().replace(/\/+$/, "")) {
        set("apiUrl", result.apiUrl);
      }
    } finally {
      setTesting(false);
    }
  }, [form, set]);

  const onSave = useCallback(async () => {
    // خطاها را زودتر نشان بده و از یک درخواستِ بی‌ثمر جلوگیری کن؛ اعتبارسنجیِ
    // نهایی همچنان سمتِ سرور است.
    setAttempted(true);
    if (Object.keys(clientErrors(form)).length > 0) return;

    setSaving(true);
    setSave(null);
    setTest(null);
    try {
      const result = await saveConfig(form);
      setSave(result);
      if (result.ok) window.setTimeout(() => window.location.assign("/"), 1200);
    } finally {
      setSaving(false);
    }
  }, [form]);

  const errors = useMemo(() => clientErrors(form), [form]);
  /** خطا فقط پس از نخستین تلاشِ ذخیره نشان داده می‌شود (نه هنگامِ تایپ). */
  const shown = useCallback((key: keyof SetupInput) => (attempted ? errors[key] : undefined), [attempted, errors]);

  const done = Boolean(save?.ok);
  const busy = testing || saving;

  /** پیشنهادِ ریشهٔ رسانه از روی نشانیِ API، وقتی با هم نمی‌خوانند. */
  const suggestedMedia = useMemo(() => {
    let origin = "";
    try {
      origin = new URL(form.apiUrl.trim()).origin;
    } catch {
      origin = "";
    }
    return origin !== "" && origin !== form.mediaUrl.trim().replace(/\/+$/, "") ? origin : "";
  }, [form.apiUrl, form.mediaUrl]);

  const envBlock = useMemo(
    () =>
      ENV_KEYS.map(([key, name]) => [name, form[key].trim()] as const)
        .filter(([, value]) => value !== "")
        .map(([name, value]) => `${name}=${value}`)
        .join("\n"),
    [form],
  );

  const caseEnv = useMemo(
    () => REF_ROWS.map((row) => `${row.env}=${PRESETS[preset][row.key]}`).join("\n"),
    [preset],
  );

  const activePreset = PRESETS[preset];
  const apiErr = shown("apiUrl") ?? (test && !test.ok ? "اتصال برقرار نشد." : undefined);
  const mediaMissing = form.mediaUrl.trim() === "";

  /**
   * `aria-describedby`/`aria-invalid` برای یک ورودی. هر فیلد یک راهنما دارد،
   * پس شناسهٔ راهنما همیشه هست؛ شناسهٔ خطا فقط وقتی خطایی هست.
   */
  const aria = (id: string, err?: string) => ({
    "aria-describedby": err ? `${id}-err ${id}-hint` : `${id}-hint`,
    "aria-invalid": err ? true : undefined,
  });

  return (
    <div className="setup" dir="rtl">
      <div className="setup-wrap">
        {/* ── سرصفحه ─────────────────────────────────────────────── */}
        <header className="setup-head">
          <div>
            <p className="setup-eyebrow">نصبِ فرانت‌اند</p>
            <h1>اتصالِ سایت به بک‌اند</h1>
            <p className="setup-lead">
              فقط اطلاعاتِ بک‌اند را وارد کنید. پس از ذخیره، همین نسخهٔ بیلدشده بدونِ بازسازی با تنظیماتِ شما کار می‌کند.
            </p>
          </div>
          <span className={done ? "setup-status ok" : "setup-status"}>
            <span className={done ? "dot ok" : "dot warn"} aria-hidden="true" />
            {done ? "نصب کامل شد" : "در انتظارِ تنظیم"}
          </span>
        </header>

        {/* ستونِ اصلی = فرم، ستونِ کناری = راهنما/کنش‌ها. `onSubmit` کلیدِ Enter
            در فیلدها را به «ذخیره» وصل می‌کند. */}
        <form
          className="setup-grid"
          noValidate
          onSubmit={(e) => {
            e.preventDefault();
            void onSave();
          }}
        >
          {/* ══ ستونِ اصلی ═══════════════════════════════════════════ */}
          <div className="setup-main">
            {!writable ? (
              <div className="alert a-amber" role="alert">
                <IconAlert />
                <div>
                  <strong>فایلِ تنظیمات قابلِ نوشتن نیست.</strong>
                  <p className="setup-hint">
                    ذخیره از این صفحه ممکن نیست. فرم را پر کنید و مقدارها را از «مقادیرِ .env» در ستونِ کنار بردارید.
                  </p>
                </div>
              </div>
            ) : null}

            {/* ── بخشِ ۱: اتصال ──────────────────────────────────── */}
            <Section step={1} title="اتصال به بک‌اند" lead="نشانی‌هایی که مرورگرِ کاربران و سرورِ شما با آن‌ها به API می‌رسند.">
              <Field
                id="apiUrl"
                label="نشانیِ عمومیِ API"
                required
                error={apiErr}
                hint={
                  <>
                    باید از اینترنت در دسترس باشد. اگر <code>/api</code> را ننویسید، «آزمونِ اتصال» خودش پیدایش می‌کند.
                  </>
                }
              >
                <input
                  id="apiUrl"
                  className="input"
                  dir="ltr"
                  inputMode="url"
                  autoComplete="off"
                  spellCheck={false}
                  placeholder="https://api.example.com/api"
                  value={form.apiUrl}
                  onChange={(e) => set("apiUrl", e.target.value)}
                  {...aria("apiUrl", apiErr)}
                />
              </Field>

              <Field
                id="internalApiUrl"
                label="نشانیِ داخلیِ API"
                optional
                error={shown("internalApiUrl")}
                hint="برای رندرِ سمتِ سرور. در داکر نامِ سرویس است تا ترافیک از اینترنت دور بزند — اگر خالی بماند از نشانیِ عمومی استفاده می‌شود."
              >
                <input
                  id="internalApiUrl"
                  className="input"
                  dir="ltr"
                  inputMode="url"
                  autoComplete="off"
                  spellCheck={false}
                  placeholder="http://backend:8080/api"
                  value={form.internalApiUrl}
                  onChange={(e) => set("internalApiUrl", e.target.value)}
                  {...aria("internalApiUrl", shown("internalApiUrl"))}
                />
                <button type="button" className="setup-linkbtn" onClick={() => set("internalApiUrl", form.apiUrl)}>
                  همان نشانیِ عمومی
                </button>
              </Field>
            </Section>

            {/* ── بخشِ ۲: هویتِ سایت ─────────────────────────────── */}
            <Section step={2} title="هویتِ سایت" lead="نشانیِ عمومیِ سایت و جایی که تصویرها از آن سرو می‌شوند.">
              <Field
                id="siteUrl"
                label="نشانیِ عمومیِ سایت"
                required
                error={shown("siteUrl")}
                hint="در canonical، Open Graph، sitemap و llms.txt استفاده می‌شود."
              >
                <input
                  id="siteUrl"
                  className="input"
                  dir="ltr"
                  inputMode="url"
                  autoComplete="off"
                  spellCheck={false}
                  placeholder="https://example.com"
                  value={form.siteUrl}
                  onChange={(e) => set("siteUrl", e.target.value)}
                  {...aria("siteUrl", shown("siteUrl"))}
                />
              </Field>

              {/*
                ⚠️ این فیلد عمداً بیرون از بخشِ «پیشرفته» و همین‌جا است. تصویرها و
                آپلودها روی **بک‌اند** ذخیره می‌شوند و نشانی‌شان از این پایه ساخته
                می‌شود؛ خالی‌بودنش **گالری** را بی‌سروصدا خالی می‌کند و علتش از
                بیرون قابلِ حدس نیست. پس باید جلوی چشم باشد.
              */}
              <Field
                id="mediaUrl"
                label="نشانیِ دارایی‌های رسانه‌ای"
                required
                error={shown("mediaUrl")}
                warn={mediaMissing ? "این مقدار خالی است — گالری و تصویرهای آپلودی خالی می‌مانند." : undefined}
                hint={
                  <>
                    تصویرها و فایل‌های آپلودی روی <strong>بک‌اند</strong> ذخیره می‌شوند، پس ریشهٔ بک‌اند است — بدونِ{" "}
                    <code>/api</code>. <strong>خالی بماند، گالری و تصاویر خالی می‌مانند.</strong>
                  </>
                }
              >
                <div className="setup-input-row">
                  <input
                    id="mediaUrl"
                    className="input"
                    dir="ltr"
                    inputMode="url"
                    autoComplete="off"
                    spellCheck={false}
                    placeholder="http://localhost:8080"
                    value={form.mediaUrl}
                    onChange={(e) => set("mediaUrl", e.target.value)}
                    {...aria("mediaUrl", shown("mediaUrl"))}
                  />
                  {suggestedMedia ? (
                    <button
                      type="button"
                      className="setup-suggest"
                      onClick={() => set("mediaUrl", suggestedMedia)}
                      title="ریشهٔ بک‌اند از روی نشانیِ API"
                    >
                      بگذار {suggestedMedia}
                    </button>
                  ) : null}
                </div>
              </Field>
            </Section>

            {/* ── بخشِ ۳: زبان و امنیت ───────────────────────────── */}
            <Section step={3} title="زبان و امنیت" lead="زبان‌های سایت و کلیدِ مشترکی که با بک‌اند پاک‌سازیِ کش را امضا می‌کند.">
              <div className="form-grid">
                <Field
                  id="locales"
                  label="زبان‌های فعال"
                  required
                  error={shown("locales")}
                  hint="جداشده با کاما، مثل fa,en."
                >
                  <input
                    id="locales"
                    className="input"
                    dir="ltr"
                    autoComplete="off"
                    spellCheck={false}
                    placeholder="fa,en"
                    value={form.locales}
                    onChange={(e) => set("locales", e.target.value)}
                    {...aria("locales", shown("locales"))}
                  />
                </Field>
                <Field
                  id="primaryLocale"
                  label="زبانِ پایه"
                  required
                  error={shown("primaryLocale")}
                  hint="بدونِ پیشوند در URL می‌آید."
                >
                  <input
                    id="primaryLocale"
                    className="input"
                    dir="ltr"
                    autoComplete="off"
                    spellCheck={false}
                    placeholder="fa"
                    value={form.primaryLocale}
                    onChange={(e) => set("primaryLocale", e.target.value)}
                    {...aria("primaryLocale", shown("primaryLocale"))}
                  />
                </Field>
              </div>

              <Field
                id="revalidateSecret"
                label="رازِ revalidate"
                required
                error={shown("revalidateSecret")}
                hint={
                  <>
                    در <code>.env</code> بک‌اند، کلیدِ <code>REVALIDATE_SECRET</code>. باید دقیقاً یکی باشد تا پاک‌سازیِ کش کار
                    کند. <strong>هرگز به مرورگر فرستاده نمی‌شود.</strong>
                  </>
                }
              >
                <div className="setup-input-row">
                  <input
                    id="revalidateSecret"
                    className="input"
                    dir="ltr"
                    type={revealSecret ? "text" : "password"}
                    autoComplete="new-password"
                    spellCheck={false}
                    placeholder="همان REVALIDATE_SECRET بک‌اند"
                    value={form.revalidateSecret}
                    onChange={(e) => set("revalidateSecret", e.target.value)}
                    {...aria("revalidateSecret", shown("revalidateSecret"))}
                  />
                  <button
                    type="button"
                    className="setup-icon-btn"
                    onClick={() => setRevealSecret((v) => !v)}
                    aria-label={revealSecret ? "پنهان‌کردنِ راز" : "نمایشِ راز"}
                  >
                    <IconEye off={revealSecret} />
                  </button>
                </div>
              </Field>

              {/* افشاگرِ پیشرفته — `aria-expanded` + `aria-controls` برای کیبورد/صفحه‌خوان. */}
              <button
                type="button"
                className="setup-disclosure"
                aria-expanded={showAdvanced}
                aria-controls="setup-advanced"
                onClick={() => setShowAdvanced((v) => !v)}
              >
                <span>تنظیماتِ پیشرفته (اختیاری)</span>
                <IconChevron />
              </button>

              {showAdvanced ? (
                <div id="setup-advanced">
                  <Field
                    id="vapidPublicKey"
                    label="کلیدِ عمومیِ Web Push"
                    optional
                    hint="برای اعلان‌های مرورگر. خالی بماند، اعلان‌ها خاموش می‌مانند."
                  >
                    <input
                      id="vapidPublicKey"
                      className="input"
                      dir="ltr"
                      autoComplete="off"
                      spellCheck={false}
                      value={form.vapidPublicKey}
                      onChange={(e) => set("vapidPublicKey", e.target.value)}
                      {...aria("vapidPublicKey")}
                    />
                  </Field>
                  <Field
                    id="siteId"
                    label="شناسهٔ سایت"
                    optional
                    hint="فقط در نصب‌های چندسایتی."
                  >
                    <input
                      id="siteId"
                      className="input"
                      dir="ltr"
                      autoComplete="off"
                      spellCheck={false}
                      value={form.siteId}
                      onChange={(e) => set("siteId", e.target.value)}
                      {...aria("siteId")}
                    />
                  </Field>
                </div>
              ) : null}
            </Section>
          </div>

          {/* ══ ستونِ کناری: راهنما + کنش‌ها ═══════════════════════ */}
          <aside className="setup-side">
            {/* راهنمای سریع: دو پیش‌تنظیم، نه دوازده دکمه */}
            <section className="card card-pad">
              <h2 className="card-title">راهنمای سریع</h2>
              <p className="setup-hint">
                وضعیتت را انتخاب کن تا کلِ فرم یک‌جا پر شود؛ بعد فقط رازِ revalidate را بگذار.
              </p>

              <div className="setup-presets" role="group" aria-label="انتخابِ وضعیتِ نصب">
                {(["local", "server"] as const).map((id) => (
                  <button
                    key={id}
                    type="button"
                    className={preset === id ? "setup-preset on" : "setup-preset"}
                    aria-pressed={preset === id}
                    onClick={() => applyPreset(id)}
                  >
                    {id === "local" ? "اجرای لوکال" : "اجرای روی سرور"}
                  </button>
                ))}
              </div>

              <div>
                {REF_ROWS.map((row) => (
                  <div className="setup-ref" key={row.key}>
                    <span className="setup-ref-label">{row.label}</span>
                    <code dir="ltr" title={activePreset[row.key]}>
                      {activePreset[row.key]}
                    </code>
                  </div>
                ))}
              </div>

              <button
                type="button"
                className="setup-linkbtn"
                onClick={() => void copy(caseEnv, "case")}
                title="کپیِ همهٔ مقدارها به شکلِ خطوطِ .env"
              >
                {copied === "case" ? "کپی شد ✓" : "کپیِ همهٔ این مقدارها"}
              </button>

              <p className="setup-note">
                {preset === "local"
                  ? "همه‌چیز روی همین ماشین است؛ از localhost استفاده کن."
                  : "داخلِ کانتینر، localhost خودِ کانتینر است نه میزبان. برای نشانیِ داخلی از نامِ سرویس (مثل backend) یا host.docker.internal استفاده کن."}
              </p>
            </section>

            {/* کنش‌ها + نتیجه‌ها */}
            <section className="card card-pad">
              <div className="setup-actions">
                <button type="submit" className="btn btn-primary btn-block" disabled={busy || !writable || done}>
                  {saving ? "در حالِ ذخیره…" : "ذخیره و پایانِ نصب"}
                </button>
                <button type="button" className="btn btn-ghost btn-block" disabled={busy} onClick={() => void onTest()}>
                  {testing ? "در حالِ آزمون…" : "آزمونِ اتصال"}
                </button>
              </div>

              <p className="setup-hint">
                پیش از ذخیره، اتصال واقعاً آزموده می‌شود؛ اگر برقرار نشود چیزی نوشته نمی‌شود.
              </p>

              {/* نتیجه‌ها همین‌جا، کنارِ دکمه‌ای که زده شد — نه پایینِ صفحه. */}
              <div className="setup-results" aria-live="polite">
                {done ? (
                  <div className="alert a-green" role="status">
                    <IconCheck />
                    <div>
                      <strong>نصب کامل شد.</strong>
                      <p className="setup-hint">در حال رفتن به سایت…</p>
                    </div>
                  </div>
                ) : null}

                {test ? (
                  <div className={test.ok ? "alert a-green" : "alert a-red"} role={test.ok ? "status" : "alert"}>
                    {test.ok ? <IconCheck /> : <IconAlert />}
                    <span>{test.message}</span>
                  </div>
                ) : null}

                {save && !save.ok ? (
                  <div className="alert a-red" role="alert">
                    <IconAlert />
                    <div>
                      <strong>{save.message}</strong>
                      {save.errors && save.errors.length > 0 ? (
                        <ul className="setup-errors">
                          {save.errors.map((e) => (
                            <li key={e}>{e}</li>
                          ))}
                        </ul>
                      ) : null}
                    </div>
                  </div>
                ) : null}
              </div>

              <div className="setup-env-toggle">
                <button
                  type="button"
                  className="setup-linkbtn"
                  aria-expanded={showEnv}
                  aria-controls="setup-env"
                  onClick={() => setShowEnv((v) => !v)}
                >
                  {showEnv ? "پنهان‌کردنِ مقادیرِ .env" : "نمایشِ مقادیرِ .env"}
                </button>
                {showEnv ? (
                  <div>
                    <pre id="setup-env" className="setup-env" dir="ltr">
                      {envBlock || "# هیچ مقداری وارد نشده"}
                    </pre>
                    <button type="button" className="setup-linkbtn" onClick={() => void copy(envBlock, "env")}>
                      {copied === "env" ? "کپی شد ✓" : "کپیِ مقادیرِ .env"}
                    </button>
                  </div>
                ) : null}
              </div>
            </section>
          </aside>
        </form>
      </div>
    </div>
  );
}
