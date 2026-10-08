import { useCallback, useEffect, useRef, useState } from "react";

import { timeRemaining } from "@/lib/devmode-time";

/**
 * K4.7 تا K4.9 — دستگیرهٔ حالت توسعه‌دهنده.
 *
 * ## چرا این‌جا و نه در `SettingsClient`
 *
 * صفحهٔ تنظیمات ۱۷ `useState` دستی دارد و کاری به توسعه‌دهنده ندارد. اضافه‌کردن
 * ژستور، مودال رمز و شمارندهٔ معکوس آنجا یعنی یک مسئولیت کاملاً متفاوت در میان
 * stateای که به تنظیمات سایت مربوط است.
 *
 * ## چرا به‌صورت props و نه fetch داخلی
 *
 * دروازهٔ باز کردن هنوز route ندارد (`routes/api.php` در مالکیت کار دیگری است).
 * اگر این کامپوننت خودش `fetch` می‌زد، نیمه‌ساخته‌ای می‌شد که در هر اجرا خطا
 * می‌داد. به‌جایش هر سه کنش از بیرون تزریق می‌شوند، پس کامپوننت **همین حالا
 * کامل و قابل‌تست است** و به‌محض آماده‌شدن route فقط نگاشت‌ها عوض می‌شوند.
 *
 * ## قانون React
 *
 * `load` داخل `useEffect` صدا زده می‌شود و با `useCallback` پایدار است — نه
 * فراخوانی در بدنهٔ render. فراخوانی در render باعث حلقهٔ رندر و خطای #301
 * می‌شود که قبلاً این پروژه را از کار انداخته بود.
 */

export type DevModeStatus = {
  /** باز بودن حالت توسعه‌دهنده، یا null یعنی هنوز نخوانده شده. */
  enabled: boolean | null;
  /** ISO 8601 یا null. */
  expiresAt: string | null;
  /** شمارندهٔ ژستور. */
  count: number;
  required: number;
};

export const DEV_MODE_STATUS: DevModeStatus = {
  enabled: null,
  expiresAt: null,
  count: 0,
  required: 5,
};

export type DevModeGateProps = {
  /** خواندن وضعیت. */
  onLoad: () => Promise<Partial<DevModeStatus>>;
  /** ثبت یک کلیک ژستور. */
  onTap: () => Promise<Partial<DevModeStatus>>;
  /** باز کردن با رمز عبور. `ok: false` یعنی سرور رد کرده. */
  onUnlock: (password: string) => Promise<{ ok: boolean; message?: string }>;
  /** بستن حالت توسعه‌دهنده. */
  onLock: () => Promise<void>;
  /** فاصلهٔ حداقلی بین دو کلیک، میلی‌ثانیه — هم‌ریشه با `DevModeTaps`. */
  minGapMs?: number;
};

const MIN_GAP_MS = 2000;

