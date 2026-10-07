"use client";

import { useCallback, useMemo, useState } from "react";
import { saveConfig, testConnection, type SetupInput, type TestResult } from "./actions";

/**
 * نصب‌کنندهٔ وبِ فرانت.
 *
 * ## اصولِ چیدمان (چرا این‌طور و نه آن‌طور)
 *
 * ۱. **دو ستون، نه یک ستونِ بلند.** فرم در ستونِ اصلی است و راهنما و کنش‌ها در
 *    ستونِ کناریِ **چسبان**. پیش‌تر راهنما بالای فرم بود و با دوازده دکمه فرم را
 *    زیرِ خودش خفه می‌کرد؛ کاربر نمی‌فهمید کجا باید چه کند. جدا کردنِ «مرجع» از
 *    «کاری که باید بکنی» همان چیزی است که صفحه را خوانا می‌کند.
 *
 * ۲. **راهنما دو دکمه است، نه دوازده.** به‌جای دکمه در هر ردیف، دو پیش‌تنظیم
 *    («لوکال» و «سرور») کلِّ فرم را یک‌جا پر می‌کنند. بقیهٔ کارِ راهنما فقط
 *    *نشان دادن* است، نه دکمه‌داشتن.
 *
 * ۳. **یک کارت برای هر موضوع**، با تیترِ کوتاه و یک خط توضیح — به‌جای
 *    `fieldset`های تودرتو.
 *
 * ۴. **کنش‌ها همیشه در دسترس.** دکمهٔ اصلی در ستونِ چسبان است، پس در هر جای
 *    صفحه که باشی می‌بینی‌اش و لازم نیست اسکرول کنی.
 *
 * ## قیدهای پروژه که رعایت شده
 *
 * - **هیچ `setState`/بارگذاری‌ای در بدنهٔ رندر نیست** (قانون: خطای React #301 =
 *   حلقهٔ رندر و کرش). همه‌چیز رویدادمحور است، پس `useEffect` هم لازم نیست.
 * - **هیچ CDN خارجی**: آیکون‌ها SVG درون‌خطی‌اند.
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

/** برچسب‌های خوانا برای نمایشِ مقدارهای پیش‌تنظیم. */
const PRESET_LABELS: Array<{ key: keyof typeof PRESETS.local; label: string; dir?: "ltr" }> = [
  { key: "apiUrl", label: "نشانیِ API", dir: "ltr" },
  { key: "internalApiUrl", label: "نشانیِ داخلی", dir: "ltr" },
  { key: "siteUrl", label: "نشانیِ سایت", dir: "ltr" },
  { key: "mediaUrl", label: "رسانه", dir: "ltr" },
  { key: "locales", label: "زبان‌ها", dir: "ltr" },
  { key: "primaryLocale", label: "زبانِ پایه", dir: "ltr" },
];

const INPUT =
  "block w-full rounded-xl border border-neutral-300 bg-white px-3 py-2.5 text-sm text-neutral-900 outline-none transition placeholder:text-neutral-400 focus:border-neutral-900 focus:ring-4 focus:ring-neutral-900/10 disabled:bg-neutral-100";
const LABEL = "flex items-baseline gap-2 text-sm font-medium text-neutral-900";
const HINT = "mt-1.5 text-xs leading-5 text-neutral-500";

/* ── آیکون‌های درون‌خطی (هیچ CDN) ───────────────────────────────── */

const svg = "shrink-0";
const stroke = { stroke: "currentColor", strokeWidth: 1.8, strokeLinecap: "round" as const, strokeLinejoin: "round" as const };

function IconCheck(props: { className?: string }) {
  return (
    <svg viewBox="0 0 20 20" fill="none" aria-hidden="true" className={`${svg} ${props.className ?? "h-4 w-4"}`}>
      <path d="M4 10.5l4 4 8-8" {...stroke} />
    </svg>
  );
}

