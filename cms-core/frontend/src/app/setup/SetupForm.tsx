"use client";

import { useCallback, useMemo, useState } from "react";
import { saveConfig, testConnection, type SetupInput, type TestResult } from "./actions";

/**
 * فرمِ نصبِ فرانت.
 *
 * ## اصولی که این فایل رعایت می‌کند
 *
 * ۱. **هیچ `setState`/بارگذاری‌ای در بدنهٔ رندر نیست** (قانونِ پروژه: خطای
 *    React #301 = حلقهٔ رندر و کرشِ صفحه). همه‌چیز رویدادمحور است، پس هیچ
 *    `useEffect`ی هم لازم نیست.
 * ۲. **کاربر نباید چیزی را حدس بزند.** هر مقدارِ لازم دو جا هست: در فرم (با
 *    راهنما) و در راهنمای «لوکال / سرور» که برای هر ردیف دکمهٔ «استفاده در
 *    فرم» دارد تا تایپ نکند.
 * ۳. `mediaUrl` از بخشِ «پیشرفته» بیرون آمده: نبودش **گالری** را خالی می‌کند و
 *    علتش از بیرون قابلِ حدس نیست، پس باید جلوی چشم باشد.
 * ۴. هیچ CDN خارجی‌ای مصرف نمی‌شود؛ آیکون‌ها SVG درون‌خطی‌اند (قانونِ پروژه).
 */

/** یک ردیفِ راهنما: مقداری که کاربر می‌تواند کپی یا مستقیم در فرم بگذارد. */
type GuideRow = {
  label: string;
  value: string;
  /** کلیدِ فرمی که این مقدار به آن تعلق دارد (دکمهٔ «استفاده در فرم»). */
  field?: keyof SetupInput;
  note?: string;
};

type Guide = {
  id: "local" | "server";
  title: string;
  lead: string;
  rows: GuideRow[];
  warn?: string;
};

const GUIDES: readonly Guide[] = [
  {
    id: "local",
    title: "اجرای لوکال",
    lead: "همه‌چیز روی همین یک ماشین است: بک‌اند، فرانت و دیتابیس.",
    rows: [
      { label: "نشانیِ API", value: "http://localhost:8080/api", field: "apiUrl" },
      { label: "نشانیِ داخلیِ API", value: "http://localhost:8080/api", field: "internalApiUrl", note: "وقتی هر دو روی یک ماشین‌اند، همان نشانیِ عمومی کافی است." },
      { label: "نشانیِ سایت", value: "http://localhost:3000", field: "siteUrl" },
      { label: "نشانیِ دارایی‌های رسانه‌ای", value: "http://localhost:8080", field: "mediaUrl", note: "ریشهٔ بک‌اند، بدونِ /api — تصویرها از خودِ بک‌اند سرو می‌شوند." },
      { label: "زبان‌های فعال", value: "fa", field: "locales", note: "برای دو زبانه: fa,en" },
      { label: "زبانِ پایه", value: "fa", field: "primaryLocale" },
    ],
  },
  {
    id: "server",
    title: "اجرای روی سرور",
    lead: "دامنه‌های واقعی؛ فرانت و بک‌اند ممکن است روی دو سرور باشند.",
    rows: [
      { label: "نشانیِ API", value: "https://api.example.com/api", field: "apiUrl" },
      { label: "نشانیِ داخلیِ API", value: "http://backend:8080/api", field: "internalApiUrl", note: "در داکر: نامِ سرویس. روی هاستِ ساده: همان نشانیِ عمومی." },
      { label: "نشانیِ سایت", value: "https://example.com", field: "siteUrl" },
      { label: "نشانیِ دارایی‌های رسانه‌ای", value: "https://api.example.com", field: "mediaUrl", note: "ریشهٔ بک‌اند، بدونِ /api." },
      { label: "زبان‌های فعال", value: "fa", field: "locales" },
      { label: "زبانِ پایه", value: "fa", field: "primaryLocale" },
    ],
    warn:
      "داخلِ کانتینر، localhost خودِ کانتینر است نه میزبان. برای رسیدن به بک‌اندِ روی میزبان از host.docker.internal استفاده کنید و اگر بک‌اند هم کانتینر است، نامِ سرویسش (مثلاً backend).",
  },
];

