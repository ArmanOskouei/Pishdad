"use client";

import { useCallback, useEffect, useState } from "react";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";

/**
 * WF-L2 — کارتِ «وب‌هوک خروجی» در صفحهٔ `/admin/notifications`.
 *
 * ## ⭐ چرا راز یک‌بارمصرف است
 *
 * بک‌اند راز را رمزنگاری ذخیره می‌کند و **هرگز** برنمی‌گرداند؛ `GET` فقط
 * `secret_set` می‌دهد. بنابراین تنها راهِ دیدنش، `POST …/secret` است که متن را
 * **یک بار** برمی‌گرداند. به همین دلیل `revealed` state داریم: مقدار تا وقتی
 * در همین نشست باز است نمایش داده می‌شود و با «فراموشش کردم» پاک می‌شود.
 *
 * جریانِ کاربر:
 *  ۱) نشانی را می‌نویسد و ذخیره می‌کند.
 *  ۲) «ساخت راز تازه» را می‌زند ⇒ راز یک بار نمایش داده می‌شود (کپی + یادآوری
 *     اینکه باید در گیرنده ذخیره شود).
 *  ۳) «ارسال آزمایشی» را می‌زند تا مطمئن شود نشانی جواب می‌دهد.
 *  ۴) کلید فعال را روشن می‌کند.
 *
 * ## چرا تست از صف بیرون است
 *
 * پاسخِ تست **همین حالا** برمی‌گردد (موفق یا خطای واقعی)، پس دکمه واقعاً
 * «آزمایشی» است و «در صف نشست» نمی‌گوید — یعنی مدیر می‌فهمد نشانی درست است یا
 * نه. رویدادهای واقعی از outbox می‌روند و بازپخش/عقب‌افتادگی دارند.
 */

type SignatureDoc = {
  event: string;
  timestamp: string;
  signature: string;
  delivery: string;
  signed_string: string;
  algorithm: string;
};

type WebhookData = {
  url: string | null;
  secret_set: boolean;
  active: boolean;
  configured: boolean;
  signature?: SignatureDoc;
};

type Envelope = WebhookData & { secret?: string };

/**
 * نامِ سرآیندها از خودِ سرور می‌آید (`data.signature`) تا این کارت یک تعریفِ
 * دوم از قرارداد نباشد. مقدارِ ثابت فقط حالتِ fallback است اگر پاسخ ناقص بود.
 */
const FALLBACK_SIGNATURE: SignatureDoc = {
  event: "X-PISHDAD-Event",
  timestamp: "X-PISHDAD-Timestamp",
  signature: "X-PISHDAD-Signature",
  delivery: "X-PISHDAD-Delivery",
  signed_string: "X-PISHDAD-Timestamp.{raw_request_body}",
  algorithm: "HMAC-SHA256 (hex, lowercase)",
};

