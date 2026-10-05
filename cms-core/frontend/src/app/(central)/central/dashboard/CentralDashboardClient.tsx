"use client";
import Link from "next/link";
import { Badge, StatCard } from "@/components/ui/primitives";
import { faNum } from "@/lib/fa";
import { OpenCase } from "@/lib/domain";
import type { CentralCharts, CentralStats } from "../types.ts";

const kindFa = (k: string) =>
  k === "wallet" ? "کیف پول" : k === "pool" ? "استخر" : k === "suspension" ? "تعلیق" : k === "deploy" ? "دیپلوی" : k === "overdue" ? "سررسید" : k;

/** داشبورد مرکزی: ۴ کارت + پرونده‌های باز قابل اکشن + نمودار خطی/میله‌ای SVG خالص. */
export function CentralDashboardClient({ stats, charts, cases }: { stats: CentralStats; charts: CentralCharts; cases: OpenCase[] }) {
  return (
    <div>
      <div className="grid c4" style={{ marginBlockEnd: 16 }}>
        <StatCard icon="◍" value={faNum(stats.active_clients)} label="مشتریان فعال" />
        <StatCard icon="⛔" value={faNum(stats.suspended_clients)} label="مشتریان معلق" />
        <StatCard icon="⬣" value={faNum(stats.mrr)} label="MRR (تومان)" />
        <StatCard icon="⚠" value={faNum(stats.overdue_invoices)} label="فاکتورهای سررسیدگذشته" />
      </div>

      <div className="grid c2" style={{ alignItems: "start", marginBlockEnd: 16 }}>
        <div className="card card-pad">
          <div className="card-title">⚡ پرونده‌های باز (قابل اکشن)</div>
          {cases.length === 0 ? (
            <p style={{ fontSize: 13, color: "var(--text-muted)" }}>پرونده بازی نیست — همه‌چیز آرام است.</p>
          ) : (
            <ul style={{ listStyle: "none", margin: 0, padding: 0 }}>
              {cases.map((c, i) => (
                <li key={`${c.kind}-${i}`} style={{ display: "flex", gap: 10, alignItems: "flex-start", paddingBlock: 11, borderBlockEnd: i < cases.length - 1 ? "1px dashed var(--border)" : "none", fontSize: 13 }}>
                  <Badge tone={c.severity === "critical" ? "red" : "amber"}>{kindFa(c.kind)}</Badge>
                  <div><b>{c.title}</b>{c.detail ? <div style={{ fontSize: 12, color: "var(--text-muted)" }}>{c.detail}</div> : null}</div>
                  <span style={{ marginInlineStart: "auto" }}>
                    <Link className={c.severity === "critical" ? "btn btn-soft btn-sm" : "btn btn-ghost btn-sm"} href={c.action}>{c.action_label}</Link>
                  </span>
                </li>
              ))}
            </ul>
          )}
        </div>
        <div className="card card-pad">
          <div className="card-title">درآمد ۱۲ ماه اخیر</div>
          <AreaChart points={charts.revenue_12m.map((r) => r.total)} labels={charts.revenue_12m.map((r) => r.month)} />
        </div>
      </div>

      <div className="grid c2" style={{ alignItems: "start" }}>
        <div className="card card-pad">
          <div className="card-title">مشتریان جدید ۱۲ ماه اخیر</div>
          <BarsChart points={charts.customers_12m.map((r) => r.count)} labels={charts.customers_12m.map((r) => r.month)} />
        </div>
      </div>
    </div>
  );
}

function AreaChart({ points, labels }: { points: number[]; labels: string[] }) {
  const W = 520;
  const H = 180;
  const max = Math.max(1, ...points);
  const step = W / Math.max(1, points.length - 1);
  const path = points
    .map((v, i) => `${i === 0 ? "M" : "L"}${(i * step).toFixed(1)},${(H - 12 - (v / max) * (H - 32)).toFixed(1)}`)
    .join(" ");
  return (
    <figure style={{ margin: 0 }}>
      <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label="نمودار درآمد" style={{ inlineSize: "100%", blockSize: "auto" }}>
        <path d={`${path} L${W},${H} L0,${H} Z`} fill="var(--primary-soft)" />
        <path d={path} fill="none" stroke="var(--primary)" strokeWidth={2.5} />
        {points.map((v, i) => (
          <circle key={i} cx={i * step} cy={H - 12 - (v / max) * (H - 32)} r={3} fill="var(--primary)">
            <title>{`${labels[i]}: ${v}`}</title>
          </circle>
        ))}
      </svg>
      <figcaption style={{ fontSize: 11.5, color: "var(--text-muted)" }}>
        {labels[0]} تا {labels[labels.length - 1]} — مجموع: {faNum(points.reduce((a, b) => a + b, 0))} تومان
      </figcaption>
    </figure>
  );
}

function BarsChart({ points, labels }: { points: number[]; labels: string[] }) {
  const H = 180;
  const max = Math.max(1, ...points);
  return (
    <figure style={{ margin: 0 }}>
      <div style={{ display: "flex", alignItems: "flex-end", gap: 4, blockSize: H }} role="img" aria-label="نمودار مشتریان جدید">
        {points.map((v, i) => (
          <div
            key={i}
            title={`${labels[i]}: ${v}`}
            style={{
              flex: 1,
              blockSize: `${Math.max(3, (v / max) * 100)}%`,
              background: "var(--primary)",
              borderRadius: "4px 4px 0 0",
              opacity: 0.55 + 0.45 * (v / max),
            }}
          />
        ))}
      </div>
      <figcaption style={{ fontSize: 11.5, color: "var(--text-muted)" }}>
        {labels[0]} تا {labels[labels.length - 1]} — مجموع: {faNum(points.reduce((a, b) => a + b, 0))} مشتری
      </figcaption>
    </figure>
  );
}