/** ریشهٔ عمومی از روی نشانیِ API — برای پیشنهادِ پایهٔ رسانه. */
function originOf(apiUrl: string): string {
  try {
    return new URL(apiUrl.trim()).origin;
  } catch {
    return "";
  }
}

const FIELD_CLASS =
  "mt-1 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 outline-none transition focus:border-neutral-500 focus:ring-2 focus:ring-neutral-900/10";
const LABEL_CLASS = "block text-sm font-medium text-neutral-800";
const HINT_CLASS = "mt-1 text-xs leading-5 text-neutral-500";

/** آیکون‌های درون‌خطی — پروژه هیچ CDN یا فونتِ آیکونی مصرف نمی‌کند. */
function IconCheck() {
  return (
    <svg viewBox="0 0 20 20" fill="none" aria-hidden="true" className="h-4 w-4 shrink-0">
      <path d="M4 10.5l4 4 8-8" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

function IconAlert() {
  return (
    <svg viewBox="0 0 20 20" fill="none" aria-hidden="true" className="h-4 w-4 shrink-0">
      <path d="M10 3l8 14H2L10 3z" stroke="currentColor" strokeWidth="1.6" strokeLinejoin="round" />
      <path d="M10 8v4M10 14.5v.5" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
    </svg>
  );
}

export function SetupForm({ defaults, writable }: { defaults: SetupInput; writable: boolean }) {
  const [form, setForm] = useState<SetupInput>(defaults);
  const [caseId, setCaseId] = useState<Guide["id"]>("local");
  const [testing, setTesting] = useState(false);
  const [saving, setSaving] = useState(false);
  const [test, setTest] = useState<TestResult | null>(null);
  const [save, setSave] = useState<{ ok: boolean; message: string; errors?: string[] } | null>(null);
  const [showEnv, setShowEnv] = useState(false);
  const [copied, setCopied] = useState<string | null>(null);
  const [showAdvanced, setShowAdvanced] = useState(false);

  /**
   * ⚠️ عمداً `useCallback` و رویدادمحور: هیچ مسیری از این فایل `setState` را
   * هنگامِ رندر صدا نمی‌زند.
   */
  const set = useCallback(<K extends keyof SetupInput>(key: K, value: SetupInput[K]) => {
    setForm((prev) => ({ ...prev, [key]: value }));
  }, []);

  const copy = useCallback(async (value: string, tag: string) => {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(tag);
      window.setTimeout(() => setCopied((current) => (current === tag ? null : current)), 1600);
    } catch {
      // کلیپ‌بورد در بسترِ ناامن (http روی دامنهٔ غیرِ localhost) یا بدونِ اجازه
      // در دسترس نیست. سکوت بدتر از گفتن است.
      setCopied(null);
    }
  }, []);

  /** «استفاده در فرم»: مقدارِ راهنما را مستقیم در خانهٔ مربوطه می‌گذارد. */
  const applyRow = useCallback(
    (row: GuideRow) => {
      if (!row.field) return;
      set(row.field, row.value as never);
    },
    [set],
  );

  const onTest = useCallback(async () => {
    setTesting(true);
    setTest(null);
    setSave(null);
    try {
      const result = await testConnection(form);
      setTest(result);
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
      if (result.ok) {
        window.setTimeout(() => window.location.assign("/"), 1200);
      }
    } finally {
      setSaving(false);
    }
  }, [form]);

  const done = Boolean(save?.ok);
  const busy = testing || saving;
  const activeGuide = GUIDES.find((g) => g.id === caseId) ?? GUIDES[0];

  /** پیشنهادِ پایهٔ رسانه از روی نشانیِ API — کاربر نباید حدس بزند. */
  const suggestedMedia = useMemo(() => {
    const origin = originOf(form.apiUrl);
    return origin !== "" && origin !== form.mediaUrl.trim().replace(/\/+$/, "") ? origin : "";
  }, [form.apiUrl, form.mediaUrl]);

  /** بلوکِ `.env` از وضعیتِ فعلیِ فرم ساخته می‌شود، نه از مقدارهای اولیه. */
  const envBlock = useMemo(() => {
    const lines: Array<[string, string]> = [
      ["CMS_PUBLIC_API_URL", form.apiUrl.trim()],
      ["CMS_INTERNAL_API_URL", form.internalApiUrl.trim()],
      ["CMS_SITE_URL", form.siteUrl.trim()],
      ["CMS_SITE_LOCALES", form.locales.trim()],
      ["CMS_SITE_PRIMARY_LOCALE", form.primaryLocale.trim()],
      ["CMS_PUBLIC_MEDIA_URL", form.mediaUrl.trim()],
      ["REVALIDATE_SECRET", form.revalidateSecret.trim()],
      ["CMS_PUBLIC_VAPID_PUBLIC_KEY", form.vapidPublicKey.trim()],
      ["CMS_SITE_ID", form.siteId.trim()],
    ];

    return lines
      .filter(([, v]) => v)
      .map(([k, v]) => `${k}=${v}`)
      .join("\n");
  }, [form]);

  return (
    <div className="mx-auto w-full max-w-3xl px-4 py-10" dir="rtl">
      {/* ── سرصفحه ─────────────────────────────────────────────── */}
      <header className="mb-8">
        <p className="text-xs font-medium tracking-wide text-neutral-500">گامِ نصب</p>
        <h1 className="mt-1 text-2xl font-bold text-neutral-900">اتصالِ سایت به بک‌اند</h1>
        <p className="mt-2 text-sm leading-6 text-neutral-600">
          فقط اطلاعاتِ بک‌اند را وارد کنید. پس از ذخیره، همین نسخهٔ بیلدشده بدونِ هیچ بازسازی‌ای با تنظیماتِ شما کار
          می‌کند.
        </p>
      </header>

      {/* ── هشدارِ فقط-خواندنی ─────────────────────────────────── */}
      {!writable && (
        <div className="mb-6 flex gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="alert">
          <IconAlert />
          <div>
            <p className="font-semibold">فایلِ تنظیمات قابلِ نوشتن نیست.</p>
            <p className="mt-1 leading-6">
              ذخیره از این صفحه ممکن نیست. فرم را پر کنید و با دکمهٔ پایین مقادیرِ <code>.env</code> را بردارید.
            </p>
          </div>
        </div>
      )}

      {done && (
        <div className="mb-6 flex gap-3 rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900" role="status">
          <IconCheck />
          <div>
            <p className="font-semibold">نصب کامل شد.</p>
            <p className="mt-1">در حال رفتن به سایت…</p>
          </div>
        </div>
      )}

      {/* ── راهنمای لوکال / سرور ───────────────────────────────── */}
      <section className="mb-8 rounded-2xl border border-neutral-200 bg-neutral-50/60 p-5" aria-labelledby="guide-heading">
        <h2 id="guide-heading" className="text-sm font-semibold text-neutral-900">
          راهنما: کدام مقدارها را وارد کنم؟
        </h2>
        <p className="mt-1 text-xs leading-5 text-neutral-600">
          وضعیتِ خودت را انتخاب کن. هر ردیف را می‌توانی کپی کنی یا با «استفاده در فرم» مستقیم بگذاری.
        </p>

        <div className="mt-4 flex gap-2" role="tablist" aria-label="وضعیتِ اجرا">
          {GUIDES.map((guide) => {
            const active = guide.id === caseId;
            return (
              <button
                key={guide.id}
                type="button"
                role="tab"
                aria-selected={active}
                aria-controls={`guide-${guide.id}`}
                id={`tab-${guide.id}`}
                onClick={() => setCaseId(guide.id)}
                className={
                  active
                    ? "rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white"
                    : "rounded-lg border border-neutral-300 bg-white px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-100"
                }
              >
                {guide.title}
              </button>
            );
          })}
        </div>

        <div
          className="mt-4"
          role="tabpanel"
          id={`guide-${activeGuide.id}`}
          aria-labelledby={`tab-${activeGuide.id}`}
        >
          <p className="text-xs leading-5 text-neutral-600">{activeGuide.lead}</p>

          <ul className="mt-3 space-y-2">
            {activeGuide.rows.map((row) => {
              const tag = `${activeGuide.id}:${row.label}`;
              return (
                <li
                  key={tag}
                  className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-neutral-200 bg-white px-3 py-2"
                >
                  <span className="min-w-40 text-xs font-medium text-neutral-700">{row.label}</span>
                  <code className="flex-1 truncate text-xs text-neutral-900" dir="ltr">
                    {row.value}
                  </code>
                  <button
                    type="button"
                    onClick={() => void copy(row.value, tag)}
                    className="rounded-md border border-neutral-300 px-2 py-1 text-xs text-neutral-700 hover:bg-neutral-100"
                  >
                    {copied === tag ? "کپی شد ✓" : "کپی"}
                  </button>
                  {row.field && (
                    <button
                      type="button"
                      onClick={() => applyRow(row)}
                      className="rounded-md bg-neutral-900 px-2 py-1 text-xs font-medium text-white hover:bg-neutral-800"
                    >
                      استفاده در فرم
                    </button>
                  )}
                  {row.note && <p className="w-full text-[11px] leading-5 text-neutral-500">{row.note}</p>}
                </li>
              );
            })}
          </ul>

          {activeGuide.warn && (
            <p className="mt-3 flex gap-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-[11px] leading-5 text-amber-900">
              <span aria-hidden="true">⚠</span>
              <span>{activeGuide.warn}</span>
            </p>
          )}
        </div>
      </section>

      {/* ── فرم ─────────────────────────────────────────────────── */}
      <form className="space-y-6" onSubmit={(e) => e.preventDefault()} noValidate>
        <fieldset className="space-y-5">
          <legend className="text-sm font-semibold text-neutral-900">۱) آدرس‌ها</legend>

          <div>
            <label className={LABEL_CLASS} htmlFor="apiUrl">
              نشانیِ عمومیِ API بک‌اند <span className="text-red-600">*</span>
            </label>
            <input
              id="apiUrl"
              className={FIELD_CLASS}
              dir="ltr"
              placeholder="https://api.example.com/api"
              value={form.apiUrl}
              onChange={(e) => set("apiUrl", e.target.value)}
              aria-invalid={Boolean(test && !test.ok)}
            />
            <p className={HINT_CLASS}>
              این نشانی را مرورگرِ کاربران صدا می‌زند، پس باید از اینترنت در دسترس باشد. اگر <code>/api</code> را
              ننویسید، خودم امتحان می‌کنم و نشانیِ درست را پیدا می‌کنم.
            </p>
          </div>

          <div>
            <label className={LABEL_CLASS} htmlFor="internalApiUrl">
              نشانیِ داخلیِ API
            </label>
            <input
              id="internalApiUrl"
              className={FIELD_CLASS}
              dir="ltr"
              placeholder="http://backend:8080/api"
              value={form.internalApiUrl}
              onChange={(e) => set("internalApiUrl", e.target.value)}
            />
            <p className={HINT_CLASS}>
              برای ارتباطِ سرور-به-سرور (رندرِ سمتِ سرور). خالی بماند، همان نشانیِ عمومی استفاده می‌شود. در داکر
              معمولاً نامِ سرویس است تا ترافیک از اینترنت دور بزند.
            </p>
          </div>

          <div>
            <label className={LABEL_CLASS} htmlFor="siteUrl">
              نشانیِ عمومیِ همین سایت
            </label>
            <input
              id="siteUrl"
              className={FIELD_CLASS}
              dir="ltr"
              placeholder="https://example.com"
              value={form.siteUrl}
              onChange={(e) => set("siteUrl", e.target.value)}
            />
            <p className={HINT_CLASS}>
              نشانی‌ای که کاربران در مرورگر می‌بینند. در canonical، Open Graph، sitemap و llms.txt استفاده می‌شود.
            </p>
          </div>

          {/* ⚠️ از «پیشرفته» بیرون آمده: نبودش گالری را خالی می‌کند. */}
          <div>
            <label className={LABEL_CLASS} htmlFor="mediaUrl">
              نشانیِ دارایی‌های رسانه‌ای
            </label>
            <div className="mt-1 flex gap-2">
              <input
                id="mediaUrl"
                className={FIELD_CLASS}
                dir="ltr"
                placeholder="http://localhost:8080"
                value={form.mediaUrl}
                onChange={(e) => set("mediaUrl", e.target.value)}
                style={{ marginTop: 0 }}
              />
              {suggestedMedia && (
                <button
                  type="button"
                  onClick={() => set("mediaUrl", suggestedMedia)}
                  className="shrink-0 rounded-lg border border-neutral-300 px-3 text-xs font-medium text-neutral-700 hover:bg-neutral-50"
                >
                  بگذار {suggestedMedia}
                </button>
              )}
            </div>
            <p className={HINT_CLASS}>
              تصویرها و فایل‌های آپلودی روی <strong>بک‌اند</strong> ذخیره می‌شوند، پس اینجا باید ریشهٔ بک‌اند باشد
              (بدونِ <code>/api</code>). اگر خالی بماند، <strong>گالری و تصاویر خالی می‌مانند</strong> چون مرورگر
              آن‌ها را از خودِ فرانت می‌خواهد.
            </p>
          </div>
        </fieldset>

        <fieldset className="space-y-5">
          <legend className="text-sm font-semibold text-neutral-900">۲) زبان‌ها</legend>

          <div className="grid gap-5 sm:grid-cols-2">
            <div>
              <label className={LABEL_CLASS} htmlFor="locales">
                زبان‌های فعال <span className="text-red-600">*</span>
              </label>
              <input
                id="locales"
                className={FIELD_CLASS}
                dir="ltr"
                placeholder="fa,en"
                value={form.locales}
                onChange={(e) => set("locales", e.target.value)}
              />
              <p className={HINT_CLASS}>جداشده با کاما. مثلاً <code>fa</code> برای تک‌زبانه.</p>
            </div>

            <div>
              <label className={LABEL_CLASS} htmlFor="primaryLocale">
                زبانِ پایه <span className="text-red-600">*</span>
              </label>
              <input
                id="primaryLocale"
                className={FIELD_CLASS}
                dir="ltr"
                placeholder="fa"
                value={form.primaryLocale}
                onChange={(e) => set("primaryLocale", e.target.value)}
              />
              <p className={HINT_CLASS}>بدونِ پیشوند در URL می‌آید.</p>
            </div>
          </div>
        </fieldset>

        <fieldset className="space-y-5">
          <legend className="text-sm font-semibold text-neutral-900">۳) اتصالِ امن</legend>

          <div>
            <label className={LABEL_CLASS} htmlFor="revalidateSecret">
              رازِ revalidate <span className="text-red-600">*</span>
            </label>
            <input
              id="revalidateSecret"
              className={FIELD_CLASS}
              dir="ltr"
              type="password"
              autoComplete="new-password"
              placeholder="همان REVALIDATE_SECRET بک‌اند"
              value={form.revalidateSecret}
              onChange={(e) => set("revalidateSecret", e.target.value)}
            />
            <p className={HINT_CLASS}>
              در <code>.env</code> بک‌اند، کلیدِ <code>REVALIDATE_SECRET</code>. باید دقیقاً یکی باشد تا پاک‌سازیِ کش
              کار کند. این مقدار ذخیره می‌شود ولی هرگز به مرورگر فرستاده نمی‌شود.
            </p>
          </div>
        </fieldset>

        {/* ── پیشرفته (اختیاری) ───────────────────────────────── */}
        <div className="rounded-xl border border-neutral-200 p-4">
          <button
            type="button"
            onClick={() => setShowAdvanced((v) => !v)}
            aria-expanded={showAdvanced}
            className="text-sm font-medium text-neutral-700"
          >
            {showAdvanced ? "پنهان کردنِ تنظیماتِ پیشرفته" : "تنظیماتِ پیشرفته (اختیاری)"}
          </button>

          {showAdvanced && (
            <div className="mt-4 space-y-5">
              <div>
                <label className={LABEL_CLASS} htmlFor="vapidPublicKey">
                  کلیدِ عمومیِ Web Push
                </label>
                <input
                  id="vapidPublicKey"
                  className={FIELD_CLASS}
                  dir="ltr"
                  value={form.vapidPublicKey}
                  onChange={(e) => set("vapidPublicKey", e.target.value)}
                />
                <p className={HINT_CLASS}>برای اعلان‌های مرورگر. خالی بماند، اعلان‌ها خاموش می‌مانند.</p>
              </div>

              <div>
                <label className={LABEL_CLASS} htmlFor="siteId">
                  شناسهٔ سایت
                </label>
                <input
                  id="siteId"
                  className={FIELD_CLASS}
                  dir="ltr"
                  value={form.siteId}
                  onChange={(e) => set("siteId", e.target.value)}
                />
                <p className={HINT_CLASS}>فقط در نصب‌های چندسایتی. در نصبِ معمولی خالی بگذارید.</p>
              </div>
            </div>
          )}
        </div>
      </form>

      {/* ── نتیجهٔ آزمون ───────────────────────────────────────── */}
      {test && (
        <div
          className={`mt-6 flex gap-3 rounded-xl border p-4 text-sm ${
            test.ok ? "border-emerald-300 bg-emerald-50 text-emerald-900" : "border-red-300 bg-red-50 text-red-900"
          }`}
          role={test.ok ? "status" : "alert"}
        >
          {test.ok ? <IconCheck /> : <IconAlert />}
          <span className="leading-6">{test.message}</span>
        </div>
      )}

      {save && !save.ok && (
        <div className="mt-6 flex gap-3 rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-900" role="alert">
          <IconAlert />
          <div>
            <p className="font-semibold">{save.message}</p>
            {save.errors && save.errors.length > 0 && (
              <ul className="mt-2 list-inside list-disc space-y-1">
                {save.errors.map((e) => (
                  <li key={e}>{e}</li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}

      {/* ── کنش‌ها ─────────────────────────────────────────────── */}
      <div className="mt-8 flex flex-wrap items-center gap-3">
        <button
          type="button"
          onClick={() => void onTest()}
          disabled={busy}
          className="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-800 hover:bg-neutral-50 disabled:opacity-50"
        >
          {testing ? "در حالِ آزمون…" : "آزمونِ اتصال"}
        </button>

        <button
          type="button"
          onClick={() => void onSave()}
          disabled={busy || !writable || done}
          className="rounded-lg bg-neutral-900 px-5 py-2 text-sm font-medium text-white hover:bg-neutral-800 disabled:opacity-50"
        >
          {saving ? "در حالِ ذخیره…" : "ذخیره و پایانِ نصب"}
        </button>

        <button type="button" onClick={() => setShowEnv((v) => !v)} className="text-sm text-neutral-600 underline">
          {showEnv ? "پنهان کردنِ مقادیرِ .env" : "می‌خواهم با .env نصب کنم"}
        </button>
      </div>

      <p className={HINT_CLASS + " mt-3"}>
        پیش از ذخیره، اتصال واقعاً آزموده می‌شود؛ اگر برقرار نشود چیزی نوشته نمی‌شود.
      </p>

      {showEnv && (
        <pre
          className="mt-4 overflow-x-auto rounded-xl bg-neutral-900 p-4 text-xs leading-6 text-neutral-100"
          dir="ltr"
        >
          {envBlock}
        </pre>
      )}
    </div>
  );
}
