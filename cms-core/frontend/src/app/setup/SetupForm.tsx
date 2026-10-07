"use client";

import { useCallback, useMemo, useState } from "react";
import { saveConfig, testConnection, type SetupInput, type TestResult } from "./actions";

/**
 * فرمِ نصبِ فرانت.
 *
 * ⚠️ قانونِ پروژه: هیچ `load()`/`setState` در بدنهٔ رندر (خطای React #301).
 * بارگذاریِ داده فقط با رویدادِ کاربر یا `useEffect` انجام می‌شود؛ اینجا همه
 * چیز رویدادمحور است، پس هیچ افکتِ بارگذاری لازم نیست.
 */
export function SetupForm({ defaults, writable }: { defaults: SetupInput; writable: boolean }) {
  const [form, setForm] = useState<SetupInput>(defaults);
  const [testing, setTesting] = useState(false);
  const [saving, setSaving] = useState(false);
  const [test, setTest] = useState<TestResult | null>(null);
  const [save, setSave] = useState<{ ok: boolean; message: string; errors?: string[] } | null>(null);
  const [showEnv, setShowEnv] = useState(false);

  const set = useCallback(<K extends keyof SetupInput>(key: K, value: SetupInput[K]) => {
    setForm((prev) => ({ ...prev, [key]: value }));
  }, []);

  const onTest = useCallback(async () => {
    setTesting(true);
    setTest(null);
    setSave(null);
    try {
      const result = await testConnection(form);
      setTest(result);
      // نشانیِ تصحیح‌شده را در فرم می‌نشانیم تا کاربر نداند که اشتباه نوشته بود.
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
        // رفرش تا میدل‌ور و رندر مقدارهای تازه را ببینند (کشِ فایل بر mtime است).
        window.setTimeout(() => window.location.assign("/"), 1200);
      }
    } finally {
      setSaving(false);
    }
  }, [form]);

  const done = Boolean(save?.ok);
  const busy = testing || saving;

  /**
   * بلوکِ `.env` از **وضعیتِ فعلیِ فرم** ساخته می‌شود، نه از مقدارهای اولیه.
   * اگر از سرور می‌آمد، هر تغییری که کاربر می‌داد دیده نمی‌شد و کسی که مسیرِ
   * فایل را ندارد مقادیرِ قدیمی را کپی می‌کرد.
   */
  const envBlock = useMemo(() => {
    const lines: Array<[string, string]> = [
      ["NEXT_PUBLIC_API_URL", form.apiUrl.trim()],
      ["INTERNAL_API_URL", form.internalApiUrl.trim()],
      ["NEXT_PUBLIC_SITE_URL", form.siteUrl.trim()],
      ["NEXT_PUBLIC_SITE_LOCALES", form.locales.trim()],
      ["NEXT_PUBLIC_SITE_PRIMARY_LOCALE", form.primaryLocale.trim()],
      ["NEXT_PUBLIC_MEDIA_URL", form.mediaUrl.trim()],
      ["REVALIDATE_SECRET", form.revalidateSecret.trim()],
      ["NEXT_PUBLIC_VAPID_PUBLIC_KEY", form.vapidPublicKey.trim()],
      ["NEXT_PUBLIC_SITE_ID", form.siteId.trim()],
    ];

    return lines.filter(([, v]) => v).map(([k, v]) => `${k}=${v}`).join("\n");
  }, [form]);

  const field = useMemo(
    () => "mt-1 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm outline-none focus:border-neutral-500",
    [],
  );
  const label = useMemo(() => "block text-sm font-medium text-neutral-800", []);

  return (
    <div className="mx-auto w-full max-w-2xl px-4 py-10" dir="rtl">
      <h1 className="text-2xl font-bold text-neutral-900">نصبِ فرانت‌اند</h1>
      <p className="mt-2 text-sm leading-6 text-neutral-600">
        فقط اطلاعاتِ بک‌اند را وارد کنید. پس از ذخیره، همین باندلِ بیلدشده بدونِ هیچ بازسازی‌ای با تنظیماتِ شما کار
        می‌کند.
      </p>

      {!writable && (
        <div className="mt-6 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
          <p className="font-semibold">فایلِ تنظیمات قابلِ نوشتن نیست.</p>
          <p className="mt-1 leading-6">
            ذخیره‌سازی از این صفحه ممکن نیست. فرم را پر کنید و با دکمهٔ زیر مقادیرِ <code>.env</code> را بردارید.
          </p>
        </div>
      )}

      {done && (
        <div className="mt-6 rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900">
          <p className="font-semibold">✓ نصب کامل شد.</p>
          <p className="mt-1">در حال رفتن به سایت…</p>
        </div>
      )}

      <div className="mt-8 space-y-5">
        <div>
          <label className={label} htmlFor="apiUrl">
            نشانیِ عمومیِ API بک‌اند <span className="text-red-600">*</span>
          </label>
          <input
            id="apiUrl"
            className={field}
            dir="ltr"
            placeholder="https://api.example.com/api"
            value={form.apiUrl}
            onChange={(e) => set("apiUrl", e.target.value)}
          />
          <p className="mt-1 text-xs leading-5 text-neutral-500">
            مرورگرِ کاربران این نشانی را صدا می‌زند، پس باید از اینترنت در دسترس باشد. اگر <code>/api</code> را
            ننویسید، خودم امتحان می‌کنم.
          </p>
        </div>

        <div>
          <label className={label} htmlFor="internalApiUrl">
            نشانیِ داخلیِ API (اختیاری)
          </label>
          <input
            id="internalApiUrl"
            className={field}
            dir="ltr"
            placeholder="http://backend:8080/api"
            value={form.internalApiUrl}
            onChange={(e) => set("internalApiUrl", e.target.value)}
          />
          <p className="mt-1 text-xs leading-5 text-neutral-500">
            برای ارتباطِ سرور-به-سرور (SSR). اگر خالی بماند، همان نشانیِ عمومی استفاده می‌شود. در داکر معمولاً نامِ
            سرویس است تا ترافیک از اینترنت دور بزند.
          </p>
        </div>

        <div>
          <label className={label} htmlFor="siteUrl">
            نشانیِ عمومیِ همین سایت
          </label>
          <input
            id="siteUrl"
            className={field}
            dir="ltr"
            placeholder="https://example.com"
            value={form.siteUrl}
            onChange={(e) => set("siteUrl", e.target.value)}
          />
          <p className="mt-1 text-xs leading-5 text-neutral-500">
            در canonical، Open Graph، sitemap و llms.txt استفاده می‌شود.
          </p>
        </div>

        <div className="grid gap-5 sm:grid-cols-2">
          <div>
            <label className={label} htmlFor="locales">
              زبان‌های فعال <span className="text-red-600">*</span>
            </label>
            <input
              id="locales"
              className={field}
              dir="ltr"
              placeholder="fa,en"
              value={form.locales}
              onChange={(e) => set("locales", e.target.value)}
            />
            <p className="mt-1 text-xs text-neutral-500">جداشده با کاما.</p>
          </div>

          <div>
            <label className={label} htmlFor="primaryLocale">
              زبانِ پایه <span className="text-red-600">*</span>
            </label>
            <input
              id="primaryLocale"
              className={field}
              dir="ltr"
              placeholder="fa"
              value={form.primaryLocale}
              onChange={(e) => set("primaryLocale", e.target.value)}
            />
            <p className="mt-1 text-xs text-neutral-500">بدونِ پیشوند در URL می‌آید.</p>
          </div>
        </div>

        <div>
          <label className={label} htmlFor="revalidateSecret">
            رازِ revalidate <span className="text-red-600">*</span>
          </label>
          <input
            id="revalidateSecret"
            className={field}
            dir="ltr"
            type="password"
            placeholder="همان REVALIDATE_SECRET بک‌اند"
            value={form.revalidateSecret}
            onChange={(e) => set("revalidateSecret", e.target.value)}
          />
          <p className="mt-1 text-xs leading-5 text-neutral-500">
            در <code>.env</code> بک‌اند، کلیدِ <code>REVALIDATE_SECRET</code>. باید دقیقاً یکی باشد تا پاک‌سازیِ کش
            کار کند. این مقدار ذخیره می‌شود ولی هرگز به مرورگر فرستاده نمی‌شود.
          </p>
        </div>

        <details className="rounded-xl border border-neutral-200 p-4">
          <summary className="cursor-pointer text-sm font-medium text-neutral-700">
            تنظیماتِ پیشرفته (اختیاری)
          </summary>

          <div className="mt-4 space-y-5">
            <div>
              <label className={label} htmlFor="mediaUrl">
                نشانیِ دارایی‌های رسانه‌ای
              </label>
              <input
                id="mediaUrl"
                className={field}
                dir="ltr"
                placeholder="https://storage.example.com"
                value={form.mediaUrl}
                onChange={(e) => set("mediaUrl", e.target.value)}
              />
            </div>

            <div>
              <label className={label} htmlFor="vapidPublicKey">
                کلیدِ عمومیِ Web Push
              </label>
              <input
                id="vapidPublicKey"
                className={field}
                dir="ltr"
                value={form.vapidPublicKey}
                onChange={(e) => set("vapidPublicKey", e.target.value)}
              />
            </div>

            <div>
              <label className={label} htmlFor="siteId">
                شناسهٔ سایت
              </label>
              <input
                id="siteId"
                className={field}
                dir="ltr"
                value={form.siteId}
                onChange={(e) => set("siteId", e.target.value)}
              />
              <p className="mt-1 text-xs text-neutral-500">فقط در نصب‌های چندسایتی.</p>
            </div>
          </div>
        </details>
      </div>

      {test && (
        <div
          className={`mt-6 rounded-xl border p-4 text-sm ${
            test.ok ? "border-emerald-300 bg-emerald-50 text-emerald-900" : "border-red-300 bg-red-50 text-red-900"
          }`}
        >
          {test.ok ? "✓ " : "✗ "}
          {test.message}
        </div>
      )}

      {save && !save.ok && (
        <div className="mt-6 rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-900">
          <p className="font-semibold">{save.message}</p>
          {save.errors && save.errors.length > 0 && (
            <ul className="mt-2 list-inside list-disc space-y-1">
              {save.errors.map((e) => (
                <li key={e}>{e}</li>
              ))}
            </ul>
          )}
        </div>
      )}

      <div className="mt-8 flex flex-wrap items-center gap-3">
        <button
          type="button"
          onClick={onTest}
          disabled={busy}
          className="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-800 hover:bg-neutral-50 disabled:opacity-50"
        >
          {testing ? "در حالِ آزمون…" : "آزمونِ اتصال"}
        </button>

        <button
          type="button"
          onClick={onSave}
          disabled={busy || !writable || done}
          className="rounded-lg bg-neutral-900 px-5 py-2 text-sm font-medium text-white hover:bg-neutral-800 disabled:opacity-50"
        >
          {saving ? "در حالِ ذخیره…" : "ذخیره و پایانِ نصب"}
        </button>

        <button
          type="button"
          onClick={() => setShowEnv((v) => !v)}
          className="text-sm text-neutral-600 underline"
        >
          {showEnv ? "پنهان کردنِ مقادیرِ .env" : "می‌خواهم با .env نصب کنم"}
        </button>
      </div>

      {showEnv && (
        <pre className="mt-4 overflow-x-auto rounded-xl bg-neutral-900 p-4 text-xs leading-6 text-neutral-100" dir="ltr">
          {envBlock}
        </pre>
      )}
    </div>
  );
}
