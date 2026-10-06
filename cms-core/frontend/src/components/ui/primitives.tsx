import Link from "next/link";

export function Badge({ tone = "gray", children }: { tone?: "violet" | "green" | "amber" | "red" | "gray"; children: React.ReactNode }) {
  const cls = { violet: "b-violet", green: "b-green", amber: "b-amber", red: "b-red", gray: "b-gray" }[tone];
  return <span className={`badge ${cls}`}>{children}</span>;
}

export function Alert({ tone = "blue", children }: { tone?: "blue" | "amber" | "red" | "green"; children: React.ReactNode }) {
  const cls = { blue: "a-blue", amber: "a-amber", red: "a-red", green: "a-green" }[tone];
  return <div className={`alert ${cls}`} role="alert">{children}</div>;
}

export function StatCard({ icon, value, label, delta, up }: { icon: string; value: string; label: string; delta?: string; up?: boolean }) {
  return (
    <div className="card stat">
      <div className="stat-ic" style={{ background: "var(--primary-soft)", color: "var(--primary)" }} aria-hidden>{icon}</div>
      <div>
        <div className="stat-num">{value}</div>
        <div className="stat-lbl">{label}</div>
        {delta ? <div className={`stat-delta ${up ? "up" : "down"}`}>{delta}</div> : null}
      </div>
    </div>
  );
}

export function EmptyState({
  title,
  hint,
  icon,
  action,
  cta,
}: {
  title: string;
  hint?: string;
  /** آیکون SVG درون‌خطی بالای حالت خالی (بدون CDN). */
  icon?: React.ReactNode;
  /** اکشن دلخواه؛ مثلاً یک کامپوننت کلاینتی. */
  action?: React.ReactNode;
  /**
   * CTA اصلی حالت خالی. `href` ⇒ لینک، در غیر این صورت دکمه.
   * `onClick` تنها از یک کامپوننت کلاینتی قابل پاس دادن است (نه Server Component).
   */
  cta?: { label: string; href?: string; onClick?: () => void; tone?: "primary" | "ghost"; disabled?: boolean };
}) {
  const ctaCls = `btn ${cta?.tone === "ghost" ? "btn-ghost" : "btn-primary"}`;
  return (
    <div className="empty">
      {icon ? <div style={{ fontSize: 28, lineHeight: 1, marginBlockEnd: 8 }} aria-hidden>{icon}</div> : null}
      <div style={{ fontSize: 15, fontWeight: 700, color: "var(--text)" }}>{title}</div>
      {hint ? <p style={{ margin: "6px 0 12px" }}>{hint}</p> : null}
      {action}
      {cta ? (
        <div style={{ marginBlockStart: action ? 10 : 0 }}>
          {cta.href ? (
            <Link className={ctaCls} href={cta.href}>{cta.label}</Link>
          ) : (
            <button type="button" className={ctaCls} onClick={cta.onClick} disabled={cta.disabled}>{cta.label}</button>
          )}
        </div>
      ) : null}
    </div>
  );
}

/**
 * F0-B6 — حالت‌های مرحله.
 *
 * `done` / `now` از اول بودند. سه حالتِ دیگر اضافه شد چون نبودِشان یعنی UI
 * دربارهٔ وضعیتِ واقعی **دروغ** می‌گفت: مرحلهٔ شکست‌خورده مثل «در حال اجرا» رنگ
 * می‌شد و مرحلهٔ قفل مثل «هنوز نرسیده‌ایم».
 *
 * - `failed`  خطا در همین مرحله.
 * - `locked`  هنوز نرسیده و *نمی‌شود* پریدش (مثلاً تایپ عبارتِ تأیید کامل نشده).
 * - `skipped` از این مرحله عبور شد بدون اجرا.
 */
export type StepState = "done" | "now" | "todo" | "failed" | "locked" | "skipped";

export function Stepper({ steps, current, states }: { steps: string[]; current: number; states?: StepState[] }) {
  return (
    <div className="stepper" aria-label="مراحل">
      {steps.map((s, i) => {
        const st: StepState = states?.[i] ?? (i < current ? "done" : i === current ? "now" : "todo");
        const locked = st === "locked" || st === "skipped";
        return (
          <span key={s} style={{ display: "contents" }}>
            <span
              className={`step ${st}`}
              {...(st === "now" ? { "aria-current": "step" as const } : {})}
              {...(locked ? { "aria-disabled": true as const } : {})}
            >
              <span className="n" aria-hidden>
                {st === "done" ? "✓" : st === "skipped" ? "–" : st === "failed" ? "✕" : ["۱", "۲", "۳", "۴", "۵", "۶"][i] ?? i + 1}
              </span>
              {s}
            </span>
            {i < steps.length - 1 ? <span className={`step-line ${st}`} /> : null}
          </span>
        );
      })}
    </div>
  );
}

export function Skeleton({ lines = 3 }: { lines?: number }) {
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 10 }} aria-busy="true" aria-label="در حال بارگذاری">
      {Array.from({ length: lines }).map((_, i) => (
        <div key={i} className="skel" style={{ blockSize: 18 }} />
      ))}
    </div>
  );
}

/**
 * `saveLabel` پارامتری است چون این کامپوننت عمومی است: «ذخیره قالب» روی صفحهٔ
 * نقش‌ها یعنی مسخره. پیش‌فرض عمداً عمومی است؛ هر صفحه‌ای که واقعاً قالب
 * ذخیره می‌کند خودش `saveLabel="ذخیره قالب"` می‌دهد.
 */
export function SaveBar({ dirtyText, onCancel, onSave, onReset, saveLabel = "تأیید و ذخیره" }: { dirtyText: string; onCancel: () => void; onSave: () => void; onReset?: () => void; saveLabel?: string }) {
  return (
    <div className="savebar" role="toolbar" aria-label="نوار ذخیره">
      <span>◉ پیش‌نمایش: <b>{dirtyText}</b></span>
      <div style={{ flex: 1 }} />
      {onReset ? <button className="btn btn-ghost btn-sm" onClick={onReset}>بازنشانی</button> : null}
      <button className="btn btn-ghost" onClick={onCancel}>انصراف</button>
      <button className="btn btn-primary" onClick={onSave}>{saveLabel}</button>
    </div>
  );
}
