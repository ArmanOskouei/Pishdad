"use client";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Alert, Badge, EmptyState } from "@/components/ui/primitives";
import { ConfirmStepper } from "@/components/ui/ConfirmStepper";
import { faNum, jalali } from "@/lib/fa";
import type { CurrentRollout, UpdatesData } from "@/lib/domain";

/** جدول نصب‌ها + رول‌اوت پلکانی دومرحله‌ای + rollback هر نصب. */
export function UpdatesClient({ initial, current }: { initial: UpdatesData; current?: CurrentRollout | null }) {
  const toast = useToast();
  const router = useRouter();
  const [version, setVersion] = useState(initial.latest?.version ?? "");
  const [batchSize, setBatchSize] = useState(10);
  const [rollout, setRollout] = useState(false);
  const [result, setResult] = useState<{ rollout: { id: number; version: string }; batches: Array<Array<{ client_id: number; batch_index: number }>> } | null>(null);

  const doRollout = async (): Promise<string> => {
    const r = await authed<{ rollout: { id: number; version: string }; batches: Array<Array<{ client_id: number; batch_index: number }>> }>(
      `/central/updates/rollout`,
      { method: "POST", body: { version, batch_size: batchSize, confirm: true } },
    );
    setResult(r);
    router.refresh();
    return `رول‌اوت #${r.rollout.id} به نسخه ${r.rollout.version} در ${r.batches.length} بچ ثبت شد.`;
  };

  const rollback = async (clientId: number) => {
    try {
      const r = await authed<{ current_version: string }>(`/v1/p/central/updates/installs/${clientId}/rollback`, { method: "POST" });
      toast(`نصب به نسخه ${r.current_version ?? "قبلی"} برگردانده شد.`, "ok");
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "rollback ناموفق بود.", "err");
    }
  };

  return (
    <div>
      {current?.rollout ? <CurrentRolloutCard current={current} /> : null}
      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">
          رجیستری نسخه‌ها {initial.latest ? <span>— آخرین: <b dir="ltr">{initial.latest.version}</b> <Badge tone="green">latest</Badge></span> : <Badge tone="gray">latest ثبت نشده</Badge>}
        </div>
        {initial.versions.length === 0 ? <EmptyState title="نسخه‌ای ثبت نشده" /> : (
          <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
            {initial.versions.map((v) => (
              <Badge key={v.id} tone={v.is_latest ? "green" : "gray"}><span dir="ltr">{v.version}</span> · {jalali(v.created_at)}</Badge>
            ))}
          </div>
        )}
      </div>

      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">رول‌اوت پلکانی</div>
        <div className="grid c3" style={{ alignItems: "end" }}>
          <div className="field"><label>نسخه هدف</label>
            <select className="select" dir="ltr" value={version} onChange={(e) => setVersion(e.target.value)}>
              <option value="">— انتخاب —</option>
              {initial.versions.map((v) => <option key={v.id} value={v.version}>{v.version}</option>)}
            </select>
          </div>
          <div className="field"><label>اندازه بچ (۱–۱۰۰)</label>
            <input className="input" type="number" min={1} max={100} dir="ltr" style={{ textAlign: "left" }} value={batchSize} onChange={(e) => setBatchSize(Number(e.target.value) || 10)} />
          </div>
          <div><button className="btn btn-primary" disabled={!version} onClick={() => setRollout(true)}>شروع رول‌اوت…</button></div>
        </div>
        <p style={{ fontSize: 12.5, color: "var(--text-muted)" }}>بدون canary طبق SPEC — بچ‌ها پشت سر هم روی همه نصب‌ها (یا install_ids) اعمال می‌شوند.</p>
      </div>

      {result ? (
        <Alert tone="green">
          رول‌اوت #{faNum(result.rollout.id)} — {faNum(result.batches.length)} بچ:
          {result.batches.map((b, i) => <span key={i}> بچ {faNum(i + 1)}: {faNum(b.length)} نصب؛</span>)}
        </Alert>
      ) : null}

      <div className="card card-pad">
        <div className="card-title">نصب‌ها ({faNum(initial.installs.length)})</div>
        {initial.installs.length === 0 ? <EmptyState title="نصبی نیست" /> : (
          <div className="table-wrap">
            <table className="tbl tbl-cards">
              <thead><tr><th>مشتری</th><th>نسخه فعلی</th><th>آپدیت</th><th>وضعیت رول‌اوت</th><th>اکشن</th></tr></thead>
              <tbody>
                {initial.installs.map((r) => (
                  <tr key={r.client_id}>
                    <td data-label="مشتری"><b>{r.name}</b> <small dir="ltr" style={{ color: "var(--text-muted)" }}>{r.site_id}</small></td>
                    <td data-label="نسخه فعلی"><span dir="ltr">{r.current_version ?? "—"}</span></td>
                    <td data-label="آپدیت">{r.update_available ? <Badge tone="amber">موجود</Badge> : <Badge tone="green">به‌روز</Badge>}</td>
                    <td data-label="وضعیت رول‌اوت">
                      {r.last_rollout_status === "failed" ? (
                        <Badge tone="red">شکست‌خورده</Badge>
                      ) : r.last_rollout_status ? (
                        <Badge tone="gray"><span dir="ltr">{r.last_rollout_status}</span></Badge>
                      ) : "—"}
                    </td>
                    <td data-label="اکشن">
                      <button
                        className={r.last_rollout_status === "failed" ? "btn btn-primary btn-sm" : "btn btn-ghost btn-sm"}
                        onClick={() => void rollback(r.client_id)}
                      >{r.last_rollout_status === "failed" ? "⚠ rollback فوری به نسخه قبلی" : "rollback به نسخه قبلی"}</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {rollout ? (
        <ConfirmStepper
          open
          title={`رول‌اوت نسخه ${version}`}
          description={`نسخه «${version}» روی همه نصب‌ها در بچ‌های ${batchSize}تایی اعمال می‌شود. این عمل در لاگ اپراتور ثبت می‌گردد.`}
          confirmLabel="بستن"
          onConfirm={doRollout}
          onClose={() => setRollout(false)}
        />
      ) : null}
    </div>
  );
}

/** دسته UIUX (افزودنی): کارت «رول‌اوت جاری» با progress + توقف/ادامه. */
function CurrentRolloutCard({ current }: { current: CurrentRollout }) {
  const toast = useToast();
  const router = useRouter();
  const r = current.rollout!;
  const busy = false;

  const act = async (kind: "pause" | "resume") => {
    try {
      await authed(`/v1/p/central/updates/rollouts/${r.id}/${kind}`, { method: "POST" });
      toast(kind === "pause" ? "رول‌اوت متوقف شد." : "رول‌اوت ادامه یافت.", "ok");
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "عملیات ناموفق بود.", "err");
    }
  };

  const pct = current.progress ?? 0;
  return (
    <div className="card card-pad" style={{ marginBlockEnd: 14, borderColor: "var(--primary)" }}>
      <div className="card-title">
        رول‌اوت جاری — نسخه <b dir="ltr">{r.version}</b>{" "}
        <Badge tone={r.status === "paused" ? "amber" : "violet"}><span dir="ltr">{r.status}</span></Badge>
      </div>
      <div style={{ blockSize: 10, borderRadius: 99, background: "var(--border)", overflow: "hidden", marginBlock: 8 }} role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100} aria-label="پیشرفت رول‌اوت">
        <div style={{ inlineSize: `${pct}%`, blockSize: "100%", background: "var(--primary)" }} />
      </div>
      <p style={{ fontSize: 12.5, color: "var(--text-muted)" }}>
        {faNum(current.done ?? 0)} انجام‌شده · {faNum(current.pending ?? 0)} در انتظار · {faNum(current.failed ?? 0)} شکست‌خورده (از {faNum(current.total ?? 0)})
      </p>
      <div style={{ display: "flex", gap: 8 }}>
        {r.status === "paused" ? (
          <button className="btn btn-primary btn-sm" onClick={() => void act("resume")} disabled={busy}>ادامه رول‌اوت</button>
        ) : (
          <button className="btn btn-ghost btn-sm" onClick={() => void act("pause")} disabled={busy}>توقف موقت</button>
        )}
      </div>
    </div>
  );
}
