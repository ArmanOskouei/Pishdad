"use client";

/** صفحه لاگین دوعاملی (تسک ۲.۱): رمز → 2FA → داشبورد. */

import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/lib/auth";
import { API_BASE } from "@/lib/api";
import { Stepper, Alert, Skeleton } from "@/components/ui/primitives";
import { useToast } from "@/components/ui/Toast";
import { nextOtpFocusIndex, OTP_LENGTH as OTP_LENGTH_LIB } from "@/lib/otp-focus";
import { loginGate } from "@/lib/login-gate";

const OTP_LENGTH = OTP_LENGTH_LIB;
const AUTO_SUBMIT_DELAY = 300;
const OTP_PATTERN = /^\d{6}$/;
const EMPTY_OTP = Array.from({ length: OTP_LENGTH }, () => "");

function normalizeOtpValue(value: string): string {
  return value
    .replace(/[٠-٩]/g, (digit) => String(digit.charCodeAt(0) - 0x660))
    .replace(/[۰-۹]/g, (digit) => String(digit.charCodeAt(0) - 0x6f0))
    .replace(/[\s-]/g, "");
}

export default function LoginPage() {
  // ⭐ `user` و `loading` برای «قبلاً وارد شده‌ای؟» لازم‌اند — گزارشِ کاربر:
  // بعد از ورود، اگر مستقیم `/login` باز می‌شد، فرم دوباره نشان داده می‌شد
  // حتی وقتی توکن معتبر بود. `useAuth` در `mount` کاربر را از سرور می‌خواند،
  // پس «تازه وارد شده» و «قبلاً وارد بوده» در این رابطه یکی‌اند.
  const { login, verify2fa, user, loading, setupRequired } = useAuth();
  const toast = useToast();
  const router = useRouter();
  const [step, setStep] = useState(1);
  const [email, setEmail] = useState("admin@example.com");
  const [password, setPassword] = useState("");
  // دسته UIUX (افزودنی): فلو بازیابی رمز (لینک «فراموشی رمز؟» دمو).
  const [mode, setMode] = useState<"login" | "forgot" | "reset">("login");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [code, setCode] = useState<string[]>([...EMPTY_OTP]);
  const inputs = useRef<Array<HTMLInputElement | null>>([]);
  const submittingRef = useRef(false);
  const focusAfterError = useRef(false);
  // برند عمومی سایت (لوگو + نام از تنظیمات — بدون نیاز به لاگین).
  const [brand, setBrand] = useState<{ title: string; logo: string | null }>({ title: "", logo: null });
  useEffect(() => {
    fetch(`${API_BASE}/v1/site/chrome`, { cache: "no-store" })
      .then((r) => (r.ok ? r.json() : null))
      .then((j) => {
        const d = j?.data ?? j;
        if (d && typeof d === "object") setBrand({ title: String(d.title ?? ""), logo: (d.logo_url as string) ?? null });
      })
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    if (step !== 2 || busy) return;
    const timer = window.setTimeout(() => inputs.current[0]?.focus(), 0);
    return () => window.clearTimeout(timer);
  }, [busy, step]);

  useEffect(() => {
    if (step !== 2 || busy || !focusAfterError.current) return;
    focusAfterError.current = false;
    inputs.current[0]?.focus();
  }, [busy, step]);

  const afterAuth = useCallback(() => {
    router.replace("/admin/dashboard");
  }, [router]);

  // ⭐ اگر نشستِ معتبری هست، فرم ورود اصلاً نباید دیده شود.
  //
  // `router.replace` ناهمگام است، پس فرم در همان رندرِ بعدی هم می‌تواند دیده
  // شود مگر اینکه خودِ رندر را هم نگه داریم. `gate` دقیقاً همین است و
  // ترتیبش با `login-gate.ts` یکی.
  const gate = loginGate({ loading, user });

  // WF-M8: نقشِ «۲FA اجباری» نباید به داشبورد بپرد — باید به صفحهٔ
  // راه‌اندازی پروفایل برود. پس دروازهٔ پیش‌فرض را برای این حالت رد می‌کنیم.
  useEffect(() => {
    if (gate !== "redirect" || setupRequired) return;
    void afterAuth();
  }, [gate, afterAuth, setupRequired]);

  useEffect(() => {
    if (gate !== "redirect" || !setupRequired) return;
    toast("نقش شما ورود با تأیید دومرحله‌ای را الزامی می‌کند؛ لطفاً آن را فعال کنید.");
    router.replace("/admin/profile?setup=2fa");
  }, [gate, router, setupRequired, toast]);

  async function submitLogin(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true); setError(null);
    try {
      const { need2fa, need2faSetup } = await login(email, password);
      if (need2fa) {
        setCode([...EMPTY_OTP]);
        setStep(2);
        toast("کد تأیید دومرحله‌ای را وارد کنید.");
      }
      else if (!need2faSetup) { toast("خوش آمدید.", "ok"); afterAuth(); }
    } catch (err) {
      setError(err instanceof Error ? err.message : "اعتبار ورود نامعتبر است.");
    } finally { setBusy(false); }
  }

  const submit2fa = useCallback(async (e?: React.FormEvent) => {
    e?.preventDefault();
    if (submittingRef.current) return;

    const normalizedCode = normalizeOtpValue(code.join(""));
    if (!OTP_PATTERN.test(normalizedCode)) return;

    submittingRef.current = true;
    setBusy(true);
    setError(null);
    try {
      await verify2fa(normalizedCode);
      toast("ورود موفق.", "ok");
      afterAuth();
    } catch (err) {
      setError(err instanceof Error ? err.message : "اعتبار ورود نامعتبر است.");
      focusAfterError.current = true;
      setCode([...EMPTY_OTP]);
    } finally {
      submittingRef.current = false;
      setBusy(false);
    }
  }, [afterAuth, code, toast, verify2fa]);

  useEffect(() => {
    const normalizedCode = normalizeOtpValue(code.join(""));
    if (step !== 2 || busy || !OTP_PATTERN.test(normalizedCode)) return;

    const timer = window.setTimeout(() => {
      if (!submittingRef.current) void submit2fa();
    }, AUTO_SUBMIT_DELAY);
    return () => window.clearTimeout(timer);
  }, [busy, code, step, submit2fa]);

  function updateOtp(raw: string, startIndex: number) {
    if (busy) return;
    const digits = normalizeOtpValue(raw);
    if (!/^\d*$/.test(digits)) {
      setError("کد فقط باید شامل رقم باشد.");
      setCode([...EMPTY_OTP]);
      focusAfterError.current = true;
      return;
    }
    if (digits.length > OTP_LENGTH) {
      setError("کد باید دقیقاً ۶ رقم باشد.");
      setCode([...EMPTY_OTP]);
      focusAfterError.current = true;
      return;
    }
    if (!digits) {
      if (/^[\s-]+$/.test(raw)) return;
      setError(null);
      setCode((p) => {
        const n = [...p];
        if (startIndex >= 0 && startIndex < OTP_LENGTH) n[startIndex] = "";
        return n;
      });
      return;
    }

    const start = digits.length > 1 ? 0 : startIndex;
    setCode((p) => {
      const n = [...p];
      for (let offset = 0; offset < digits.length && start + offset < OTP_LENGTH; offset += 1) n[start + offset] = digits[offset];
      return n;
    });
    setError(null);
    // کادر **بعدی**، نه کادری که رقم در آن نشست. منطقش در `lib/otp-focus`
    // است و تست دارد، چون همین‌جا یک واحد اختلاف داشت و کاربر را مجبور
    // می‌کرد برای هر رقم دستی کلیک یا TAB بزند.
    const nextFocus = nextOtpFocusIndex(start, digits.length, OTP_LENGTH);
    window.setTimeout(() => inputs.current[nextFocus]?.focus(), 0);
  }

  function onOtpPaste(e: React.ClipboardEvent<HTMLInputElement>, i: number) {
    e.preventDefault();
    updateOtp(e.clipboardData.getData("text"), i);
  }

  function backToLogin() {
    setCode([...EMPTY_OTP]);
    setError(null);
    setStep(1);
    focusAfterError.current = false;
  }

  const normalizedCode = normalizeOtpValue(code.join(""));
  const otpReady = step === 2 && OTP_PATTERN.test(normalizedCode) && !busy;

  // دروازهٔ mount، از همان تابعی که تست شده — تا رفتار و تست یکی باشند.
  //
  // `wait` و `redirect` هر دو «فرم را نشان نده» یعنی. برای `redirect` صفحه
  // خالی می‌ماند تا روتر جابه‌جا شود؛ نشان‌دادنِ فرم در آن یک لحظهٔ نمایشیِ
  // دقیقاً همان چیزی است که کاربر گزارش داد.
  if (gate !== "form") {
    return <main style={{ minBlockSize: "100vh" }} aria-busy="true" />;
  }

  return (
    <main style={{ minBlockSize: "100vh", display: "grid", placeItems: "center", padding: 16 }}>
      <div className="card card-pad" style={{ inlineSize: "100%", maxInlineSize: 420, borderRadius: "var(--radius-xl)", boxShadow: "var(--shadow-lg)" }}>
        <div style={{ display: "flex", flexDirection: "column", alignItems: "center", gap: 8, marginBlockEnd: 24, textAlign: "center" }}>
          {brand.logo ? (
            <img src={brand.logo} alt="" style={{ inlineSize: 56, blockSize: 56, borderRadius: 14, objectFit: "cover", border: "1px solid var(--border)" }} />
          ) : (
            <span className="brand-mark" style={{ inlineSize: 52, blockSize: 52, fontSize: 24 }}>س</span>
          )}
          <b style={{ fontSize: 17 }}>ورود به پنل مدیریت{brand.title ? ` ${brand.title}` : ""}</b>
        </div>
        {step === 1 ? (
          mode === "forgot" ? (
            <ForgotForm email={email} onBack={() => setMode("login")} onSent={() => setMode("reset")} />
          ) : mode === "reset" ? (
            <ResetForm email={email} onBack={() => setMode("login")} />
          ) : (
          <form onSubmit={submitLogin}>
            <Stepper steps={["رمز عبور", "تأیید دومرحله‌ای"]} current={0} />
            {error ? <Alert tone="red">{error}</Alert> : null}
            <div className="field"><label htmlFor="email">ایمیل</label>
              <input id="email" className="input" dir="ltr" style={{ textAlign: "left" }} value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="email" required />
            </div>
            <div className="field"><label htmlFor="pass">رمز عبور</label>
              <input id="pass" className="input" type="password" dir="ltr" style={{ textAlign: "left" }} value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" required />
            </div>
            <div style={{ display: "flex", justifyContent: "flex-end", marginBlock: "4px 10px" }}>
              <button type="button" className="btn btn-ghost btn-sm" onClick={() => setMode("forgot")}>فراموشی رمز؟</button>
            </div>
            <button className="btn btn-primary btn-block" disabled={busy}>{busy ? "در حال بررسی…" : "ادامه"}</button>
          </form>
          )
        ) : (
          <form onSubmit={submit2fa}>
            <Stepper steps={["رمز عبور", "تأیید دومرحله‌ای"]} current={1} />
            {error ? <Alert tone="red">{error}</Alert> : null}
            <p style={{ fontSize: 13, color: "var(--text-muted)", textAlign: "center" }}>کد ۶ رقمی اپ احراز هویت را وارد کنید.</p>
            <p style={{ fontSize: 12, color: "var(--text-muted)", textAlign: "center", marginBlock: "0 8px" }}>پس از وارد کردن ۶ رقم، ورود به‌صورت خودکار انجام می‌شود.</p>
            <div style={{ display: "flex", gap: 8, justifyContent: "center", direction: "ltr", margin: "8px 0 16px" }}>
              {code.map((c, i) => (
                <input
                  key={i} ref={(el) => { inputs.current[i] = el; }} value={c}
                  onChange={(e) => updateOtp(e.target.value, i)}
                  onPaste={(e) => onOtpPaste(e, i)}
                  onKeyDown={(e) => { if (e.key === "Backspace" && !code[i] && i > 0) inputs.current[i - 1]?.focus(); }}
                  maxLength={1} inputMode="numeric" autoComplete={i === 0 ? "one-time-code" : "off"} aria-label={`رقم ${i + 1}`}
                  disabled={busy} aria-invalid={Boolean(error)}
                  style={{ inlineSize: 48, blockSize: 54, textAlign: "center", fontSize: 20, fontWeight: 800, border: "1.5px solid var(--border)", borderRadius: "var(--radius-md)", background: "var(--surface)", color: "var(--text)" }}
                />
              ))}
            </div>
            <button
              className={`btn btn-block ${otpReady || busy ? "btn-success" : "btn-primary"}`}
              disabled={busy || !otpReady}
              aria-busy={busy}
            >
              {busy ? <span className="otp-spinner" aria-hidden="true" /> : null}
              {busy ? "در حال بررسی…" : otpReady ? "آمادهٔ ورود خودکار" : "تأیید و ورود"}
            </button>
            <button type="button" className="btn btn-ghost btn-block" style={{ marginBlockStart: 8 }} disabled={busy} onClick={backToLogin}>بازگشت</button>
          </form>
        )}
        {busy && step === 1 ? <div style={{ marginBlockStart: 12 }}><Skeleton lines={2} /></div> : null}
      </div>
    </main>
  );
}