function IconAlert(props: { className?: string }) {
  return (
    <svg viewBox="0 0 20 20" fill="none" aria-hidden="true" className={`${svg} ${props.className ?? "h-4 w-4"}`}>
      <path d="M10 3.2l7.5 13.3h-15L10 3.2z" {...stroke} />
      <path d="M10 8.2v3.6M10 14.2v.6" {...stroke} />
    </svg>
  );
}

function IconCopy(props: { className?: string }) {
  return (
    <svg viewBox="0 0 20 20" fill="none" aria-hidden="true" className={`${svg} ${props.className ?? "h-4 w-4"}`}>
      <rect x="7" y="7" width="9" height="9" rx="2" {...stroke} />
      <path d="M13 7V5.6A1.6 1.6 0 0011.4 4H5.6A1.6 1.6 0 004 5.6v5.8A1.6 1.6 0 005.6 13H7" {...stroke} />
    </svg>
  );
}

function IconEye(props: { off?: boolean; className?: string }) {
  return (
    <svg viewBox="0 0 20 20" fill="none" aria-hidden="true" className={`${svg} ${props.className ?? "h-4 w-4"}`}>
      <path d="M2.5 10S5.5 4.8 10 4.8 17.5 10 17.5 10 14.5 15.2 10 15.2 2.5 10 2.5 10z" {...stroke} />
      <circle cx="10" cy="10" r="2.4" {...stroke} />
      {props.off && <path d="M3.5 3.5l13 13" {...stroke} />}
    </svg>
  );
}

function IconChevron(props: { open: boolean; className?: string }) {
  return (
    <svg
      viewBox="0 0 20 20"
      fill="none"
      aria-hidden="true"
      className={`${svg} transition-transform ${props.open ? "rotate-180" : ""} ${props.className ?? "h-4 w-4"}`}
    >
      <path d="M5.5 8l4.5 4.5L14.5 8" {...stroke} />
    </svg>
  );
}

/* ── اجزای کوچک ─────────────────────────────────────────────────── */

/** کارتِ یک موضوع از فرم. */
function Card({ step, title, lead, children }: { step: number; title: string; lead?: string; children: React.ReactNode }) {
  return (
    <section className="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm sm:p-6">
      <header className="mb-5 flex items-start gap-3">
        <span
          aria-hidden="true"
          className="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full bg-neutral-900 text-[11px] font-bold text-white"
        >
          {step}
        </span>
        <div>
          <h2 className="text-base font-semibold text-neutral-900">{title}</h2>
          {lead && <p className="mt-0.5 text-xs leading-5 text-neutral-500">{lead}</p>}
        </div>
      </header>
      {children}
    </section>
  );
}

/** یک مقدارِ مرجع در ستونِ کناری، با دکمهٔ کپی. */
function RefRow({ label, value, onCopy, copied }: { label: string; value: string; onCopy: () => void; copied: boolean }) {
  return (
    <div className="group flex items-center gap-2 py-1.5">
      <span className="w-20 shrink-0 text-[11px] text-neutral-500">{label}</span>
      <code className="flex-1 truncate text-[11px] text-neutral-800" dir="ltr" title={value}>
        {value}
      </code>
      <button
        type="button"
        onClick={onCopy}
        title={`کپیِ ${label}`}
        aria-label={`کپیِ ${label}`}
        className="rounded-md p-1 text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-700"
      >
        {copied ? <IconCheck className="h-3.5 w-3.5 text-emerald-600" /> : <IconCopy className="h-3.5 w-3.5" />}
      </button>
    </div>
  );
}

/* ── فرمِ اصلی ──────────────────────────────────────────────────── */

