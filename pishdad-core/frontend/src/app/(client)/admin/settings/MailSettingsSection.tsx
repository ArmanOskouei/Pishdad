"use client";
import { useCallback, useEffect, useState } from "react";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";

type MailTemplate = { subject: string; body: string };
type MailData = {
  host: string;
  port: number;
  username: string;
  from_address: string;
  from_name: string;
  encryption: "tls" | "ssl" | "none";
  password_set: boolean;
  templates: { ticket: MailTemplate; password_reset: MailTemplate; contact: MailTemplate };
};

const EMPTY: MailTemplate = { subject: "", body: "" };

/**
 * WF-H11 — تبِ «ایمیل»: SMTP اختصاصی نصب + قالب‌های قابل ویرایش.
 *
 * رمز SMTP هیچ‌وقت از سرور نمی‌آید. فیلد رمز همیشه خالی است؛ پرکردنش یعنی
 * «عوض کن»، خالی‌گذاشتنش یعنی «همان قبلی بماند».
 */
export function MailSettingsSection() {
  const toast = useToast();
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [host, setHost] = useState("");
  const [port, setPort] = useState("587");
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [clearPassword, setClearPassword] = useState(false);
  const [passwordSet, setPasswordSet] = useState(false);
  const [fromAddress, setFromAddress] = useState("");
  const [fromName, setFromName] = useState("");
  const [encryption, setEncryption] = useState<"tls" | "ssl" | "none">("tls");
  const [templates, setTemplates] = useState<Record<string, MailTemplate>>({
    ticket: EMPTY,
    password_reset: EMPTY,
    contact: EMPTY,
  });
  const [testTo, setTestTo] = useState("");
  const [testing, setTesting] = useState(false);

  const apply = useCallback((d: MailData) => {
    setHost(d.host ?? "");
    setPort(String(d.port ?? 587));
    setUsername(d.username ?? "");
    setFromAddress(d.from_address ?? "");
    setFromName(d.from_name ?? "");
    setEncryption(d.encryption === "ssl" || d.encryption === "none" ? d.encryption : "tls");
    setPasswordSet(Boolean(d.password_set));
    setTemplates({
      ticket: d.templates?.ticket ?? EMPTY,
      password_reset: d.templates?.password_reset ?? EMPTY,
      contact: d.templates?.contact ?? EMPTY,
    });
  }, []);

  const load = useCallback(async () => {
    try {
      apply(await authed<MailData>("/v1/admin/settings/mail"));
    } catch (e) {
      toast(e instanceof Error ? e.message : "بارگذاری تنظیمات ایمیل ناموفق بود.", "err");
    } finally {
      setLoading(false);
    }
  }, [apply, toast]);

  useEffect(() => {
    void load();
  }, [load]);

  const saveSmtp = async () => {
    setBusy(true);
    try {
      const body: Record<string, unknown> = {
        host: host.trim(),
        port: port.trim() ? Number(port) : null,
        username: username.trim(),
        from_address: fromAddress.trim() || null,
        from_name: fromName.trim() || null,
        encryption,
        clear_password: clearPassword,
      };
      if (password) body.password = password;
      const data = await authed<MailData>("/v1/admin/settings/mail", { method: "PUT", body });
      apply(data);
      setPassword("");
      setClearPassword(false);
      toast("تنظیمات ایمیل ذخیره شد.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "ذخیره ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  const saveTemplates = async () => {
    setBusy(true);
    try {
      await authed("/v1/admin/settings/mail/templates", { method: "PUT", body: { templates } });
      toast("قالب‌های ایمیل ذخیره شد.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "ذخیره ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  const sendTest = async () => {
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(testTo.trim())) {
      toast("ایمیل گیرندهٔ آزمایشی معتبر نیست.", "err");
      return;
    }
    setTesting(true);
    try {
      await authed("/v1/admin/settings/mail/test", { method: "POST", body: { to: testTo.trim() } });
      toast("ایمیل آزمایشی با موفقیت ارسال شد.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "ارسال ایمیل آزمایشی ناموفق بود.", "err");
    } finally {
      setTesting(false);
    }
  };

  const setTpl = (key: string, patch: Partial<MailTemplate>) =>
    setTemplates((prev) => ({ ...prev, [key]: { ...(prev[key] ?? EMPTY), ...patch } }));

  if (loading) {
    return (
      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">ایمیل (SMTP)</div>
        <p style={{ fontSize: 13, color: "var(--text-muted)" }}>در حال بارگذاری…</p>
      </div>
    );
  }

  return (
    <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
      <div className="card-title">ایمیل (SMTP اختصاصی)</div>
      <p style={{ fontSize: 12, color: "var(--text-muted)", margin: "0 0 10px" }}>
        ایمیل‌های سایت (تیکت، بازیابی رمز و فرم تماس) با این حساب SMTP ارسال می‌شوند. رمز به‌صورت
        رمزنگاری‌شده ذخیره می‌شود و هرگز نمایش داده نمی‌شود.
      </p>

      <div className="grid c2">
        <div className="field">
          <label>میزبان SMTP</label>
          <input className="input" dir="ltr" style={{ textAlign: "left" }} value={host} onChange={(e) => setHost(e.target.value)} placeholder="smtp.example.com" />
        </div>
        <div className="field">
          <label>پورت</label>
          <input className="input" dir="ltr" style={{ textAlign: "left" }} value={port} onChange={(e) => setPort(e.target.value)} placeholder="587" />
        </div>
        <div className="field">
          <label>نام کاربری</label>
          <input className="input" dir="ltr" style={{ textAlign: "left" }} value={username} onChange={(e) => setUsername(e.target.value)} autoComplete="off" />
        </div>
        <div className="field">
          <label>رمز عبور</label>
          <input
            className="input"
            dir="ltr"
            style={{ textAlign: "left" }}
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete="new-password"
            placeholder={passwordSet ? "ذخیره شده — برای تغییر وارد کنید" : "—"}
          />
        </div>
        <div className="field">
          <label>رمزنگاری</label>
          <select className="select" value={encryption} onChange={(e) => setEncryption(e.target.value as "tls" | "ssl" | "none")}>
            <option value="tls">TLS (شروع، پورت ۵۸۷)</option>
            <option value="ssl">SSL (پورت ۴۶۵)</option>
            <option value="none">بدون رمزنگاری</option>
          </select>
        </div>
        <div className="field">
          <label>ایمیل فرستنده</label>
          <input className="input" dir="ltr" style={{ textAlign: "left" }} value={fromAddress} onChange={(e) => setFromAddress(e.target.value)} placeholder="no-reply@example.com" />
        </div>
        <div className="field">
          <label>نام فرستنده</label>
          <input className="input" value={fromName} onChange={(e) => setFromName(e.target.value)} />
        </div>
      </div>

      {passwordSet ? (
        <label style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 13, marginBlockEnd: 10 }}>
          <input type="checkbox" checked={clearPassword} onChange={(e) => setClearPassword(e.target.checked)} />
          پاک‌کردن رمز ذخیره‌شده
        </label>
      ) : null}

      <div style={{ display: "flex", gap: 8, flexWrap: "wrap", alignItems: "flex-end" }}>
        <button className="btn btn-primary" onClick={() => void saveSmtp()} disabled={busy}>ذخیرهٔ تنظیمات SMTP</button>
        <div className="field" style={{ marginBlockEnd: 0 }}>
          <input className="input" dir="ltr" style={{ textAlign: "left" }} value={testTo} onChange={(e) => setTestTo(e.target.value)} placeholder="ایمیل آزمایشی: you@example.com" />
        </div>
        <button className="btn btn-ghost" onClick={() => void sendTest()} disabled={testing}>
          {testing ? "در حال ارسال…" : "ارسال ایمیل آزمایشی"}
        </button>
      </div>

      <hr style={{ margin: "18px 0", border: 0, borderBlockStart: "1px solid var(--border)" }} />

      <div className="card-title">قالب‌های ایمیل</div>
      <p style={{ fontSize: 12, color: "var(--text-muted)", margin: "0 0 10px" }}>
        متغیرهای مجاز: <code dir="ltr">{"{{app_name}} {{name}} {{email}} {{subject}} {{body}} {{ticket_id}} {{url}}"}</code>
      </p>

      {([
        ["contact", "فرم تماس (به مدیر)"],
        ["ticket", "پاسخ تیکت (به مشتری)"],
        ["password_reset", "بازیابی رمز عبور"],
      ] as const).map(([key, label]) => (
        <div key={key} className="card card-pad" style={{ marginBlockEnd: 12, background: "var(--surface-2, transparent)" }}>
          <div style={{ fontWeight: 600, marginBlockEnd: 8 }}>{label}</div>
          <div className="field">
            <label>موضوع</label>
            <input className="input" value={templates[key]?.subject ?? ""} onChange={(e) => setTpl(key, { subject: e.target.value })} />
          </div>
          <div className="field">
            <label>بدنه (HTML)</label>
            <textarea
              className="input"
              rows={5}
              dir="ltr"
              style={{ textAlign: "left", fontFamily: "monospace", fontSize: 12.5 }}
              value={templates[key]?.body ?? ""}
              onChange={(e) => setTpl(key, { body: e.target.value })}
            />
          </div>
        </div>
      ))}

      <button className="btn btn-primary" onClick={() => void saveTemplates()} disabled={busy}>ذخیرهٔ قالب‌ها</button>
    </div>
  );
}