/** دسته UIUX (افزودنی): درخواست لینک بازیابی رمز (POST /v1/auth/forgot-password). */
function ForgotForm({ email, onBack, onSent }: { email: string; onBack: () => void; onSent: () => void }) {
  const toast = useToast();
  const [em, setEm] = useState(email);
  const [busy, setBusy] = useState(false);
  const send = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    try {
      const r = await fetch(`${API_BASE}/v1/auth/forgot-password`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ email: em.trim() }),
      });
      const j = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error((j as { message?: string })?.message ?? "ارسال ناموفق بود.");
      toast((j as { message?: string })?.message ?? "لینک بازیابی ارسال شد.", "ok");
      onSent();
    } catch (err) {
      toast(err instanceof Error ? err.message : "ارسال ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };
  return (
    <form onSubmit={send}>
      <Stepper steps={["بازیابی رمز", "رمز جدید"]} current={0} />
      <p style={{ fontSize: 13, color: "var(--text-muted)", textAlign: "center" }}>ایمیل حساب را وارد کنید تا لینک بازیابی ارسال شود.</p>
      <div className="field"><label htmlFor="fg-email">ایمیل</label>
        <input id="fg-email" className="input" dir="ltr" style={{ textAlign: "left" }} value={em} onChange={(e) => setEm(e.target.value)} autoComplete="email" required />
      </div>
      <button className="btn btn-primary btn-block" disabled={busy}>{busy ? "در حال ارسال…" : "ارسال لینک بازیابی"}</button>
      <button type="button" className="btn btn-ghost btn-block" style={{ marginBlockStart: 8 }} onClick={onBack}>بازگشت به ورود</button>
    </form>
  );
}

/** دسته UIUX (افزودنی): ثبت رمز جدید با توکن ایمیل (POST /v1/auth/reset-password). */
function ResetForm({ email, onBack }: { email: string; onBack: () => void }) {
  const toast = useToast();
  const [em, setEm] = useState(email);
  const [token, setToken] = useState("");
  const [pw, setPw] = useState("");
  const [pw2, setPw2] = useState("");
  const [busy, setBusy] = useState(false);
  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (pw !== pw2) { toast("تکرار رمز عبور مطابقت ندارد.", "err"); return; }
    setBusy(true);
    try {
      const r = await fetch(`${API_BASE}/v1/auth/reset-password`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ email: em.trim(), token: token.trim(), password: pw, password_confirmation: pw2 }),
      });
      const j = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error((j as { message?: string })?.message ?? "تغییر رمز ناموفق بود.");
      toast((j as { message?: string })?.message ?? "رمز عبور تغییر کرد.", "ok");
      onBack();
    } catch (err) {
      toast(err instanceof Error ? err.message : "تغییر رمز ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };
  return (
    <form onSubmit={save}>
      <Stepper steps={["بازیابی رمز", "رمز جدید"]} current={1} />
      <div className="field"><label htmlFor="rs-email">ایمیل</label>
        <input id="rs-email" className="input" dir="ltr" style={{ textAlign: "left" }} value={em} onChange={(e) => setEm(e.target.value)} required />
      </div>
      <div className="field"><label htmlFor="rs-token">توکن بازیابی (از ایمیل)</label>
        <input id="rs-token" className="input" dir="ltr" style={{ textAlign: "left" }} value={token} onChange={(e) => setToken(e.target.value)} required />
      </div>
      <div className="field"><label htmlFor="rs-pw">رمز جدید</label>
        <input id="rs-pw" className="input" type="password" dir="ltr" style={{ textAlign: "left" }} value={pw} onChange={(e) => setPw(e.target.value)} required />
      </div>
      <div className="field"><label htmlFor="rs-pw2">تکرار رمز جدید</label>
        <input id="rs-pw2" className="input" type="password" dir="ltr" style={{ textAlign: "left" }} value={pw2} onChange={(e) => setPw2(e.target.value)} required />
      </div>
      <button className="btn btn-primary btn-block" disabled={busy}>{busy ? "…" : "تغییر رمز و ورود"}</button>
      <button type="button" className="btn btn-ghost btn-block" style={{ marginBlockStart: 8 }} onClick={onBack}>بازگشت به ورود</button>
    </form>
  );
}
