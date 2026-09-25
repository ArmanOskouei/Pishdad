"use client";
import { useState } from "react";
import { Modal } from "./Overlays";

/**
 * تأیید دومرحله‌ای عملیات مخرب: مرحله ۱ بازبینی (+ تایپ عبارت اختیاری)،
 * مرحله ۲ اجرای async با وضعیت پیشرفت/نتیجه. دکمه‌ها تا پایان قفل‌اند.
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
      <div className="stepper" aria-label="مراحل تأیید">
        {steps.map((s, i) => {
          const cur = phase === "review" ? 0 : 1;
          return (
            <span key={s} style={{ display: "contents" }}>
              <span className={`step ${i === cur ? "now" : ""} ${i < cur ? "done" : ""}`}>
                <span className="n">{i < cur ? "✓" : i + 1}</span>{s}
              </span>
              {i < steps.length - 1 ? <span className="step-line" /> : null}
            </span>
          );
        })}
      </div>
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
