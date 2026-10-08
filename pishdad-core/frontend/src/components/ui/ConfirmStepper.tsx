"use client";
import { useState } from "react";
import { Modal } from "./Overlays";
import { Stepper, type StepState } from "./primitives";

/**
 * تأیید دومرحله‌ای عملیات مخرب: مرحله ۱ بازبینی (+ تایپ عبارت اختیاری)،
 * مرحله ۲ اجرای async با وضعیت پیشرفت/نتیجه. دکمه‌ها تا پایان قفل‌اند.
 *
 * F0-B6 — وضعیتِ هر مرحله از `phase` مشتق می‌شود، نه از یک شمارندهٔ موازی. دلیلش
 * این است که قبلاً فقط `now`/`done` رنگ می‌شدند: در فاز `error` هر دو مرحله
 * «انجام‌شده/در حال انجام» به نظر می‌رسیدند، در حالی که واقعاً مرحلهٔ اجرا
 * **شکست خورده** بود. یک منبعِ حقیقت (`phase`) یعنی این حالت‌ها هرگز از هم
 * جدا نمی‌افتند.
 */
export function ConfirmStepper({
  open, title, description, steps = ["بازبینی", "اجرا"],
  requirePhrase, confirmLabel = "تأیید نهایی", busyLabel = "در حال اجرا…",
  onConfirm, onClose,
}: {
  open: boolean;
  title: string;
  description: string;
  steps?: string[];
  requirePhrase?: string;
  confirmLabel?: string;
  busyLabel?: string;
  onConfirm: () => Promise<string>;
  onClose: () => void;
}) {
  const [phrase, setPhrase] = useState("");
  const [phase, setPhase] = useState<"review" | "doing" | "done" | "error">("review");
  const [result, setResult] = useState("");
  if (!open) return null;
  const canGo = !requirePhrase || phrase.trim() === requirePhrase;

  /**
   * `doing` در مرحلهٔ ۱ یعنی «بازبینی تمام شد» و در مرحلهٔ ۲ یعنی «اجرا تمام
   * شد» — پس `current` دو حالت دارد و این تنها جایی است که باید صریح باشد.
   *
   * وضعیتِ هر مرحله **مشتق** است: یک جدولِ کوچک به‌جای شمارندهٔ موازی. اگر
   * `current` و رنگ جدا نگه داشته شوند، در فاز `error` دوباره ناهماهنگ می‌شوند.
   */
  const finished = phase === "done";
  const failed = phase === "error";
  const current = phase === "review" || finished || failed ? 0 : 1;
  const states: StepState[] = steps.map((_, i): StepState => {
    if (i < current) return "done";
    if (i > current) return "locked";
    // i === current
    if (finished) return "done";
    if (failed) return "failed";
    if (phase === "doing") return "now";
    // فاز بازبینی: مرحلهٔ اول `now`، ولی اگر عبارتِ تأیید هنوز درست نیست
    // قفل است — کاربر می‌بیند که تا کامل نکردن، راهِ جلو بسته است.
    return canGo ? "now" : "locked";
  });

  const run = async () => {
    setPhase("doing");
    try {
      const msg = await onConfirm();
      setResult(msg || "انجام شد.");
      setPhase("done");
    } catch (e) {
      setResult(e instanceof Error ? e.message : "خطایی رخ داد.");
      setPhase("error");
    }
  };
  const close = () => {
    if (phase === "doing") return;
    setPhase("review"); setPhrase(""); setResult("");
    onClose();
  };

  return (
    <Modal title={title} onClose={close}>
      <Stepper steps={steps} current={current} states={states} />
      {phase === "review" ? (
        <>
          <p style={{ fontSize: 13.5, color: "var(--text-muted)" }}>{description}</p>
          {requirePhrase ? (
            <div className="field" style={{ marginBlockStart: 12 }}>
              <label>برای تأیید، عبارت «{requirePhrase}» را تایپ کنید</label>
              <input className="input" value={phrase} onChange={(e) => setPhrase(e.target.value)} dir="ltr" style={{ textAlign: "left" }} />
            </div>
          ) : null}
          <div style={{ display: "flex", gap: 8, marginBlockStart: 16 }}>
            <button className="btn btn-ghost" onClick={close}>انصراف</button>
            <button className="btn btn-primary" onClick={run} disabled={!canGo}>ادامه</button>
          </div>
        </>
      ) : null}
      {phase === "doing" ? (
        <div aria-busy="true"><div className="progress"><i style={{ inlineSize: "60%" }} /></div><p style={{ fontSize: 13 }}>{busyLabel}</p></div>
      ) : null}
      {phase === "done" ? (
        <><div className="alert a-green" role="status">{result}</div><button className="btn btn-primary" onClick={close}>{confirmLabel}</button></>
      ) : null}
      {phase === "error" ? (
        <><div className="alert a-red" role="alert">{result}</div>
          <div style={{ display: "flex", gap: 8 }}><button className="btn btn-ghost" onClick={close}>بستن</button><button className="btn btn-primary" onClick={run}>تلاش مجدد</button></div></>
      ) : null}
    </Modal>
  );
}
