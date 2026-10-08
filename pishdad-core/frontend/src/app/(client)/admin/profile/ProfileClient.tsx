"use client";
import { useCallback, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Alert, Badge } from "@/components/ui/primitives";
import { CopyToClipboard } from "@/components/ui/CopyToClipboard";
import { MediaPicker } from "@/components/ui/MediaPicker";
import { useTheme, type ThemeState } from "@/lib/theme";
import { faNum, jalali } from "@/lib/fa";
import { mediaUrl, type MediaItem, type ProfileData, type TwoFactorMethods, type UserSession, type LoginEvent } from "@/lib/domain";

/** فرم‌های پروفایل: مشخصات + رمز + 2FA دومرحله‌ای + AppearancePanel فشرده. */
export function ProfileClient({ initial, methods, issuer = "CMS" }: { initial: ProfileData; methods: TwoFactorMethods | null; issuer?: string }) {
  const [profile, setProfile] = useState(initial);
  const searchParams = useSearchParams();
  const setupRequired = searchParams?.get("setup") === "2fa";

  return (
    <div className="grid c2" style={{ alignItems: "start" }}>
      {setupRequired ? (
        <div style={{ gridColumn: "1 / -1" }}>
          <Alert tone="amber">
            نقش شما ورود با تأیید دومرحله‌ای را الزامی می‌کند. برای ادامه، همین‌حالا در همین صفحه تأیید دومرحله‌ای را فعال کنید.
          </Alert>
        </div>
      ) : null}
      <div>
        <BasicForm initial={profile} onUpdated={setProfile} />
        <PasswordForm />
        <SmsCard methods={methods} />
      </div>
      <div>
        <TwoFactorCard initial={profile} methods={methods} issuer={issuer} />
        <SessionsCard />
        <LoginHistoryCard />
        <AppearanceMini />
      </div>
    </div>
  );
}

function BasicForm({ initial, onUpdated }: { initial: ProfileData; onUpdated: (profile: ProfileData) => void }) {
  const toast = useToast();
  const [name, setName] = useState(initial.name);
  const [email, setEmail] = useState(initial.email);
  const [currentPassword, setCurrentPassword] = useState("");
  const [phone, setPhone] = useState(initial.phone ?? "");
  const [firstName, setFirstName] = useState(initial.first_name ?? "");
  const [lastName, setLastName] = useState(initial.last_name ?? "");
  const [bio, setBio] = useState(initial.bio ?? "");
  const [avatarId, setAvatarId] = useState<number | null>(initial.avatar_media_id ?? null);
  const [avatarUrl, setAvatarUrl] = useState<string | null>(initial.avatar_url ?? null);
  const [picker, setPicker] = useState(false);
  const [busy, setBusy] = useState(false);

  useEffect(() => setEmail(initial.email), [initial.email]);

  const emailChanged = email.trim().toLowerCase() !== initial.email.trim().toLowerCase();

  const pickAvatar = (ids: number[], items: MediaItem[]) => {
    const id = ids[0] ?? null;
    setAvatarId(id);
    setAvatarUrl(id && items[0] ? mediaUrl(items[0]) : (id ? avatarUrl : null));
  };

  const save = async () => {
    const normalizedEmail = email.trim().toLowerCase();
    if (!name.trim()) { toast("نام الزامی است.", "err"); return; }
    if (!normalizedEmail || !/^\S+@\S+\.\S+$/.test(normalizedEmail)) { toast("قالب ایمیل معتبر نیست.", "err"); return; }
    if (firstName.trim().length > 60 || lastName.trim().length > 60) { toast("نام و نام خانوادگی حداکثر ۶۰ نویسه.", "err"); return; }
    if (bio.trim().length > 1000) { toast("متن «درباره من» حداکثر ۱۰۰۰ نویسه.", "err"); return; }
    if (emailChanged && !currentPassword) { toast("برای تغییر ایمیل، رمز فعلی الزامی است.", "err"); return; }
    setBusy(true);
    try {
      const result = await authed<Partial<ProfileData> & { two_factor_disabled?: boolean }>(`/v1/admin/profile`, {
        method: "PUT",
        body: {
          name: name.trim(),
          email: normalizedEmail,
          ...(emailChanged ? { current_password: currentPassword } : {}),
          phone: phone.trim() || null,
          first_name: firstName.trim() || null,
          last_name: lastName.trim() || null,
          bio: bio.trim() || null,
          avatar_media_id: avatarId,
        },
      });
      const { two_factor_disabled: twoFactorDisabled, ...profileData } = result;
      onUpdated({
        ...initial,
        ...profileData,
        id: profileData.id ?? initial.id,
        name: profileData.name ?? initial.name,
        email: profileData.email ?? initial.email,
        google2fa_enabled: profileData.google2fa_enabled ?? initial.google2fa_enabled,
        roles: initial.roles,
      });
      setCurrentPassword("");
      toast(
        twoFactorDisabled
          ? "پروفایل به‌روزرسانی شد؛ تأیید دومرحله‌ای غیرفعال شد و باید دوباره فعال شود."
          : "پروفایل به‌روزرسانی شد.",
        "ok",
      );
    } catch (e) {
      toast(e instanceof Error ? e.message : "ذخیره ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
      <div className="card-title">مشخصات</div>
      <div className="field"><label>ایمیل</label>
        <input className="input" type="email" dir="ltr" style={{ textAlign: "left" }} value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="email" />
      </div>
      {emailChanged ? (
        <div className="field"><label>رمز فعلی برای تغییر ایمیل</label>
          <input className="input" type="password" dir="ltr" style={{ textAlign: "left" }} value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} autoComplete="current-password" />
          <small style={{ color: "var(--text-muted)" }}>تغییر ایمیل حساس است؛ تأیید رمز فعلی الزامی است.</small>
        </div>
      ) : null}
      <div className="kv"><span>نقش‌ها</span><span>{initial.roles.join("، ") || "—"}</span></div>
      <div style={{ display: "flex", gap: 12, alignItems: "center", marginBlock: 10 }}>
        {avatarUrl ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={avatarUrl} alt="تصویر پروفایل" style={{ inlineSize: 64, blockSize: 64, borderRadius: "50%", objectFit: "cover", border: "1px solid var(--border)" }} />
        ) : (
          <span className="brand-mark" style={{ inlineSize: 64, blockSize: 64, fontSize: 22, borderRadius: "50%" }} aria-hidden>
            {(firstName.trim() || name).slice(0, 1)}
          </span>
        )}
        <div style={{ display: "flex", gap: 8 }}>
          <button type="button" className="btn btn-ghost btn-sm" onClick={() => setPicker(true)}>انتخاب عکس پروفایل…</button>
          {avatarId ? <button type="button" className="btn btn-ghost btn-sm" onClick={() => { setAvatarId(null); setAvatarUrl(null); }}>حذف عکس</button> : null}
        </div>
      </div>
      <div className="field"><label>نام</label><input className="input" value={name} onChange={(e) => setName(e.target.value)} /></div>
      <div className="grid c2">
        <div className="field"><label>نام (حداکثر ۶۰ نویسه)</label><input className="input" value={firstName} onChange={(e) => setFirstName(e.target.value)} maxLength={60} /></div>
        <div className="field"><label>نام خانوادگی (حداکثر ۶۰ نویسه)</label><input className="input" value={lastName} onChange={(e) => setLastName(e.target.value)} maxLength={60} /></div>
      </div>
      <div className="field"><label>موبایل (مثل 09123456789)</label>
        <input className="input" dir="ltr" style={{ textAlign: "left" }} value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="09…" />
      </div>
      <div className="field"><label>درباره من (حداکثر ۱۰۰۰ نویسه)</label>
        <textarea className="textarea" rows={3} value={bio} onChange={(e) => setBio(e.target.value)} maxLength={1000} placeholder="کمی درباره خودتان بنویسید…" />
      </div>
      <button className="btn btn-primary" onClick={() => void save()} disabled={busy}>{busy ? "…" : "ذخیره مشخصات"}</button>
      <MediaPicker open={picker} selected={avatarId ? [avatarId] : []} onChange={pickAvatar} onClose={() => setPicker(false)} />
    </div>
  );
}

