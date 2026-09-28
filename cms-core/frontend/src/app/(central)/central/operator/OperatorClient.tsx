"use client";
import { useState } from "react";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Alert, Badge, EmptyState } from "@/components/ui/primitives";
import { ConfirmStepper } from "@/components/ui/ConfirmStepper";
import { CopyToClipboard } from "@/components/ui/CopyToClipboard";
import { Pagination } from "@/components/ui/Pagination";
import { faNum, jalali } from "@/lib/fa";
import type { OperatorActivityItem, Paginator } from "@/lib/domain";

const FRESH_2FA_HINT = "اگر خطای «تأیید دومرحله‌ای تازه» گرفتید، اول در /login دوباره با 2FA وارد شوید (اعتبار ۵ دقیقه).";

/** 2FA سخت‌افزاری + توکن نصب یکبارنمایش + Kill-Switch دومرحله‌ای + لاگ فعالیت. */
export function OperatorClient({ initialActivity }: { initialActivity: Paginator<OperatorActivityItem> }) {
  const toast = useToast();
  const [hwName, setHwName] = useState("");
  const [hwSecret, setHwSecret] = useState("");
  const [hwCode, setHwCode] = useState("");
  // دسته UIUX (افزودنی): صدور secret با QR برای ثبت کلید.
  const [otpauth, setOtpauth] = useState<string | null>(null);
  const [purpose, setPurpose] = useState("");
  const [token, setToken] = useState<{ token: string; expires_at: string } | null>(null);
  const [kill, setKill] = useState(false);
  const [busy, setBusy] = useState(false);

  /** دسته UIUX (افزودنی): صدور secret جدید + otpauth برای اسکن QR. */
  const setupSecret = async () => {
    setBusy(true);
    try {
      const r = await authed<{ secret: string; otpauth_url: string }>(`/v1/p/central/operator/2fa/hardware/setup`);
      setHwSecret(r.secret);
      setOtpauth(r.otpauth_url);
      toast("secret جدید صادر شد — QR را اسکن کنید.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "صدور secret ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  const registerHw = async () => {    if (!hwName.trim() || !hwSecret.trim() || !hwCode.trim()) { toast("نام، secret و کد کلید الزامی است.", "err"); return; }
    setBusy(true);
    try {
      await authed(`/v1/p/central/operator/2fa/hardware`, {
        method: "POST",
        body: { name: hwName.trim(), secret: hwSecret.trim(), code: hwCode.trim() },
      });
      toast("کلید سخت‌افزاری ثبت شد. (WebAuthn واقعی TODO بک‌اند است.)", "ok");
      setHwName(""); setHwSecret(""); setHwCode("");
    } catch (e) {
      toast(e instanceof Error ? e.message : "ثبت کلید ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  const createToken = async () => {
    setBusy(true);
    try {
      const r = await authed<{ token: string; expires_at: string }>(`/v1/p/central/operator/setup-tokens`, {
        method: "POST",
        body: { purpose: purpose.trim() || undefined },
      });
      setToken(r);
    } catch (e) {
      toast(e instanceof Error ? e.message : "صدور توکن ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  const doKill = async (): Promise<string> => {
    const reason = (document.getElementById("kill-reason") as HTMLTextAreaElement | null)?.value ?? "";
    if (reason.trim().length < 10) throw new Error("دلیل توقف اضطراری باید حداقل ۱۰ نویسه باشد.");
    await authed(`/v1/p/central/operator/kill-switch`, { method: "POST", body: { reason: reason.trim(), confirm: true } });
    return "توقف اضطراری ثبت و در لاگ دائمی درج شد.";
  };

  return (
    <div>
      <Alert tone="blue">عملیات حساس این صفحه step-up auth می‌خواهند. {FRESH_2FA_HINT}</Alert>

      <div className="grid c2" style={{ alignItems: "start" }}>
        <div>
          <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
            <div className="card-title">کلید دومرحله‌ای سخت‌افزاری (TOTP)</div>
            <div className="field"><label>نام کلید *</label><input className="input" value={hwName} onChange={(e) => setHwName(e.target.value)} placeholder="YubiKey دفتر" /></div>
            {otpauth ? (
              <Alert tone="blue">
                <div style={{ inlineSize: "100%" }}>
                  <div>این otpauth را در اپ Authenticator اسکن کنید (یا secret را دستی وارد کنید):</div>
                  <div dir="ltr" style={{ fontFamily: "monospace", wordBreak: "break-all", marginBlock: 6, fontSize: 12 }}>{otpauth}</div>
                  <CopyToClipboard text={otpauth} label="کپی otpauth" />
                </div>
              </Alert>
            ) : null}
            <div className="field"><label>secret *</label>
              <div style={{ display: "flex", gap: 8 }}>
                <input className="input" dir="ltr" style={{ textAlign: "left", fontFamily: "monospace" }} value={hwSecret} onChange={(e) => setHwSecret(e.target.value)} />
                <button type="button" className="btn btn-ghost btn-sm" onClick={() => void setupSecret()} disabled={busy}>صدور secret با QR…</button>
              </div>
            </div>
            <div className="field"><label>کد تأیید کلید *</label><input className="input" dir="ltr" style={{ textAlign: "left" }} value={hwCode} onChange={(e) => setHwCode(e.target.value)} placeholder="123456" /></div>
            <button className="btn btn-primary" onClick={() => void registerHw()} disabled={busy}>{busy ? "…" : "ثبت کلید"}</button>
          </div>

          <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
            <div className="card-title">توکن نصب (یکبارمصرف)</div>
            <div className="field"><label>کاربرد</label><input className="input" value={purpose} onChange={(e) => setPurpose(e.target.value)} placeholder="نصب مشتری جدید…" /></div>
            {token ? (
              <Alert tone="amber">
                <div style={{ inlineSize: "100%" }}>
                  <div dir="ltr" style={{ fontFamily: "monospace", wordBreak: "break-all", marginBlockEnd: 6 }}>{token.token}</div>
                  <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
                    <CopyToClipboard text={token.token} label="کپی توکن" />
                    <small>انقضا: {jalali(token.expires_at)} — فقط همین‌بار نمایش داده می‌شود.</small>
                  </div>
                </div>
              </Alert>
            ) : (
              <button className="btn btn-ghost" onClick={() => void createToken()} disabled={busy}>{busy ? "…" : "صدور توکن (پیش‌فرض ۱۵ دقیقه)"}</button>
            )}
          </div>
        </div>

        <div>
          <div className="card card-pad" style={{ marginBlockEnd: 14, borderColor: "var(--danger, #dc2626)" }}>
            <div className="card-title">⛔ توقف اضطراری (Kill-Switch)</div>
            <p style={{ fontSize: 13, color: "var(--text-muted)" }}>
              این عمل برگشت‌ناپذیر و دائمی در لاگ ثبت می‌شود. فقط در شرایط اضطراری واقعی استفاده کنید.
            </p>
            <div className="field"><label>دلیل (حداقل ۱۰ نویسه) *</label>
              <textarea id="kill-reason" className="input" rows={3} placeholder="شرح اضطراری…" />
            </div>
            <button className="btn btn-primary" onClick={() => setKill(true)}>ادامه به تأیید دومرحله‌ای…</button>
          </div>

          <div className="card card-pad">
            <div className="card-title">لاگ فعالیت اپراتور ({faNum(initialActivity.total)})</div>
            {initialActivity.data.length === 0 ? <EmptyState title="فعالیتی ثبت نشده" /> : (
              initialActivity.data.map((a) => (
                <div className="ver-row" key={a.id}>
                  <Badge tone="violet"><span dir="ltr">{a.action}</span></Badge>
                  <span style={{ fontSize: 12.5 }}>{a.user ? `${a.user.name} (${a.user.email})` : "—"}</span>
                  <span style={{ flex: 1 }} />
                  <small style={{ color: "var(--text-muted)" }}>{jalali(a.created_at)}</small>
                </div>
              ))
            )}
            <Pagination page={initialActivity.current_page} lastPage={initialActivity.last_page} total={initialActivity.total} base="/central/operator" params={{ page: String(initialActivity.current_page) }} />
          </div>
        </div>
      </div>

      {kill ? (
        <ConfirmStepper
          open
          title="⛔ تأیید نهایی توقف اضطراری"
          description="با تأیید، Kill-Switch با scope سراسری ثبت می‌شود و در لاگ دائمی می‌ماند. مطمئن هستید؟"
          requirePhrase="توقف اضطراری"
          confirmLabel="بستن"
          busyLabel="در حال ثبت توقف اضطراری…"
          onConfirm={doKill}
          onClose={() => setKill(false)}
        />
      ) : null}
    </div>
  );
}