export function DevModeGate({
  onLoad,
  onTap,
  onUnlock,
  onLock,
  minGapMs = MIN_GAP_MS,
}: DevModeGateProps) {
  const [status, setStatus] = useState<DevModeStatus>(DEV_MODE_STATUS);
  const [modalOpen, setModalOpen] = useState(false);
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  // آخرین زمان کلیک، تا فرانت هم جلوی کلیک‌اسپم را بگیرد. قاعدهٔ ۲ ثانیه
  // سمت سرور هست و این فقط تجربهٔ کاربر است — اگر نبود، کاربر چهار خطای
  // «خیلی سریع» می‌دید به‌جای اینکه دکمه موقتاً غیرفعال باشد.
  const lastTapRef = useRef(0);

  const load = useCallback(async () => {
    try {
      const next = await onLoad();
      setStatus((prev) => ({ ...prev, ...next }));
    } catch (e) {
      setError(e instanceof Error ? e.message : "خواندن وضعیت ناموفق بود.");
    }
  }, [onLoad]);

  useEffect(() => {
    void load();
  }, [load]);

  const tap = useCallback(async () => {
    const now = Date.now();
    if (now - lastTapRef.current < minGapMs) {
      return;
    }
    lastTapRef.current = now;

    setError(null);
    const next = await onTap();
    setStatus((prev) => ({ ...prev, ...next }));
    // مودال فقط وقتی ژستور **کامل** شد باز می‌شود. زودتر باز شدن یعنی کاربر رمز
    // را وارد می‌کند و رد می‌شود چون سرور هنوز شمارش را ناقص می‌بیند.
    if (next.count !== undefined && next.count >= (next.required ?? 5)) {
      setModalOpen(true);
    }
  }, [minGapMs, onTap]);

  const unlock = useCallback(async () => {
    setBusy(true);
    setError(null);
    try {
      const result = await onUnlock(password);
      if (!result.ok) {
        setError(result.message ?? "باز کردن ممکن نشد.");
        return;
      }
      setPassword("");
      setModalOpen(false);
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : "باز کردن ممکن نشد.");
    } finally {
      setBusy(false);
    }
  }, [load, onUnlock, password]);

  const lock = useCallback(async () => {
    setBusy(true);
    try {
      await onLock();
      await load();
    } finally {
      setBusy(false);
    }
  }, [load, onLock]);

  const left = Math.max(0, (status.required ?? 5) - status.count);
  const timeLeft = timeRemaining(status.expiresAt, Date.now());

  if (status.enabled === null) {
    return <div className="lock-banner">در حال خواندن وضعیت حالت توسعه‌دهنده…</div>;
  }

  return (
    <section aria-labelledby="devmode-heading" className="settings-block">
      <h2 id="devmode-heading">حالت توسعه‌دهنده</h2>

      {status.enabled ? (
        <>
          <p role="status">
            حالت توسعه‌دهنده باز است
            {timeLeft ? ` — ${timeLeft} دیگر باقی مانده` : " — در حال انقضا"}.
          </p>
          <button type="button" className="btn btn-ghost" onClick={() => void lock()} disabled={busy}>
            بستن حالت توسعه‌دهنده
          </button>
        </>
      ) : (
        <>
          <p>
            برای نصب بستهٔ بدون امضا، پنج بار روی دکمهٔ زیر کلیک کنید. سپس رمز عبور
            خود را وارد کنید.
          </p>
          <button
            type="button"
            className="btn btn-primary"
            onClick={() => void tap()}
            disabled={busy || left === 0}
          >
            {left === 0 ? "آمادهٔ ورود رمز" : `کلیک کنید (${left} مانده)`}
          </button>
          {/* شمارنده به‌عنوان متن کمکی، نه فقط در دکمه — صفحه‌خوان باید بشنود. */}
          <p aria-live="polite">
            {status.count} از {status.required} کلیک
          </p>
        </>
      )}

      {error ? (
        <p role="alert" className="form-error">
          {error}
        </p>
      ) : null}

      {modalOpen ? (
        <div role="dialog" aria-modal="true" aria-labelledby="devmode-modal-heading" className="modal">
          <h3 id="devmode-modal-heading">تأیید با رمز عبور</h3>
          <p>
            حالت توسعه‌دهنده اجازهٔ نصب بستهٔ بدون امضا می‌دهد. برای ادامه رمز عبور
            خود را وارد کنید.
          </p>
          <form
            onSubmit={(e) => {
              e.preventDefault();
              void unlock();
            }}
          >
            <label htmlFor="devmode-password">رمز عبور</label>
            <input
              id="devmode-password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              autoComplete="current-password"
              required
            />
            <div className="modal-actions">
              <button type="submit" className="btn btn-primary" disabled={busy || password === ""}>
                باز کن
              </button>
              <button type="button" className="btn btn-ghost" onClick={() => setModalOpen(false)}>
                انصراف
              </button>
            </div>
          </form>
        </div>
      ) : null}
    </section>
  );
}