export function SetupForm({ defaults, writable }: { defaults: SetupInput; writable: boolean }) {
  const [form, setForm] = useState<SetupInput>(defaults);
  const [preset, setPreset] = useState<PresetId>("local");
  const [testing, setTesting] = useState(false);
  const [saving, setSaving] = useState(false);
  const [test, setTest] = useState<TestResult | null>(null);
  const [save, setSave] = useState<{ ok: boolean; message: string; errors?: string[] } | null>(null);
  const [showAdvanced, setShowAdvanced] = useState(false);
  const [showEnv, setShowEnv] = useState(false);
  const [revealSecret, setRevealSecret] = useState(false);
  const [copied, setCopied] = useState<string | null>(null);

  // ⚠️ همهٔ `setState`ها داخلِ رویداد هستند — هیچ‌کدام در بدنهٔ رندر صدا زده
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
  const applyPreset = useCallback(
    (id: PresetId) => {
      setPreset(id);
      setForm((prev) => ({ ...prev, ...PRESETS[id] }));
    },
    [],
  );

  const onTest = useCallback(async () => {
    setTesting(true);
    setTest(null);
    setSave(null);
    try {
      const result = await testConnection(form);
      setTest(result);
      // نشانیِ تصحیح‌شده را می‌نشانیم تا کاربر نداند اشتباه نوشته بوده.
      if (result.ok && result.apiUrl && result.apiUrl !== form.apiUrl.trim().replace(/\/+$/, "")) {
        set("apiUrl", result.apiUrl);
      }
    } finally {
      setTesting(false);
    }
  }, [form, set]);

  const onSave = useCallback(async () => {
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

  const done = Boolean(save?.ok);
  const busy = testing || saving;

  /** پیشنهادِ پایهٔ رسانه از روی نشانیِ API، وقتی با هم نمی‌خوانند. */
  const suggestedMedia = useMemo(() => {
    let origin = "";
    try {
      origin = new URL(form.apiUrl.trim()).origin;
    } catch {
      origin = "";
    }
    return origin !== "" && origin !== form.mediaUrl.trim().replace(/\/+$/, "") ? origin : "";
  }, [form.apiUrl, form.mediaUrl]);

  const envBlock = useMemo(() => {
    const rows: Array<[string, string]> = [
      ["CMS_PUBLIC_API_URL", form.apiUrl],
      ["CMS_INTERNAL_API_URL", form.internalApiUrl],
      ["CMS_SITE_URL", form.siteUrl],
      ["CMS_SITE_LOCALES", form.locales],
      ["CMS_SITE_PRIMARY_LOCALE", form.primaryLocale],
      ["CMS_PUBLIC_MEDIA_URL", form.mediaUrl],
      ["REVALIDATE_SECRET", form.revalidateSecret],
      ["CMS_PUBLIC_VAPID_PUBLIC_KEY", form.vapidPublicKey],
      ["CMS_SITE_ID", form.siteId],
    ];
    return rows
      .filter(([, v]) => v.trim())
      .map(([k, v]) => `${k}=${v.trim()}`)
      .join("\n");
  }, [form]);

  const activePreset = PRESETS[preset];

  return (
    <div dir="rtl" className="min-h-screen bg-neutral-50 text-neutral-900">
      {/* ── سرصفحه ─────────────────────────────────────────────── */}
      <header className="border-b border-neutral-200 bg-white">
        <div className="mx-auto flex max-w-6xl flex-col gap-3 px-4 py-6 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p className="text-[11px] font-medium uppercase tracking-wider text-neutral-400">نصبِ فرانت‌اند</p>
            <h1 className="mt-1 text-xl font-bold tracking-tight sm:text-2xl">اتصالِ سایت به بک‌اند</h1>
            <p className="mt-1.5 max-w-xl text-sm leading-6 text-neutral-600">
              فقط اطلاعاتِ بک‌اند را وارد کنید. پس از ذخیره، همین نسخهٔ بیلدشده بدونِ بازسازی با تنظیماتِ شما کار می‌کند.
            </p>
          </div>
          <span className="inline-flex w-fit items-center gap-2 rounded-full border border-neutral-200 bg-neutral-50 px-3 py-1.5 text-xs text-neutral-600">
            <span className="h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden="true" />
            در انتظارِ تنظیم
          </span>
        </div>
      </header>

      <main className="mx-auto grid max-w-6xl gap-6 px-4 py-8 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
        {/* ══ ستونِ اصلی: فرم ═══════════════════════════════════ */}
        <form className="space-y-5" onSubmit={(e) => e.preventDefault()} noValidate>
          {!writable && (
            <div className="flex gap-3 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="alert">
              <IconAlert />
              <div>
                <p className="font-semibold">فایلِ تنظیمات قابلِ نوشتن نیست.</p>
                <p className="mt-1 leading-6">
                  ذخیره از این صفحه ممکن نیست. فرم را پر کنید و با «مقادیرِ <code>.env</code>» در ستونِ کنار، مقدارها را
                  بردارید.
                </p>
              </div>
            </div>
          )}

          {done && (
            <div className="flex gap-3 rounded-2xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900" role="status">
              <IconCheck />
              <div>
                <p className="font-semibold">نصب کامل شد.</p>
                <p className="mt-1">در حال رفتن به سایت…</p>
              </div>
            </div>
          )}

          {/* ── کارتِ ۱: اتصال ─────────────────────────────────── */}
          <Card step={1} title="اتصال به بک‌اند" lead="آدرسی که مرورگرِ کاربران و سرورِ شما برای رسیدن به API استفاده می‌کنند.">
            <div className="space-y-5">
              <div>
                <label className={LABEL} htmlFor="apiUrl">
                  نشانیِ عمومیِ API
                  <span className="text-red-600" aria-hidden="true">*</span>
                </label>
                <input
                  id="apiUrl"
                  className={`${INPUT} mt-2`}
                  dir="ltr"
                  inputMode="url"
                  autoComplete="off"
                  spellCheck={false}
                  placeholder="https://api.example.com/api"
                  value={form.apiUrl}
                  onChange={(e) => set("apiUrl", e.target.value)}
                />
                <p className={HINT}>
                  باید از اینترنت در دسترس باشد. اگر <code className="rounded bg-neutral-100 px-1">/api</code> را
                  ننویسید، «آزمونِ اتصال» خودش پیدایش می‌کند.
                </p>
              </div>

              <div>
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <label className={LABEL} htmlFor="internalApiUrl">
                    نشانیِ داخلیِ API
                    <span className="rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-normal text-neutral-500">
                      اختیاری
                    </span>
                  </label>
                  <button
                    type="button"
                    onClick={() => set("internalApiUrl", form.apiUrl)}
                    className="text-[11px] text-neutral-500 underline underline-offset-2 hover:text-neutral-800"
                  >
                    همان نشانیِ عمومی
                  </button>
                </div>
                <input
                  id="internalApiUrl"
                  className={`${INPUT} mt-2`}
                  dir="ltr"
                  inputMode="url"
                  autoComplete="off"
                  spellCheck={false}
                  placeholder="http://backend:8080/api"
                  value={form.internalApiUrl}
                  onChange={(e) => set("internalApiUrl", e.target.value)}
                />
                <p className={HINT}>برای رندرِ سمتِ سرور. در داکر نامِ سرویس است تا ترافیک از اینترنت دور بزند.</p>
              </div>
            </div>
          </Card>

          {/* ── کارتِ ۲: هویتِ سایت ───────────────────────────── */}
          <Card step={2} title="هویتِ سایت" lead="نشانیِ عمومیِ سایت و جایی که تصویرها از آن سرو می‌شوند.">
            <div className="space-y-5">
              <div>
                <label className={LABEL} htmlFor="siteUrl">
                  نشانیِ عمومیِ سایت
                  <span className="text-red-600" aria-hidden="true">*</span>
                </label>
                <input
                  id="siteUrl"
                  className={`${INPUT} mt-2`}
                  dir="ltr"
                  inputMode="url"
                  autoComplete="off"
                  spellCheck={false}
                  placeholder="https://example.com"
                  value={form.siteUrl}
                  onChange={(e) => set("siteUrl", e.target.value)}
                />
                <p className={HINT}>در canonical، Open Graph، sitemap و llms.txt استفاده می‌شود.</p>
              </div>

              {/*
                ⚠️ این خانه از «پیشرفته» بیرون آمده و اینجا مانده است. نبودش
                **گالری** را خالی می‌کند و علتش از بیرون قابلِ حدس نیست، پس باید
                جلوی چشم باشد نه در یک بخشِ تاشو.
              */}
              <div>
                <label className={LABEL} htmlFor="mediaUrl">
                  نشانیِ دارایی‌های رسانه‌ای
                  <span className="text-red-600" aria-hidden="true">*</span>
                </label>
                <div className="mt-2 flex gap-2">
                  <input
                    id="mediaUrl"
                    className={INPUT}
                    dir="ltr"
                    inputMode="url"
                    autoComplete="off"
                    spellCheck={false}
                    placeholder="http://localhost:8080"
                    value={form.mediaUrl}
                    onChange={(e) => set("mediaUrl", e.target.value)}
                  />
                  {suggestedMedia && (
                    <button
                      type="button"
                      onClick={() => set("mediaUrl", suggestedMedia)}
                      className="shrink-0 whitespace-nowrap rounded-xl border border-neutral-300 px-3 text-xs font-medium text-neutral-700 transition hover:bg-neutral-50"
                    >
                      بگذار {suggestedMedia}
                    </button>
                  )}
                </div>
                <p className={HINT}>
                  تصویرها و فایل‌های آپلودی روی <strong className="font-medium">بک‌اند</strong> ذخیره می‌شوند، پس
                  ریشهٔ بک‌اند است — بدونِ <code className="rounded bg-neutral-100 px-1">/api</code>.{" "}
                  <strong className="font-medium">خالی بمانَد، گالری و تصاویر خالی می‌مانند.</strong>
                </p>
              </div>
            </div>
          </Card>

          {/* ── کارتِ ۳: زبان و امنیت ─────────────────────────── */}
          <Card step={3} title="زبان و امنیت" lead="زبان‌های سایت و کلیدِ مشترکی که با بک‌اند پاک‌سازیِ کش را امضا می‌کند.">
            <div className="space-y-5">
              <div className="grid gap-5 sm:grid-cols-2">
                <div>
                  <label className={LABEL} htmlFor="locales">
                    زبان‌های فعال
                    <span className="text-red-600" aria-hidden="true">*</span>
                  </label>
                  <input
                    id="locales"
                    className={`${INPUT} mt-2`}
                    dir="ltr"
                    autoComplete="off"
                    spellCheck={false}
                    placeholder="fa,en"
                    value={form.locales}
                    onChange={(e) => set("locales", e.target.value)}
                  />
                  <p className={HINT}>جداشده با کاما.</p>
                </div>
                <div>
                  <label className={LABEL} htmlFor="primaryLocale">
                    زبانِ پایه
                    <span className="text-red-600" aria-hidden="true">*</span>
                  </label>
                  <input
                    id="primaryLocale"
                    className={`${INPUT} mt-2`}
                    dir="ltr"
                    autoComplete="off"
                    spellCheck={false}
                    placeholder="fa"
                    value={form.primaryLocale}
                    onChange={(e) => set("primaryLocale", e.target.value)}
                  />
                  <p className={HINT}>بدونِ پیشوند در URL می‌آید.</p>
                </div>
              </div>

              <div>
                <label className={LABEL} htmlFor="revalidateSecret">
                  رازِ revalidate
                  <span className="text-red-600" aria-hidden="true">*</span>
                </label>
                <div className="relative mt-2">
                  <input
                    id="revalidateSecret"
                    className={`${INPUT} pl-10`}
                    dir="ltr"
                    type={revealSecret ? "text" : "password"}
                    autoComplete="new-password"
                    spellCheck={false}
                    placeholder="همان REVALIDATE_SECRET بک‌اند"
                    value={form.revalidateSecret}
                    onChange={(e) => set("revalidateSecret", e.target.value)}
                  />
                  <button
                    type="button"
                    onClick={() => setRevealSecret((v) => !v)}
                    aria-label={revealSecret ? "پنهان کردنِ راز" : "نمایشِ راز"}
                    className="absolute inset-y-0 left-2 my-auto grid h-7 w-7 place-items-center rounded-md text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-700"
                  >
                    <IconEye off={revealSecret} />
                  </button>
                </div>
                <p className={HINT}>
                  در <code className="rounded bg-neutral-100 px-1">.env</code> بک‌اند، کلیدِ{" "}
                  <code className="rounded bg-neutral-100 px-1">REVALIDATE_SECRET</code>. باید دقیقاً یکی باشد تا
                  پاک‌سازیِ کش کار کند. <strong className="font-medium">هرگز به مرورگر فرستاده نمی‌شود.</strong>
                </p>
              </div>

              <div className="border-t border-neutral-200 pt-2">
                <button
                  type="button"
                  onClick={() => setShowAdvanced((v) => !v)}
                  aria-expanded={showAdvanced}
                  className="flex w-full items-center justify-between gap-2 py-2 text-sm font-medium text-neutral-700"
                >
                  <span>تنظیماتِ پیشرفته (اختیاری)</span>
                  <IconChevron open={showAdvanced} />
                </button>

                {showAdvanced && (
                  <div className="mt-3 space-y-5">
                    <div>
                      <label className={LABEL} htmlFor="vapidPublicKey">
                        کلیدِ عمومیِ Web Push
                      </label>
                      <input
                        id="vapidPublicKey"
                        className={`${INPUT} mt-2`}
                        dir="ltr"
                        autoComplete="off"
                        spellCheck={false}
                        value={form.vapidPublicKey}
                        onChange={(e) => set("vapidPublicKey", e.target.value)}
                      />
                      <p className={HINT}>برای اعلان‌های مرورگر. خالی بمانَد، اعلان‌ها خاموش می‌مانند.</p>
                    </div>
                    <div>
                      <label className={LABEL} htmlFor="siteId">
                        شناسهٔ سایت
                      </label>
                      <input
                        id="siteId"
                        className={`${INPUT} mt-2`}
                        dir="ltr"
                        autoComplete="off"
                        spellCheck={false}
                        value={form.siteId}
                        onChange={(e) => set("siteId", e.target.value)}
                      />
                      <p className={HINT}>فقط در نصب‌های چندسایتی.</p>
                    </div>
                  </div>
                )}
              </div>
            </div>
          </Card>
        </form>

        {/* ══ ستونِ کناری: راهنما + کنش‌ها ═══════════════════════ */}
        <aside className="space-y-5 lg:sticky lg:top-6">
          {/* راهنمای سریع: دو پیش‌تنظیم، نه دوازده دکمه */}
          <section className="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm">
            <h2 className="text-sm font-semibold text-neutral-900">راهنمای سریع</h2>
            <p className="mt-1 text-xs leading-5 text-neutral-500">
              وضعیتِ خودت را بزن تا کلِ فرم یک‌جا پر شود؛ بعد فقط رازِ revalidate را بگذار.
            </p>

            <div className="mt-3 grid grid-cols-2 gap-2">
              {(["local", "server"] as const).map((id) => (
                <button
                  key={id}
                  type="button"
                  onClick={() => applyPreset(id)}
                  aria-pressed={preset === id}
                  className={
                    preset === id
                      ? "rounded-xl bg-neutral-900 px-3 py-2.5 text-xs font-semibold text-white"
                      : "rounded-xl border border-neutral-300 px-3 py-2.5 text-xs font-semibold text-neutral-700 transition hover:bg-neutral-50"
                  }
                >
                  {id === "local" ? "اجرای لوکال" : "اجرای روی سرور"}
                </button>
              ))}
            </div>

            <dl className="mt-3 divide-y divide-neutral-100 border-t border-neutral-100">
              {PRESET_LABELS.map((row) => (
                <RefRow
                  key={row.key}
                  label={row.label}
                  value={activePreset[row.key]}
                  copied={copied === `ref:${row.key}`}
                  onCopy={() => void copy(activePreset[row.key], `ref:${row.key}`)}
                />
              ))}
            </dl>

            <p className="mt-3 rounded-xl bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-600">
              {preset === "local"
                ? "همه‌چیز روی همین ماشین است."
                : "داخلِ کانتینر، localhost خودِ کانتینر است نه میزبان؛ از host.docker.internal یا نامِ سرویس استفاده کنید."}
            </p>
          </section>

          {/* کنش‌ها + نتیجه‌ها */}
          <section className="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm">
            <button
              type="button"
              onClick={() => void onSave()}
              disabled={busy || !writable || done}
              className="w-full rounded-xl bg-neutral-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-neutral-800 disabled:opacity-50"
            >
              {saving ? "در حالِ ذخیره…" : "ذخیره و پایانِ نصب"}
            </button>

            <button
              type="button"
              onClick={() => void onTest()}
              disabled={busy}
              className="mt-2 w-full rounded-xl border border-neutral-300 px-4 py-2.5 text-sm font-medium text-neutral-800 transition hover:bg-neutral-50 disabled:opacity-50"
            >
              {testing ? "در حالِ آزمون…" : "آزمونِ اتصال"}
            </button>

            <p className="mt-3 text-[11px] leading-5 text-neutral-500">
              پیش از ذخیره، اتصال واقعاً آزموده می‌شود؛ اگر برقرار نشود چیزی نوشته نمی‌شود.
            </p>

            {/* نتیجه‌ها همین‌جا، کنارِ دکمه‌ای که زده شد — نه پایینِ صفحه. */}
            <div aria-live="polite" className="mt-4 space-y-3">
              {test && (
                <div
                  className={`flex gap-2 rounded-xl border p-3 text-xs leading-5 ${
                    test.ok ? "border-emerald-300 bg-emerald-50 text-emerald-900" : "border-red-300 bg-red-50 text-red-900"
                  }`}
                  role={test.ok ? "status" : "alert"}
                >
                  {test.ok ? <IconCheck /> : <IconAlert />}
                  <span>{test.message}</span>
                </div>
              )}

              {save && !save.ok && (
                <div className="flex gap-2 rounded-xl border border-red-300 bg-red-50 p-3 text-xs leading-5 text-red-900" role="alert">
                  <IconAlert />
                  <div>
                    <p className="font-semibold">{save.message}</p>
                    {save.errors && save.errors.length > 0 && (
                      <ul className="mt-1.5 list-inside list-disc space-y-0.5">
                        {save.errors.map((e) => (
                          <li key={e}>{e}</li>
                        ))}
                      </ul>
                    )}
                  </div>
                </div>
              )}
            </div>

            <div className="mt-4 border-t border-neutral-200 pt-3">
              <button
                type="button"
                onClick={() => setShowEnv((v) => !v)}
                aria-expanded={showEnv}
                className="text-xs text-neutral-500 underline underline-offset-2 hover:text-neutral-800"
              >
                {showEnv ? "پنهان کردنِ مقادیرِ .env" : "نمایشِ مقادیرِ .env"}
              </button>
              {showEnv && (
                <pre
                  className="mt-3 max-h-64 overflow-auto rounded-xl bg-neutral-900 p-3 text-[11px] leading-6 text-neutral-100"
                  dir="ltr"
                >
                  {envBlock}
                </pre>
              )}
            </div>
          </section>
        </aside>
      </main>
    </div>
  );
}