function PasswordForm() {
  const toast = useToast();
  const [cur, setCur] = useState("");
  const [pw, setPw] = useState("");
  const [pw2, setPw2] = useState("");
  const [busy, setBusy] = useState(false);

  const save = async () => {
    if (pw !== pw2) { toast("تکرار رمز عبور مطابقت ندارد.", "err"); return; }
    setBusy(true);
    try {
      await authed(`/v1/admin/profile/password`, {
        method: "POST",
        body: { current_password: cur, password: pw, password_confirmation: pw2 },
      });
      toast("رمز تغییر کرد — همه نشست‌های دیگر باطل شدند. لطفاً دوباره وارد شوید.", "ok");
      setCur(""); setPw(""); setPw2("");
    } catch (e) {
      toast(e instanceof Error ? e.message : "تغییر رمز ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
      <div className="card-title">تغییر رمز عبور</div>
      <div className="field"><label>رمز فعلی</label><input className="input" type="password" dir="ltr" style={{ textAlign: "left" }} value={cur} onChange={(e) => setCur(e.target.value)} /></div>
      <div className="field"><label>رمز جدید (۱۰+ نویسه + حروف/عدد/نویسه ویژه)</label><input className="input" type="password" dir="ltr" style={{ textAlign: "left" }} value={pw} onChange={(e) => setPw(e.target.value)} /></div>
      <div className="field"><label>تکرار رمز جدید</label><input className="input" type="password" dir="ltr" style={{ textAlign: "left" }} value={pw2} onChange={(e) => setPw2(e.target.value)} /></div>
      <button className="btn btn-primary" onClick={() => void save()} disabled={busy}>{busy ? "…" : "تغییر رمز"}</button>
    </div>
  );
}

const AUTO_SUBMIT_DELAY = 300;
const OTP_PATTERN = /^\d{6}$/;

type TotpSetup = { secret: string; otpauth_url?: string | null };

function normalizeTotpCode(value: string): string {
  return value
    .replace(/[٠-٩]/g, (digit) => String(digit.charCodeAt(0) - 0x660))
    .replace(/[۰-۹]/g, (digit) => String(digit.charCodeAt(0) - 0x6f0))
    .replace(/[\s-]/g, "")
    .slice(0, 6);
}

function buildOtpauthUrl(secret: string, email: string, issuer: string): string {
  const normalizedIssuer = issuer.trim() || "CMS";
  const label = `${encodeURIComponent(normalizedIssuer)}:${encodeURIComponent(email.trim())}`;
  const query = new URLSearchParams({
    secret,
    issuer: normalizedIssuer,
    algorithm: "SHA1",
    digits: "6",
    period: "30",
  });
  return `otpauth://totp/${label}?${query.toString()}`;
}

const RECOVERY_LOW_THRESHOLD = 2;

function buildRecoveryCodesText(codes: string[], email: string): string {
  return [
    "کدهای بازیابی تأیید دومرحله‌ای",
    `حساب: ${email}`,
    `تاریخ: ${jalali(new Date().toISOString())}`,
    `تعداد کد: ${codes.length}`,
    "----------------------------------------",
    ...codes,
    "----------------------------------------",
    "هر کد فقط یک‌بار قابل استفاده است.",
    "این فایل را در جای امن نگه دارید و با کسی به اشتراک نگذارید.",
  ].join("\r\n");
}

/** WF-M9 — دانلود کدهای بازیابی به‌صورت txt از Blobِ سمت کلاینت. */
function downloadRecoveryCodes(codes: string[], email: string): void {
  const blob = new Blob(["\uFEFF" + buildRecoveryCodesText(codes, email)], { type: "text/plain;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = "recovery-codes.txt";
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

/** WF-M9 — چاپ کدهای بازیابی در پنجرهٔ مستقل. */
function printRecoveryCodes(codes: string[], email: string): void {
  const win = window.open("", "_blank", "width=480,height=640");
  if (!win) return;
  const escapeHtml = (value: string) => value.replace(/[&<>"']/g, (ch) => (
    { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch] as string
  ));
  const rows = codes.map((c) => `<li><code>${escapeHtml(c)}</code></li>`).join("");
  win.document.write(
    `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>کدهای بازیابی</title>`
    + `<style>body{font-family:system-ui,sans-serif;padding:24px;line-height:1.9}`
    + `code{font-size:18px;direction:ltr;display:inline-block}li{margin-block:6px}</style></head><body>`
    + `<h1>کدهای بازیابی تأیید دومرحله‌ای</h1><p>حساب: ${escapeHtml(email)}</p><ol>${rows}</ol>`
    + `<p>هر کد فقط یک‌بار قابل استفاده است؛ آن را در جای امن نگه دارید.</p></body></html>`,
  );
  win.document.close();
  win.focus();
  win.print();
}

function TwoFactorCard({ initial, methods, issuer }: { initial: ProfileData; methods: TwoFactorMethods | null; issuer: string }) {
  const toast = useToast();
  const [enabled, setEnabled] = useState(initial.google2fa_enabled);
  const [qr, setQr] = useState<TotpSetup | null>(null);
  const [qrDataUrl, setQrDataUrl] = useState<string | null>(null);
  const [qrLoading, setQrLoading] = useState(false);
  const [qrError, setQrError] = useState(false);
  const [code, setCode] = useState("");
  const [recovery, setRecovery] = useState<string[] | null>(null);
  const [busy, setBusy] = useState(false);
  const [regenOpen, setRegenOpen] = useState(false);
  const [proof, setProof] = useState("");
  const [regenBusy, setRegenBusy] = useState(false);
  const [remaining, setRemaining] = useState<number | null>(methods?.recovery_codes_remaining ?? null);
  const confirmingRef = useRef(false);
  const codeInputRef = useRef<HTMLInputElement | null>(null);

  useEffect(() => {
    setRemaining(methods?.recovery_codes_remaining ?? null);
  }, [methods]);

  useEffect(() => {
    setEnabled(initial.google2fa_enabled);
    if (!initial.google2fa_enabled) {
      setQr(null);
      setQrDataUrl(null);
      setQrError(false);
      setCode("");
      setRecovery(null);
    }
  }, [initial.google2fa_enabled]);

  // WF-M9 — تازگیِ شمارنده از endpointِ اختصاصیِ «باقی‌مانده» (سرور آستانهٔ
  // هشدار را هم می‌دهد). `enabled` کلیدِ وابستگی است تا فقط در حالت فعال و بعد
  // از فعال‌سازیِ همین‌سشن، مقدار واقعی کشیده شود.
  useEffect(() => {
    if (!enabled) {
      setRemaining(0);
      return;
    }
    let cancelled = false;
    void authed<{ remaining: number }>(`/v1/admin/profile/2fa/recovery-codes`)
      .then((r) => { if (!cancelled) setRemaining(r.remaining); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, [enabled]);

  useEffect(() => {
    let cancelled = false;
    if (!qr) {
      setQrDataUrl(null);
      setQrError(false);
      setQrLoading(false);
      return;
    }

    const otpauthUrl = qr.otpauth_url?.trim() || buildOtpauthUrl(qr.secret, initial.email, issuer);
    setQrDataUrl(null);
    setQrError(false);
    setQrLoading(true);

    void import("qrcode")
      .then(({ default: QRCode }) => {
        const rootStyles = getComputedStyle(document.documentElement);
        const dark = rootStyles.getPropertyValue("--qr-foreground").trim();
        const light = rootStyles.getPropertyValue("--qr-background").trim();
        return QRCode.toDataURL(otpauthUrl, {
          errorCorrectionLevel: "M",
          margin: 4,
          width: 256,
          ...(dark && light ? { color: { dark, light } } : {}),
        });
      })
      .then((dataUrl) => {
        if (!cancelled) setQrDataUrl(dataUrl);
      })
      .catch(() => {
        if (!cancelled) setQrError(true);
      })
      .finally(() => {
        if (!cancelled) setQrLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [initial.email, issuer, qr]);

  const start = async () => {
    setCode("");
    setBusy(true);
    try {
      const r = await authed<TotpSetup>(`/v1/admin/profile/2fa/enable`, { method: "POST", body: {} });
      setQr({ secret: r.secret, otpauth_url: r.otpauth_url ?? null });
    } catch (e) {
      toast(e instanceof Error ? e.message : "شروع فعال‌سازی ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  const confirm = useCallback(async () => {
    if (confirmingRef.current || busy || !qr) return;
    const normalizedCode = normalizeTotpCode(code);
    if (!OTP_PATTERN.test(normalizedCode)) return;

    confirmingRef.current = true;
    setBusy(true);
    try {
      const r = await authed<{ recovery_codes: string[] }>(`/v1/admin/profile/2fa/enable`, { method: "POST", body: { code: normalizedCode } });
      setRecovery(r.recovery_codes);
      setRemaining(r.recovery_codes.length);
      setEnabled(true);
      setQr(null);
      setCode("");
      toast("تأیید دومرحله‌ای فعال شد.", "ok");
    } catch (e) {
      setCode("");
      toast(e instanceof Error ? e.message : "کد تأیید صحیح نیست.", "err");
      window.setTimeout(() => codeInputRef.current?.focus(), 0);
    } finally {
      confirmingRef.current = false;
      setBusy(false);
    }
  }, [busy, code, qr, toast]);

  useEffect(() => {
    const normalizedCode = normalizeTotpCode(code);
    if (enabled || !qr || busy || confirmingRef.current || !OTP_PATTERN.test(normalizedCode)) return;

    const timer = window.setTimeout(() => {
      if (!confirmingRef.current) void confirm();
    }, AUTO_SUBMIT_DELAY);
    return () => window.clearTimeout(timer);
  }, [busy, code, confirm, enabled, qr]);

  const disable = async () => {
    setBusy(true);
    try {
      await authed(`/v1/admin/profile/2fa/disable`, { method: "POST", body: { code: normalizeTotpCode(code) } });
      setEnabled(false);
      setQr(null);
      setCode("");
      setRecovery(null);
      setRegenOpen(false);
      setProof("");
      setRemaining(0);
      toast("تأیید دومرحله‌ای غیرفعال شد.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "غیرفعال‌سازی ناموفق بود (کد TOTP لازم است).", "err");
    } finally {
      setBusy(false);
    }
  };

  const regenerate = async () => {
    const value = proof.trim();
    if (!value) return;
    const normalizedProof = normalizeTotpCode(value);
    const body = OTP_PATTERN.test(normalizedProof)
      ? { code: normalizedProof }
      : { password: value };
    setRegenBusy(true);
    try {
      const r = await authed<{ recovery_codes: string[] }>(`/v1/admin/profile/2fa/recovery-codes`, { method: "POST", body });
      setRecovery(r.recovery_codes);
      setRemaining(r.recovery_codes.length);
      setRegenOpen(false);
      setProof("");
      toast("کدهای بازیابی بازتولید شد.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "بازتولید کدهای بازیابی ناموفق بود.", "err");
    } finally {
      setRegenBusy(false);
    }
  };

  const cancelSetup = () => {
    if (busy) return;
    setQr(null);
    setQrDataUrl(null);
    setQrError(false);
    setCode("");
  };

  const normalizedCode = normalizeTotpCode(code);
  const otpReady = OTP_PATTERN.test(normalizedCode);

  return (
    <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
      <div className="card-title">
        تأیید دومرحله‌ای <Badge tone={enabled ? "green" : "gray"}>{enabled ? "فعال" : "غیرفعال"}</Badge>
      </div>
      {enabled || methods ? (
        <div>
          <div className="kv"><span>کدهای بازیابی باقی‌مانده</span><span>{remaining === null ? "…" : faNum(remaining)}</span></div>
          {enabled && remaining !== null && remaining <= RECOVERY_LOW_THRESHOLD ? (
            <Alert tone="red">
              تعداد کدهای بازیابی باقی‌مانده کم است ({faNum(remaining)}). با تمام‌شدن آن‌ها ورود اضطراری ممکن نیست؛ همین‌حالا کدهای تازه بسازید.
            </Alert>
          ) : null}
        </div>
      ) : null}

      {!enabled && !qr ? (
        <button className="btn btn-primary" onClick={() => void start()} disabled={busy}>{busy ? "…" : "شروع فعال‌سازی (TOTP)"}</button>
      ) : null}

      {!enabled && qr ? (
        <div aria-busy={qrLoading || busy}>
          <Alert tone="blue">پس از اسکن QR، کد ۶ رقمی نمایش‌داده‌شده در اپلیکیشن را وارد کنید.</Alert>
          <div className="totp-qr-card" role="group" aria-labelledby="totp-qr-title">
            <div className="totp-qr-frame">
              {qrDataUrl ? (
                <img src={qrDataUrl} width={256} height={256} alt="کد QR فعال‌سازی تأیید دومرحله‌ای برای اپلیکیشن احراز هویت" />
              ) : qrError ? (
                <p className="totp-qr-status" role="status">ساخت QR ناموفق بود؛ کد متنی Secret را دستی وارد کنید.</p>
              ) : (
                <div className="totp-qr-loading" role="status" aria-label="در حال ساخت QR">
                  <span className="otp-spinner" aria-hidden="true" />
                </div>
              )}
            </div>
            <p id="totp-qr-title" className="totp-qr-instruction">این کد را با اپلیکیشن احراز هویت اسکن کنید</p>
            <p className="totp-qr-apps">Google Authenticator، Authy یا 1Password</p>
          </div>

          <div className="field">
            <label htmlFor="totp-secret">کد متنی Secret</label>
            <div className="totp-secret-row">
              <code id="totp-secret" dir="ltr">{qr.secret}</code>
              <CopyToClipboard text={qr.secret} label="کپی" />
            </div>
            <small className="totp-hint">در صورت نبود امکان اسکن، Secret را در اپلیکیشن وارد کنید.</small>
          </div>

          <div className="field">
            <label htmlFor="totp-code">کد ۶ رقمی</label>
            <input
              ref={codeInputRef}
              id="totp-code"
              className="input"
              dir="ltr"
              inputMode="numeric"
              autoComplete="one-time-code"
              style={{ textAlign: "left" }}
              value={code}
              onChange={(e) => setCode(normalizeTotpCode(e.target.value))}
              onKeyDown={(e) => {
                if (e.key === "Enter" && otpReady) {
                  e.preventDefault();
                  void confirm();
                }
              }}
              placeholder="123456"
              maxLength={12}
              disabled={busy}
            />
          </div>
          <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
            <button type="button" className="btn btn-ghost" onClick={cancelSetup} disabled={busy}>انصراف</button>
            <button type="button" className="btn btn-primary" onClick={() => void confirm()} disabled={busy || !otpReady}>
              {busy ? <><span className="otp-spinner" aria-hidden="true" /> در حال تأیید…</> : otpReady ? "آمادهٔ فعال‌سازی خودکار" : "تأیید و فعال‌سازی"}
            </button>
          </div>
          <p className="totp-status" role="status" aria-live="polite">
            {busy ? "در حال بررسی کد…" : qrLoading ? "در حال آماده‌سازی QR…" : "پس از وارد کردن ۶ رقم، فعال‌سازی خودکار انجام می‌شود."}
          </p>
        </div>
      ) : null}

      {recovery ? (
        <Alert tone="amber">
          <div>کدهای بازیابی — فقط همین‌بار نمایش داده می‌شود، در جای امن نگه دارید:</div>
          <div dir="ltr" style={{ fontFamily: "monospace", marginBlockStart: 6 }}>{recovery.join(" · ")}</div>
          <div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginBlockStart: 10 }}>
            <button type="button" className="btn btn-primary btn-sm" onClick={() => downloadRecoveryCodes(recovery, initial.email)}>
              دانلود کدهای بازیابی (txt)
            </button>
            <button type="button" className="btn btn-ghost btn-sm" onClick={() => printRecoveryCodes(recovery, initial.email)}>
              چاپ
            </button>
          </div>
        </Alert>
      ) : null}

      {enabled ? (
        <div>
          <div className="field"><label>کد TOTP برای غیرفعال‌سازی</label>
            <input className="input" dir="ltr" style={{ textAlign: "left" }} value={code} onChange={(e) => setCode(normalizeTotpCode(e.target.value))} placeholder="123456" inputMode="numeric" autoComplete="one-time-code" disabled={busy} />
          </div>
          <button className="btn btn-ghost" onClick={() => void disable()} disabled={busy}>{busy ? "…" : "غیرفعال‌سازی 2FA"}</button>

          <div className="field" style={{ marginBlockStart: 14 }}>
            {!regenOpen ? (
              <button type="button" className="btn btn-ghost" onClick={() => { setRegenOpen(true); setProof(""); }} disabled={busy || regenBusy}>
                بازتولید کدهای بازیابی
              </button>
            ) : (
              <div>
                <Alert tone="amber">با بازتولید، کدهای بازیابی قبلی باطل می‌شوند. برای تأیید، کد TOTP یا رمز عبور خود را وارد کنید.</Alert>
                <label htmlFor="regen-proof">کد TOTP یا رمز عبور</label>
                <input
                  id="regen-proof"
                  className="input"
                  dir="ltr"
                  style={{ textAlign: "left" }}
                  value={proof}
                  onChange={(e) => setProof(e.target.value)}
                  autoComplete="off"
                  disabled={regenBusy}
                />
                <div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginBlockStart: 8 }}>
                  <button type="button" className="btn btn-ghost" onClick={() => { setRegenOpen(false); setProof(""); }} disabled={regenBusy}>انصراف</button>
                  <button type="button" className="btn btn-primary" onClick={() => void regenerate()} disabled={regenBusy || !proof.trim()}>
                    {regenBusy ? "…" : "تأیید و بازتولید"}
                  </button>
                </div>
              </div>
            )}
          </div>
        </div>
      ) : null}
    </div>
  );
}

/** AppearancePanel فشرده (بخش ۱.۴ از SPEC) — حالت کامل در /admin/appearance. */
function AppearanceMini() {
  const { theme, set } = useTheme();
  const pick = (p: Partial<ThemeState>) => set(p);
  return (
    <div className="card card-pad">
      <div className="card-title">ظاهر پنل <Link href="/admin/appearance" style={{ color: "var(--primary)", fontSize: 12 }}>تنظیمات کامل ←</Link></div>
      <div className="field"><label>جهت بصری (پیش‌نمایش زنده)</label>
        <div className="seg">
          {([["sahar", "سپیده"], ["amaliyat", "عملیات"], ["arya", "آریا"], ["narm", "نرم"], ["divan", "دیوان"]] as const).map(([v, l]) => (
            <button key={v} className={theme.preset === v ? "on" : ""} onClick={() => pick({ preset: v })}>{l}</button>
          ))}
        </div>
      </div>
      <div className="field"><label>حالت</label>
        <div className="seg" style={{ maxInlineSize: 220 }}>
          <button className={theme.mode === "light" ? "on" : ""} onClick={() => pick({ mode: "light" })}>روشن</button>
          <button className={theme.mode === "dark" ? "on" : ""} onClick={() => pick({ mode: "dark" })}>تیره</button>
        </div>
      </div>
      <div className="field"><label>تراکم</label>
        <div className="seg" style={{ maxInlineSize: 260 }}>
          {(["compact", "comfortable", "loose"] as const).map((v) => (
            <button key={v} className={theme.density === v ? "on" : ""} onClick={() => pick({ density: v })}>
              {v === "compact" ? "فشرده" : v === "comfortable" ? "متوسط" : "باز"}
            </button>
          ))}
        </div>
      </div>
      <div className="field"><label>شعاع گوشه‌ها</label>
        <div className="seg" style={{ maxInlineSize: 260 }}>
          {(["sharp", "default", "rounded"] as const).map((v) => (
            <button key={v} className={theme.radius === v ? "on" : ""} onClick={() => pick({ radius: v })}>
              {v === "sharp" ? "تیز" : v === "default" ? "پیش‌فرض" : "گرد"}
            </button>
          ))}
        </div>
      </div>
      <small style={{ color: "var(--text-muted)" }}>تغییرات همین‌جا زنده پیش‌نمایش می‌شوند؛ ذخیره دائم در <Link href="/admin/appearance" style={{ color: "var(--primary)" }}>قالب پنل</Link>.</small>
    </div>
  );
}

/** دسته UIUX (افزودنی): روش «تأیید پیامکی» با تست/قطع. */
function SmsCard({ methods }: { methods: TwoFactorMethods | null }) {
  const toast = useToast();
  const [phone, setPhone] = useState(methods?.sms?.phone ?? "");
  const [code, setCode] = useState("");
  const [pending, setPending] = useState(false);
  const [verified, setVerified] = useState(methods?.sms?.enabled ?? false);
  const [busy, setBusy] = useState(false);
  // L-B6/F0.4 — تصمیم (ب): «نمایش‌با-غیرفعال». درگاه واقعی وصل نیست (E3) پس
  // گفتن «ارسال شد» دروغ است؛ سرور کد sms.not_configured می‌دهد و UI شاخهٔ
  // صادقانه را نشان می‌دهد، نه فرم تأیید.
  const [unavailable, setUnavailable] = useState(false);
  const [unavailableMsg, setUnavailableMsg] = useState("");

  const request = async () => {
    if (!/^09\d{9}$/.test(phone.trim())) { toast("شماره موبایل معتبر نیست (مثل 09123456789).", "err"); return; }
    setBusy(true);
    try {
      const r = await authed<{ debug_code?: string; code?: string; message?: string }>(`/v1/admin/profile/sms/request`, {
        method: "POST", body: { phone: phone.trim() },
      });
      if (r?.code === "sms.not_configured") {
        setUnavailable(true);
        setUnavailableMsg(r?.message ?? "سرویس پیامک هنوز فعال نشده است؛ کدی ارسال نشد.");
        return;
      }
      setPending(true);
      toast(r?.debug_code ? `کد تست: ${r.debug_code}` : (r?.message ?? "کد تأیید پیامکی ارسال شد."), "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "ارسال کد ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  const verify = async () => {
    setBusy(true);
    try {
      await authed(`/v1/admin/profile/sms/verify`, { method: "POST", body: { code: code.trim() } });
      setVerified(true);
      setPending(false);
      toast("شماره موبایل تأیید شد.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "کد تأیید صحیح نیست.", "err");
    } finally {
      setBusy(false);
    }
  };

  const disconnect = async () => {
    setBusy(true);
    try {
      await authed(`/v1/admin/profile/sms`, { method: "DELETE" });
      setVerified(false);
      setPending(false);
      toast("تأیید پیامکی قطع شد.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "قطع ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
      <div className="card-title">
        تأیید پیامکی <Badge tone={verified ? "green" : "gray"}>{verified ? "متصل" : "قطع"}</Badge>
      </div>
      {!verified ? (
        <div>
          {unavailable ? (
            <p role="note" style={{ color: "var(--text-muted)", fontSize: 13 }}>
              {unavailableMsg || "سرویس پیامک هنوز فعال نشده است؛ کدی ارسال نمی‌شود."}
            </p>
          ) : null}
          <div className="field"><label>شماره موبایل</label>
            <input className="input" dir="ltr" style={{ textAlign: "left" }} value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="09123456789" disabled={unavailable} />
          </div>
          {!pending ? (
            <button className="btn btn-primary" onClick={() => void request()} disabled={busy || unavailable}>{busy ? "…" : "ارسال کد تست"}</button>
          ) : (
            <div>
              <div className="field"><label>کد ۶ رقمی پیامک</label>
                <input className="input" dir="ltr" style={{ textAlign: "left" }} value={code} onChange={(e) => setCode(e.target.value)} placeholder="123456" />
              </div>
              <div style={{ display: "flex", gap: 8 }}>
                <button className="btn btn-ghost" onClick={() => setPending(false)}>انصراف</button>
                <button className="btn btn-primary" onClick={() => void verify()} disabled={busy}>{busy ? "…" : "تأیید و اتصال"}</button>
              </div>
            </div>
          )}
        </div>
      ) : (
        <div>
          <div className="kv"><span>شماره متصل</span><span dir="ltr">{phone || "—"}</span></div>
          <button className="btn btn-ghost" onClick={() => void disconnect()} disabled={busy}>{busy ? "…" : "قطع تأیید پیامکی"}</button>
        </div>
      )}
    </div>
  );
}

/** دسته UIUX (افزودنی): نشست‌های فعال + خروج تکی/جمعی. */
function SessionsCard() {
  const toast = useToast();
  const [items, setItems] = useState<UserSession[] | null>(null);
  const [busy, setBusy] = useState(false);
  const [dropping, setDropping] = useState<number | null>(null);

  const load = async () => {
    try {
      const r = await authed<{ sessions: UserSession[] }>(`/v1/admin/profile/sessions`);
      setItems(r.sessions);
    } catch (e) {
      toast(e instanceof Error ? e.message : "بارگذاری نشست‌ها ناموفق بود.", "err");
    }
  };

  useEffect(() => { void load(); }, []);

  const revokeAll = async () => {
    setBusy(true);
    try {
      const r = await authed<{ revoked: number }>(`/v1/admin/profile/sessions/revoke-all`, { method: "POST" });
      toast(`${faNum(r.revoked)} نشست دیگر باطل شد.`, "ok");
      await load();
    } catch (e) {
      toast(e instanceof Error ? e.message : "باطل‌سازی ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  const dropOne = async (id: number) => {
    setDropping(id);
    try {
      await authed(`/v1/admin/profile/sessions/${id}`, { method: "DELETE" });
      toast("نشست بسته شد.", "ok");
      await load();
    } catch (e) {
      toast(e instanceof Error ? e.message : "بستن نشست ناموفق بود.", "err");
    } finally {
      setDropping(null);
    }
  };

  return (
    <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
      <div className="card-title">
        نشست‌های فعال {items ? <Badge tone="gray">{faNum(items.length)}</Badge> : null}
      </div>
      {items === null ? (
        <button className="btn btn-ghost btn-sm" onClick={() => void load()}>نمایش نشست‌ها</button>
      ) : items.length === 0 ? (
        <p style={{ fontSize: 13, color: "var(--text-muted)" }}>نشستی ثبت نشده.</p>
      ) : (
        <div>
          {items.map((s) => (
            <SessionRow key={s.id} s={s} dropping={dropping === s.id} onDrop={() => void dropOne(s.id)} />
          ))}
          <button className="btn btn-ghost" onClick={() => void revokeAll()} disabled={busy}>
            {busy ? "…" : "خروج از همه دستگاه‌ها (به‌جز همین نشست)"}
          </button>
        </div>
      )}
    </div>
  );
}

function SessionRow({ s, dropping, onDrop }: { s: UserSession; dropping: boolean; onDrop: () => void }) {
  const osLabel = formatOsLabel(s.os, s.os_version);
  const browserLabel = formatBrowserLabel(s.browser, s.browser_version);
  const osTitle = osLabel ?? "سیستم‌عامل نامشخص";
  const browserTitle = browserLabel
    ? `${browserLabel}${osLabel ? ` روی ${osLabel}` : ""}`
    : osLabel ? `مرورگر نامشخص روی ${osLabel}` : "مرورگر نامشخص";
  const sessionTitle = browserLabel && osLabel
    ? `${browserLabel} روی ${osLabel}`
    : browserLabel ?? osLabel ?? s.device ?? "نشست نامشخص";

  return (
    <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", gap: 12, paddingBlock: 12, borderBlockEnd: "1px solid var(--border)" }}>
      <div style={{ display: "flex", alignItems: "flex-start", gap: 12, minInlineSize: 0, flex: 1 }}>
        <div style={{ display: "flex", flexDirection: "column", alignItems: "center", gap: 6, flexShrink: 0 }}>
          <span role="img" aria-label={osTitle} title={osTitle} style={{ display: "grid", placeItems: "center", inlineSize: 40, minBlockSize: 40 }}>
            <OsIcon os={s.os} />
          </span>
          <span role="img" aria-label={browserTitle} title={browserTitle} style={{ display: "grid", placeItems: "center", inlineSize: 40, minBlockSize: 40 }}>
            <BrowserIcon browser={s.browser} />
          </span>
        </div>
        <div style={{ minInlineSize: 0 }}>
          <div style={{ display: "flex", alignItems: "center", gap: 8, flexWrap: "wrap" }}>
            <b title={sessionTitle}>{sessionTitle}</b>
            {s.current ? <Badge tone="green">همین دستگاه</Badge> : null}
          </div>
          <small style={{ color: "var(--text-muted)", display: "block" }}>
            {osTitle}{browserLabel ? ` · ${browserLabel}` : ""}{s.device_type ? ` · ${s.device_type}` : ""}
          </small>
          <small style={{ color: "var(--text-muted)", display: "block" }}>ورود: {jalali(s.created_at)}</small>
          <small style={{ color: "var(--text-muted)", display: "block" }}>آخرین فعالیت: {jalali(s.last_used_at)}</small>
          {s.ip ? <small style={{ color: "var(--text-muted)", display: "block" }}>IP: <span dir="ltr">{s.ip}</span></small> : null}
        </div>
      </div>
      {s.current ? null : (
        <button className="btn btn-ghost btn-sm" onClick={onDrop} disabled={dropping} aria-label="خروج از این نشست" style={{ flexShrink: 0 }}>
          {dropping ? "…" : "خروج از این نشست"}
        </button>
      )}
    </div>
  );
}

/** WF-H8 — تاریخچهٔ ورود: آخرین ~۱۰ تلاش (موفق/ناموفق)، مکملِ نشست‌های فعال. */
function LoginHistoryCard() {
  const toast = useToast();
  const [items, setItems] = useState<LoginEvent[] | null>(null);

  const load = async () => {
    try {
      const r = await authed<{ events: LoginEvent[] }>(`/v1/admin/profile/login-history`);
      setItems(r.events);
    } catch (e) {
      toast(e instanceof Error ? e.message : "بارگذاری تاریخچهٔ ورود ناموفق بود.", "err");
    }
  };

  useEffect(() => { void load(); }, []);

  return (
    <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
      <div className="card-title">
        تاریخچهٔ ورود {items ? <Badge tone="gray">{faNum(items.length)}</Badge> : null}
      </div>
      {items === null ? (
        <button className="btn btn-ghost btn-sm" onClick={() => void load()}>نمایش تاریخچه</button>
      ) : items.length === 0 ? (
        <p style={{ fontSize: 13, color: "var(--text-muted)" }}>ورودی ثبت نشده.</p>
      ) : (
        <div>
          {items.map((e) => (
            <LoginEventRow key={e.id} e={e} />
          ))}
        </div>
      )}
    </div>
  );
}

function LoginEventRow({ e }: { e: LoginEvent }) {
  const osLabel = formatOsLabel(e.os, e.os_version);
  const browserLabel = formatBrowserLabel(e.browser, e.browser_version);
  const osTitle = osLabel ?? "سیستم‌عامل نامشخص";
  const browserTitle = browserLabel ?? "مرورگر نامشخص";
  const deviceTitle = browserLabel && osLabel
    ? `${browserLabel} روی ${osLabel}`
    : browserLabel ?? osLabel ?? e.device ?? "دستگاه نامشخص";

  return (
    <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", gap: 12, paddingBlock: 12, borderBlockEnd: "1px solid var(--border)" }}>
      <div style={{ minInlineSize: 0 }}>
        <div style={{ display: "flex", alignItems: "center", gap: 8, flexWrap: "wrap" }}>
          <b title={deviceTitle}>{deviceTitle}</b>
          <Badge tone={e.successful ? "green" : "red"}>{e.successful ? "موفق" : "ناموفق"}</Badge>
        </div>
        {e.device_type ? <small style={{ color: "var(--text-muted)", display: "block" }}>{e.device_type}</small> : null}
        <small style={{ color: "var(--text-muted)", display: "block" }}>{jalali(e.created_at)}</small>
        {e.ip ? <small style={{ color: "var(--text-muted)", display: "block" }}>IP: <span dir="ltr">{e.ip}</span></small> : null}
      </div>
      <div style={{ display: "flex", alignItems: "flex-start", gap: 8, flexShrink: 0 }}>
        <span role="img" aria-label={osTitle} title={osTitle} style={{ display: "grid", placeItems: "center", inlineSize: 40, minBlockSize: 40 }}>
          <OsIcon os={e.os} />
        </span>
        <span role="img" aria-label={browserTitle} title={browserTitle} style={{ display: "grid", placeItems: "center", inlineSize: 40, minBlockSize: 40 }}>
          <BrowserIcon browser={e.browser} />
        </span>
      </div>
    </div>
  );
}

function majorVersion(value?: string | null): string | null {
  return value?.match(/\d+/)?.[0] ?? null;
}

function formatOsLabel(os?: string | null, version?: string | null): string | null {
  const rawName = os?.trim();
  if (!rawName) return null;
  const name = rawName.replace(/\s+\d+(?:[._]\d+)*$/, "");
  const major = majorVersion(version);
  const normalized = name.toLowerCase();
  if (normalized === "windows" && major === "10") return "Windows 10/11";
  return major ? `${name} ${major}` : name;
}

function formatBrowserLabel(browser?: string | null, version?: string | null): string | null {
  const name = browser?.trim();
  if (!name || name.toLowerCase() === "mozilla") return null;
  const major = majorVersion(version);
  return major ? `${name} ${major}` : name;
}

function OsIcon({ os }: { os?: string | null }) {
  const common = { width: 34, height: 34, viewBox: "0 0 24 24", "aria-hidden": true, focusable: "false" } as const;
  switch ((os ?? "").toLowerCase()) {
    case "windows":
      return (
        <svg {...common}>
          <path fill="#0078D4" d="M2.5 5.1 11 3.8v8.1h-8.5z" />
          <path fill="#00A4EF" d="M12.2 3.6 21.5 2.2v9.7h-9.3z" />
          <path fill="#0078D4" d="M2.5 13.1H11v8.1l-8.5-1.3z" />
          <path fill="#00A4EF" d="M12.2 13.1h9.3v9.7l-9.3-1.4z" />
        </svg>
      );
    case "ios":
    case "macos":
      return (
        <svg {...common} style={{ color: "#A1A1A6" }}>
          <path fill="currentColor" d="M16.7 12.9c0-2.4 2-3.6 2.1-3.7-1.1-1.7-2.9-1.9-3.5-1.9-1.5-.2-2.9.9-3.7.9-.8 0-1.9-.9-3.2-.9-1.6 0-3.1 1-4 2.4-1.7 2.9-.4 7.3 1.2 9.7.8 1.2 1.8 2.5 3.1 2.4 1.2-.1 1.7-.8 3.2-.8s1.9.8 3.2.8c1.3 0 2.2-1.2 3-2.4.9-1.4 1.3-2.7 1.3-2.8-.1 0-2.7-1-2.7-3.7M14.3 5.4c.7-.8 1.1-1.9 1-3-1 0-2.1.6-2.8 1.4-.6.7-1.2 1.9-1 3 1 .1 2.1-.6 2.8-1.4" />
        </svg>
      );
    case "android":
      return (
        <svg {...common} style={{ color: "#3DDC84" }}>
          <path fill="currentColor" d="M17.5 12.5c.8-.4 1.5-1.2 1.9-2.1l1.6.7c-.5 1.2-1.4 2.2-2.6 2.8l-.9-1.4M6.5 12.5 5.6 13.9c-1.2-.6-2.1-1.6-2.6-2.8l1.6-.7c.4.9 1.1 1.7 1.9 2.1M12 8.5c-2.8 0-5.2 1.5-6.3 3.7l1.5.7c.9-1.7 2.8-2.9 4.8-2.9s3.9 1.2 4.8 2.9l1.5-.7C17.2 10 14.8 8.5 12 8.5M8.5 4 7.3 4.6l1.4 2.6c.9-.4 2-.6 3.3-.6s2.4.2 3.3.6L16.7 4.6 15.5 4l-1.2 2.3c-.7-.2-1.5-.3-2.3-.3s-1.6.1-2.3.3zM12 10v8.5c0 .8.7 1.5 1.5 1.5s1.5-.7 1.5-1.5V14h1v4.5c0 .8.7 1.5 1.5 1.5s1.5-.7 1.5-1.5V10zM7 10v8.5c0 .8-.7 1.5-1.5 1.5S4 19.3 4 18.5V10z" transform="scale(.92) translate(1,1)" />
        </svg>
      );
    case "linux":
      return (
        <svg {...common} style={{ color: "#F2C94C" }}>
          <path fill="currentColor" d="M12 3c-2.2 0-3.6 1.8-3.6 4.3 0 1.3-.3 2.2-1.1 3.4C6 12.5 4.7 15 4.7 17.2c0 2.3 2.9 3.8 7.3 3.8s7.3-1.5 7.3-3.8c0-2.2-1.3-4.7-2.6-6.5-.8-1.2-1.1-2.1-1.1-3.4C15.6 4.8 14.2 3 12 3zm-2.1 2.1c.5 0 .8.4.8.9s-.3.9-.8.9-.8-.4-.8-.9.3-.9.8-.9zm4.2 0c.5 0 .8.4.8.9s-.3.9-.8.9-.8-.4-.8-.9.3-.9.8-.9zM12 11.2c1.4 0 2.5.6 2.5 1.4 0 .7-1.1 1.3-2.5 1.3s-2.5-.6-2.5-1.3c0-.8 1.1-1.4 2.5-1.4zM8.4 18.3c.8.8 2.1 1.3 3.6 1.3s2.8-.5 3.6-1.3c-.6 1.6-1.9 2.5-3.6 2.5s-3-.9-3.6-2.5z" />
        </svg>
      );
    default:
      return (
        <svg {...common} style={{ color: "#64748B" }}>
          <path fill="currentColor" d="M12 2 2 7v10l10 5 10-5V7zm0 2.3L18.7 8 12 11.7 5.3 8zM4 9.7l7 3.9v6.9l-7-3.5zm9 10.8v-6.9l7-3.9v6.9z" />
        </svg>
      );
  }
}

function BrowserIcon({ browser }: { browser?: string | null }) {
  const common = { width: 34, height: 34, viewBox: "0 0 24 24", "aria-hidden": true, focusable: "false" } as const;
  switch ((browser ?? "").toLowerCase()) {
    case "chrome":
      return (
        <svg {...common}>
          <path fill="#EA4335" d="M12 3a9 9 0 0 1 7.8 4.5H12a4.5 4.5 0 0 0-3.9 2.2L5.6 7.3A9 9 0 0 1 12 3Z" />
          <path fill="#FBBC05" d="m5.6 7.3 2.5 4.2A4.5 4.5 0 0 0 12 16.5V21a9 9 0 0 1-6.4-13.7Z" />
          <path fill="#34A853" d="M12 16.5a4.5 4.5 0 0 0 3.8-2l2.5 4A9 9 0 0 1 12 21Z" />
          <path fill="#4285F4" d="M21 12a9 9 0 0 1-9 9v-4.5a4.5 4.5 0 0 0 3.8-7l2.5-4A9 9 0 0 1 21 12Z" />
          <circle cx="12" cy="12" r="3.2" fill="#fff" />
          <circle cx="12" cy="12" r="2.2" fill="#4285F4" />
        </svg>
      );
    case "firefox":
      return (
        <svg {...common}>
          <circle cx="12" cy="13" r="8" fill="#FF7139" />
          <path fill="#5B1B77" d="M5.1 6.8c3.5 1.8 7.6 1.3 11.4.2-1.2 3.5-3.9 5.3-6.6 6.8 2.2.6 4.7.1 6.4-1.6A8 8 0 1 1 5.1 6.8Z" />
          <path fill="#FFD800" d="m6.3 9.4 4.5 1.5-2.7 2.4Z" />
        </svg>
      );
    case "safari":
      return (
        <svg {...common}>
          <circle cx="12" cy="12" r="9" fill="#1B88FF" />
          <circle cx="12" cy="12" r="7.4" fill="#F4FAFF" />
          <path fill="#FF3B30" d="m16.2 7.8-2 5.2-5.2 2 2-5.2z" />
          <path fill="#D70000" d="m16.2 7.8-2 5.2-1.1-4.1z" />
        </svg>
      );
    case "edge":
      return (
        <svg {...common}>
          <path fill="#0F7C8A" d="M21 12a9 9 0 1 1-9-9c4.5 0 7.7 2.3 9 5.5H12a4.5 4.5 0 0 0-4.2 2.9A4.5 4.5 0 0 0 12 16.5h9c0 1.5-.4 2.9-1.1 4.1A9 9 0 0 1 21 12Z" />
          <path fill="#2C7BE5" d="M7.8 11.4A4.5 4.5 0 0 1 12 7.5h9c-1.3-3.2-4.5-5.5-9-5.5a9 9 0 0 0-7.4 13.8 9 9 0 0 1 3.2-4.4Z" />
        </svg>
      );
    case "opera":
      return (
        <svg {...common}>
          <ellipse cx="12" cy="12" rx="10" ry="8" fill="#FF1B2D" />
          <ellipse cx="12" cy="12" rx="6.8" ry="4.7" fill="#B41425" />
          <ellipse cx="12" cy="12" rx="4.7" ry="3" fill="#F7F7F7" />
        </svg>
      );
    case "samsung internet":
      return (
        <svg {...common}>
          <circle cx="12" cy="12" r="9" fill="#1428A0" />
          <path fill="none" stroke="#fff" strokeWidth="1.8" d="M6.5 9.5h11M7 12h10M8 14.5h8" />
        </svg>
      );
    default:
      return (
        <svg {...common} style={{ color: "#64748B" }}>
          <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" strokeWidth="1.8" />
          <path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18" fill="none" stroke="currentColor" strokeWidth="1.8" />
        </svg>
      );
  }
}