export function WebhookSettingsCard() {
  const toast = useToast();
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [testing, setTesting] = useState(false);
  const [rotating, setRotating] = useState(false);
  const [url, setUrl] = useState("");
  const [active, setActive] = useState(false);
  const [secretSet, setSecretSet] = useState(false);
  const [signature, setSignature] = useState<SignatureDoc>(FALLBACK_SIGNATURE);
  /** رازِ تازه، فقط تا وقتی در همین نشست باز است (هرگز از سرور دوباره نمی‌آید). */
  const [revealed, setRevealed] = useState<string | null>(null);
  const [testResult, setTestResult] = useState<{ ok: boolean; message: string } | null>(null);

  const apply = useCallback((d: WebhookData) => {
    setUrl(d.url ?? "");
    setActive(Boolean(d.active));
    setSecretSet(Boolean(d.secret_set));
    if (d.signature?.signature) setSignature(d.signature);
  }, []);

  const load = useCallback(async () => {
    try {
      apply(await authed<WebhookData>("/v1/admin/settings/webhook"));
    } catch (e) {
      toast(e instanceof Error ? e.message : "بارگذاری تنظیمات وب‌هوک ناموفق بود.", "err");
    } finally {
      setLoading(false);
    }
  }, [apply, toast]);

  useEffect(() => {
    void load();
  }, [load]);

  const save = useCallback(async () => {
    const trimmed = url.trim();

    if (trimmed && !/^https?:\/\/.+\..+/.test(trimmed)) {
      toast("نشانی وب‌هوک معتبر نیست (مثل https://n8n.example.ir/webhook/cms).", "err");
      return;
    }

    setBusy(true);
    try {
      apply(await authed<WebhookData>("/v1/admin/settings/webhook", {
        method: "PUT",
        body: { url: trimmed || null, active },
      }));
      toast("تنظیمات وب‌هوک ذخیره شد.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "ذخیرهٔ تنظیمات وب‌هوک ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  }, [active, apply, toast, url]);

  const rotate = useCallback(async () => {
    setRotating(true);
    try {
      const data = await authed<Envelope>("/v1/admin/settings/webhook/secret", { method: "POST" });
      apply(data);
      setRevealed(typeof data.secret === "string" && data.secret !== "" ? data.secret : null);
      toast("راز تازه ساخته شد. همین حالا آن را کپی کنید — دیگر نمایش داده نمی‌شود.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "ساخت راز تازه ناموفق بود.", "err");
    } finally {
      setRotating(false);
    }
  }, [apply, toast]);

  const sendTest = useCallback(async () => {
    setTesting(true);
    setTestResult(null);
    try {
      await authed<{ sent: boolean }>("/v1/admin/settings/webhook/test", { method: "POST" });
      setTestResult({ ok: true, message: "درخواست آزمایشی با موفقیت ارسال شد." });
    } catch (e) {
      setTestResult({
        ok: false,
        message: e instanceof Error ? e.message : "ارسال آزمایشی ناموفق بود.",
      });
    } finally {
      setTesting(false);
    }
  }, []);

  return (
    <section
      aria-labelledby="webhook-settings-title"
      style={{
        background: "var(--surface, #fff)",
        border: "1px solid var(--border, #e5e7eb)",
        borderRadius: 12,
        padding: 20,
        marginBlockEnd: 16,
      }}
    >
      <h2 id="webhook-settings-title" style={{ margin: "0 0 4px", fontSize: 16 }}>
        وب‌هوک خروجی
      </h2>
      <p style={{ margin: "0 0 16px", fontSize: 13, color: "var(--text-muted)", lineHeight: 1.9 }}>
        هنگام انتشار یا لغو انتشار صفحه و ثبت تیکت تازه، یک درخواست JSON امضاشده به نشانی
        شما فرستاده می‌شود — برای اتصال به n8n، Zapier یا هر اتوماسیون دلخواه. ارسال از صف
        انجام می‌شود، پاک شدن گیرنده هرگز انتشار صفحه را متوقف نمی‌کند.
      </p>

      {loading ? (
        <p style={{ margin: 0, fontSize: 13, color: "var(--text-muted)" }}>در حال بارگذاری…</p>
      ) : null}

      <div className="field">
        <label htmlFor="webhook-url">نشانی دریافت (Webhook URL)</label>
        <input
          id="webhook-url"
          className="input"
          dir="ltr"
          style={{ textAlign: "left" }}
          value={url}
          onChange={(e) => setUrl(e.target.value)}
          maxLength={200}
          placeholder="https://n8n.example.ir/webhook/cms"
          disabled={loading}
        />
        <small style={{ color: "var(--text-muted)" }}>
          نشانی‌ای که هر درخواست به آن POST می‌شود. باید با https باشد (http فقط برای
          آزمایش روی لوکال).
        </small>
      </div>

      <div className="field">
        <label>Rاز امضا (Secret)</label>
        {secretSet && !revealed ? (
          <p style={{ margin: "0 0 8px", fontSize: 13 }}>
            راز ذخیره شده است و هرگز نمایش داده نمی‌شود. اگر آن را گم کرده‌اید، راز تازه
            بسازید (راز قبلی از کار می‌افتد).
          </p>
        ) : null}

        {revealed ? (
          <div
            style={{
              border: "1px dashed var(--border, #e5e7eb)",
              borderRadius: 8,
              padding: 12,
              marginBlockEnd: 10,
            }}
          >
            <code dir="ltr" style={{ display: "block", wordBreak: "break-all", fontSize: 12.5 }}>
              {revealed}
            </code>
            <div style={{ display: "flex", gap: 8, marginBlockStart: 10, flexWrap: "wrap" }}>
              <button
                type="button"
                className="btn btn-sm"
                onClick={() => {
                  void navigator.clipboard?.writeText(revealed);
                  toast("راز کپی شد.", "ok");
                }}
              >
                کپی راز
              </button>
              <button
                type="button"
                className="btn btn-ghost btn-sm"
                onClick={() => setRevealed(null)}
              >
                فراموشش کردم
              </button>
            </div>
            <p style={{ margin: "10px 0 0", fontSize: 12, color: "var(--text-muted)" }}>
              این تنها بار است که راز نمایش داده می‌شود. آن را در گیرنده بگذارید، بعد همین
              پنجره را ببندید.
            </p>
          </div>
        ) : null}

        <button
          type="button"
          className="btn"
          onClick={() => void rotate()}
          disabled={rotating || loading}
        >
          {rotating ? "در حال ساخت…" : secretSet ? "ساخت راز تازه" : "ساخت راز"}
        </button>
      </div>

      <label
        style={{ display: "flex", alignItems: "center", gap: 10, fontSize: 13.5, cursor: "pointer" }}
      >
        <input
          type="checkbox"
          checked={active}
          disabled={loading}
          onChange={(e) => setActive(e.target.checked)}
        />
        ارسال رویدادها به این نشانی
      </label>
      <p style={{ margin: "6px 0 0", fontSize: 12, color: "var(--text-muted)" }}>
        {active
          ? "رویدادهای انتشار، لغو انتشار و تیکت تازه فرستاده می‌شوند."
          : "خاموش است؛ حتی با فعال بودن، تا این کلید روشن نشود چیزی فرستاده نمی‌شود."}
        {!secretSet ? " برای ارسال، اول باید راز بسازید." : null}
      </p>

      <details style={{ marginBlockStart: 12 }}>
        <summary style={{ fontSize: 13, cursor: "pointer" }}>چطور امضا را بررسی کنم؟</summary>
        <div style={{ fontSize: 12.5, lineHeight: 2, marginBlockStart: 8 }}>
          <p style={{ margin: "0 0 8px" }}>
            درخواست یک POST با بدنهٔ JSON در کلیدهای <code dir="ltr">event</code>،{" "}
            <code dir="ltr">occurred_at</code> و <code dir="ltr">data</code> است.
          </p>
          <ul style={{ margin: "0 0 8px", paddingInlineStart: 18 }}>
            <li>
              سرآیند رویداد: <code dir="ltr">{signature.event}</code>
            </li>
            <li>
              سرآیند زمان: <code dir="ltr">{signature.timestamp}</code>
            </li>
            <li>
              سرآیند امضا: <code dir="ltr">{signature.signature}</code>
            </li>
            <li>
              سرآیند شناسهٔ تحویل: <code dir="ltr">{signature.delivery}</code>
            </li>
          </ul>
          <p style={{ margin: 0 }}>
            امضا = <code dir="ltr">{signature.algorithm}</code> روی رشتهٔ{" "}
            <code dir="ltr">{signature.signed_string}</code> با راز شما. حتماً روی{" "}
            <strong>بدنهٔ خام</strong> حساب کنید، نه روی نسخهٔ parse‌شده.
          </p>
        </div>
      </details>

      <div
        style={{
          display: "flex",
          alignItems: "center",
          gap: 10,
          marginBlockStart: 14,
          flexWrap: "wrap",
        }}
      >
        <button
          type="button"
          className="btn btn-ghost btn-sm"
          onClick={() => void sendTest()}
          disabled={testing || loading}
        >
          {testing ? "در حال ارسال…" : "ارسال آزمایشی"}
        </button>
        <button
          type="button"
          className="btn btn-primary btn-sm"
          onClick={() => void save()}
          disabled={busy || loading}
        >
          {busy ? "در حال ذخیره…" : "ذخیرهٔ تنظیمات"}
        </button>
      </div>

      {testResult ? (
        <p
          role="status"
          style={{
            margin: "12px 0 0",
            fontSize: 13,
            color: testResult.ok ? "var(--text-muted)" : "#b91c1c",
          }}
        >
          {testResult.message}
        </p>
      ) : null}
    </section>
  );
}