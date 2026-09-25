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

export function EmptyState({ title, hint, action }: { title: string; hint?: string; action?: React.ReactNode }) {
  return (
    <div className="empty">
      <div style={{ fontSize: 15, fontWeight: 700, color: "var(--text)" }}>{title}</div>
      {hint ? <p style={{ margin: "6px 0 12px" }}>{hint}</p> : null}
      {action}
    </div>
  );
}

export function Stepper({ steps, current }: { steps: string[]; current: number }) {
  return (
    <div className="stepper" aria-label="مراحل">
      {steps.map((s, i) => (
        <span key={s} style={{ display: "contents" }}>
          <span className={`step ${i === current ? "now" : ""} ${i < current ? "done" : ""}`}>
            <span className="n">{i < current ? "✓" : ["۱", "۲", "۳", "۴"][i] ?? i + 1}</span>{s}
          </span>
          {i < steps.length - 1 ? <span className="step-line" /> : null}
        </span>
      ))}
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

export function SaveBar({ dirtyText, onCancel, onSave, onReset }: { dirtyText: string; onCancel: () => void; onSave: () => void; onReset?: () => void }) {
  return (
    <div className="savebar" role="toolbar" aria-label="نوار ذخیره">
      <span>◉ پیش‌نمایش: <b>{dirtyText}</b></span>
      <div style={{ flex: 1 }} />
      {onReset ? <button className="btn btn-ghost btn-sm" onClick={onReset}>بازنشانی</button> : null}
      <button className="btn btn-ghost" onClick={onCancel}>انصراف</button>
      <button className="btn btn-primary" onClick={onSave}>تأیید و ذخیره قالب</button>
    </div>
  );
}
