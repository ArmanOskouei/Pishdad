"use client";
import { Alert, Badge, EmptyState, StatCard } from "@/components/ui/primitives";
import { Pagination } from "@/components/ui/Pagination";
import { faNum, jalali, relativeFa } from "@/lib/fa";
import { HealthRow, Paginator, UptimeRow } from "@/lib/domain";
import type { CentralLogItem } from "../types.ts";

const stateTone = (s: string) => (s === "online" ? "green" : s === "silent" ? "amber" : "red") as "green" | "amber" | "red";
const STATE_FA: Record<string, string> = { online: "آنلاین", silent: "ساکت", never: "بدون heartbeat" };
const levelTone = (l: string) => (l === "critical" || l === "error" ? "red" : l === "warning" ? "amber" : "gray") as "red" | "amber" | "gray";

/** گرید سلامت + لاگ متمرکز با فیلتر + نوار آپتایم SVG. */
export function MonitoringClient({
  grid, silent, silentMinutes, logs, level, siteId, q, uptime,
}: {
  grid: HealthRow[];
  silent: HealthRow[];
  silentMinutes: number;
  logs: Paginator<CentralLogItem>;
  level: string;
  siteId: string;
  /** دسته UIUX (افزودنی): جستجوی متنی لاگ. */
  q?: string;
  /** دسته UIUX (افزودنی): سری آپتایم ۳۰ روزه per-client. */
  uptime?: UptimeRow[];
}) {
  const online = grid.filter((g) => g.state === "online").length;
  const uptimePct = grid.length === 0 ? 0 : Math.round((online / grid.length) * 100);

  return (
    <div>
      <div className="grid c4" style={{ marginBlockEnd: 16 }}>
        <StatCard icon="◍" value={faNum(grid.length)} label="کل نصب‌ها" />
        <StatCard icon="◉" value={faNum(online)} label="آنلاین" />
        <StatCard icon="⚠" value={faNum(silent.length)} label={`ساکت (>${faNum(silentMinutes)} دقیقه)`} />
        <StatCard icon="%" value={faNum(uptimePct)} label="آپتایم فلیت" />
      </div>

      {silent.length > 0 ? (
        <Alert tone="amber">{faNum(silent.length)} نصب ساکت یا بدون heartbeat: {silent.slice(0, 5).map((s) => s.name).join("، ")}{silent.length > 5 ? "…" : ""}</Alert>
      ) : null}

      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">آپتایم فلیت</div>
        <UptimeBar grid={grid} />
      </div>

      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">گرید سلامت نصب‌ها</div>
        {grid.length === 0 ? <EmptyState title="نصبی نیست" /> : (
          <div className="table-wrap">
            <table className="tbl tbl-cards">
              <thead><tr><th>مشتری</th><th>وضعیت heartbeat</th><th>نسخه</th><th>آخرین دیده‌شدن</th><th>حالت</th></tr></thead>
              <tbody>
                {grid.map((g) => (
                  <tr key={g.client_id}>
                    <td data-label="مشتری"><b>{g.name}</b> <small dir="ltr" style={{ color: "var(--text-muted)" }}>{g.site_id}</small></td>
                    <td data-label="وضعیت"><span dir="ltr">{g.status ?? "—"}</span></td>
                    <td data-label="نسخه"><span dir="ltr">{g.version ?? "—"}</span></td>
                    <td data-label="آخرین دیده‌شدن">{g.last_seen_at ? `${jalali(g.last_seen_at)} (${relativeFa(g.last_seen_at)})` : "هرگز"}</td>
                    <td data-label="حالت"><Badge tone={stateTone(g.state)}>{STATE_FA[g.state]}</Badge></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div className="card card-pad">
        <div className="card-title">لاگ متمرکز (۳۰ روز اخیر)</div>
        <form className="toolbar" method="get" action="/central/monitoring">
          <div className="field">
            <label htmlFor="lv">سطح</label>
            <select id="lv" name="level" className="select" defaultValue={level}>
              <option value="">همه</option>
              <option value="debug">debug</option>
              <option value="info">info</option>
              <option value="warning">warning</option>
              <option value="error">error</option>
              <option value="critical">critical</option>
            </select>
          </div>
          <div className="field grow">
            <label htmlFor="sid">site_id</label>
            <input id="sid" name="site_id" className="input" dir="ltr" style={{ textAlign: "left" }} defaultValue={siteId} placeholder="site_…" />
          </div>
          <div className="field grow">
            <label htmlFor="qq">جستجو در متن لاگ</label>
            <input id="qq" name="q" className="input" defaultValue={q ?? ""} placeholder="متن خطا یا site_id…" />
          </div>
          <button className="btn btn-ghost" type="submit">اعمال</button>
        </form>

        {logs.data.length === 0 ? <EmptyState title="لاگی نیست" hint="فیلتر دیگری امتحان کنید." /> : (
          logs.data.map((l) => (
            <div className="ver-row" key={l.id}>
              <Badge tone={levelTone(l.level)}><span dir="ltr">{l.level}</span></Badge>
              <span style={{ fontSize: 13 }}>{l.message}</span>
              <span style={{ flex: 1 }} />
              {l.site_id ? <small dir="ltr" style={{ color: "var(--text-muted)" }}>{l.site_id}</small> : null}
              <small style={{ color: "var(--text-muted)" }}>{jalali(l.created_at)}</small>
            </div>
          ))
        )}
        <Pagination page={logs.current_page} lastPage={logs.last_page} total={logs.total} base="/central/monitoring" params={{ level, site_id: siteId, q: q ?? "", page: String(logs.current_page) }} />
      </div>

      <div className="card card-pad" style={{ marginBlockStart: 14 }}>
        <div className="card-title">آپتایم ۳۰ روز هر نصب</div>
        <p style={{ fontSize: 12, color: "var(--text-muted)", margin: "0 0 10px" }}>فقط روز جاری واقعی است؛ روزهای گذشته تاریخچه ندارند (خاکستری = نامشخص).</p>
        {(uptime ?? []).length === 0 ? <EmptyState title="داده آپتایم نیست" /> : (
          <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
            {(uptime ?? []).map((c) => (
              <div key={c.client_id} style={{ display: "flex", gap: 8, alignItems: "center" }}>
                <b style={{ minInlineSize: 140, fontSize: 13 }}>{c.name}</b>
                <div style={{ display: "flex", gap: 2, flex: 1 }} role="img" aria-label={`آپتایم ۳۰ روز ${c.name}`}>
                  {c.days.map((d) => (
                    <span
                      key={d.date}
                      title={`${d.date}: ${d.status === "online" ? "آنلاین" : d.status === "silent" ? "ساکت" : "نامشخص"}`}
                      style={{
                        flex: 1, blockSize: 18, borderRadius: 3,
                        background: d.status === "online" ? "var(--success, #16a34a)" : d.status === "silent" ? "var(--warning, #d97706)" : "var(--border)",
                        opacity: d.status === "unknown" ? 0.6 : 0.9,
                      }}
                    />
                  ))}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

function UptimeBar({ grid }: { grid: HealthRow[] }) {
  if (grid.length === 0) return <p style={{ fontSize: 13, color: "var(--text-muted)" }}>داده‌ای نیست.</p>;
  return (
    <div style={{ display: "flex", gap: 3 }} role="img" aria-label="نوار آپتایم نصب‌ها">
      {grid.map((g) => (
        <span
          key={g.client_id}
          title={`${g.name}: ${STATE_FA[g.state]}`}
          style={{
            flex: 1, blockSize: 26, borderRadius: 4,
            background: g.state === "online" ? "var(--success, #16a34a)" : g.state === "silent" ? "var(--warning, #d97706)" : "var(--danger, #dc2626)",
            opacity: 0.85,
          }}
        />
      ))}
    </div>
  );
}
