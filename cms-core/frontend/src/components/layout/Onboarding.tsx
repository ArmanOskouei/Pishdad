"use client";
import { useCallback, useEffect, useRef, useState } from "react";
import { useAuth } from "@/lib/auth";
import {
  readOnboardingSeen,
  shouldShowOnboarding,
  writeOnboardingSeen,
  type StorageLike,
} from "@/lib/onboarding";

/**
 * E8 — آنبوردینگ اولین ورود: اورلی ۳ مرحله‌ای کوتاه.
 *
 * - «اولین ورود» per-user است: کلید در localStorage به شناسهٔ همین کاربر گره
 *   خورده (`lib/onboarding.ts`). روی مرورگر مشترک، مدیرِ دوم هم راهنما را
 *   می‌بیند — که باگِ کلیدِ سراسریِ قبلی بود.
 * - تا تمام‌شدنِ بررسیِ نشست چیزی نشان نمی‌دهیم (نه به مهمان، نه هنگام
 *   `loading`)؛ تصمیمِ خالص در `lib/onboarding.ts` تست شده است.
 * - فقط خواندن/نوشتن `localStorage` — هیچ fetch، هیچ CDN، فونت از body به
 *   ارث می‌رسد (وزیرمتن محلی). RTL ذاتی + logical properties.
 * - قاعدهٔ AGENTS.md: هیچ setState در render نیست — همه‌چیز در useEffect و
 *   هندلرها (ضد React #301).
 */

const STEPS = [
  {
    title: "به پنل خوش آمدید",
    body: "از سایدبار به صفحات، فایل‌ها و تیکت‌ها می‌روید؛ جست‌وجوی بالای صفحه همه‌جا را می‌گردد.",
  },
  {
    title: "اشتراک و پرداخت",
    body: "وضعیت پلن و فاکتورها در «اشتراک من» است. اگر پنل قفل شد، همان‌جا پرداخت کنید تا خودکار باز شود.",
  },
  {
    title: "کمک هر وقت لازم بود",
    body: "تیکت‌ها مستقیم به پشتیبانی می‌رسد. این راهنما فقط یک‌بار نشان داده می‌شود.",
  },
] as const;

/** Storage مرورگر با محافظِ عدم‌دسترسی (حالت خصوصی/SSR). */
function browserStorage(): StorageLike | null {
  try {
    return typeof window === "undefined" ? null : window.localStorage;
  } catch {
    return null;
  }
}

export function Onboarding() {
  const { user, loading } = useAuth();
  const [open, setOpen] = useState(false);
  const [step, setStep] = useState(0);
  const dialogRef = useRef<HTMLDivElement | null>(null);

  const userId = user?.id ?? null;

  useEffect(() => {
    if (shouldShowOnboarding({ loading, userId, seen: readOnboardingSeen(browserStorage(), userId) })) {
      setStep(0);
      setOpen(true);
    } else {
      setOpen(false);
    }
  }, [loading, userId]);

  const dismiss = useCallback(() => {
    writeOnboardingSeen(browserStorage(), userId);
    setOpen(false);
  }, [userId]);

  useEffect(() => {
    if (!open) return;
    // فوکوس به دیالوگ + بستن با Escape (دسترس‌پذیری).
    dialogRef.current?.focus();
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") dismiss();
    };
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [open, dismiss]);

  if (!open) return null;

  const last = step === STEPS.length - 1;
  const current = STEPS[step]!;

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="onboarding-title"
      style={{
        position: "fixed",
        inset: 0,
        zIndex: 90,
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
        padding: 16,
        background: "color-mix(in srgb, var(--bg) 55%, transparent)",
      }}
    >
      <div
        ref={dialogRef}
        className="card card-pad"
        tabIndex={-1}
        style={{ maxInlineSize: 420, inlineSize: "100%", textAlign: "start", outline: "none" }}
      >
        <div style={{ fontSize: 12, color: "var(--text-muted)" }}>
          مرحلهٔ {step + 1} از {STEPS.length}
        </div>
        <h2 id="onboarding-title" style={{ marginBlock: "6px 8px", fontSize: 18 }}>{current.title}</h2>
        <p style={{ margin: 0, fontSize: 14, lineHeight: 2, color: "var(--text)" }}>{current.body}</p>
        <div style={{ display: "flex", gap: 6, marginBlock: 12 }} aria-hidden="true">
          {STEPS.map((_, i) => (
            <span
              key={i}
              style={{
                blockSize: 6,
                inlineSize: 28,
                borderRadius: 3,
                background: i === step ? "var(--primary)" : "var(--border)",
              }}
            />
          ))}
        </div>
        <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
          {step > 0 ? (
            <button type="button" className="btn btn-ghost btn-sm" onClick={() => setStep((s) => s - 1)}>
              قبلی
            </button>
          ) : null}
          <div style={{ flex: 1 }} />
          <button type="button" className="btn btn-ghost btn-sm" onClick={dismiss}>
            رد کردن
          </button>
          {last ? (
            <button type="button" className="btn btn-primary btn-sm" onClick={dismiss}>
              شروع کار
            </button>
          ) : (
            <button type="button" className="btn btn-primary btn-sm" onClick={() => setStep((s) => s + 1)}>
              بعدی
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
