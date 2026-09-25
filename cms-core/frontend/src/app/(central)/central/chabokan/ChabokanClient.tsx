"use client";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Alert, Badge, EmptyState, StatCard } from "@/components/ui/primitives";
import { ConfirmStepper } from "@/components/ui/ConfirmStepper";
import { faNum, jalali } from "@/lib/fa";
import type { Deployment, Paginator, Reconciliation, ServiceItem, Wallet } from "@/lib/domain";

type Action = { kind: "start" | "stop" | "restart" | "resize" | "redeploy"; service: string };

const ACTION_FA: Record<Action["kind"], string> = {
  start: "روشن‌کردن",
  stop: "خاموش‌کردن",
  restart: "ری‌استارت",
  resize: "تغییر سایز",
  redeploy: "دیپلوی مجدد",
};

/** کنسول چابکان: کیف پول + اکشن‌های سرویس (ConfirmStepper + confirm:true) + دیپلوی‌ها + reconciliation. */
export function ChabokanClient({
  initialWallet, initialServices, initialDeployments, initialRecon,
}: {
  initialWallet: Wallet;
  initialServices: ServiceItem[];
  initialDeployments: Paginator<Deployment>;
  initialRecon: Reconciliation;
}) {
  const toast = useToast();
  const router = useRouter();
  const [action, setAction] = useState<Action | null>(null);
  const [size, setSize] = useState("medium");

  const runAction = async (): Promise<string> => {
    if (!action) return "—";
    const { kind, service } = action;
    if (kind === "redeploy") {
      await authed(`/central/chabokan/redeploy`, { method: "POST", body: { service, confirm: true } });
      router.refresh();
      return "دیپلوی مجدد ثبت شد.";
    }
    const body: Record<string, unknown> =
      kind === "resize" ? { size, confirm: true }
      : kind === "start" ? {}
      : { confirm: true };
    await authed(`/central/chabokan/services/${encodeURIComponent(service)}/${kind}`, { method: "POST", body });
    router.refresh();
    return { start: "سرویس روشن شد.", stop: "سرویس خاموش شد.", restart: "سرویس ری‌استارت شد.", resize: "سایز سرویس تغییر کرد.", redeploy: "" }[kind];
  };

  return (
    <div>
      <div className="grid c4" style={{ marginBlockEnd: 16 }}>
        <StatCard icon="⬣" value={faNum(initialWallet.balance)} label={`موجودی کیف پول${initialWallet.currency ? ` (${initialWallet.currency})` : ""}`} />
        <StatCard icon="⬢" value={faNum(initialServices.length)} label="سرویس‌ها (Stub)" />
        <StatCard icon="⟳" value={faNum(initialDeployments.total)} label="دیپلوی‌های ثبت‌شده" />
        <StatCard
          icon="⚖"
          value={faNum(initialRecon.missing_in_db.length + initialRecon.missing_in_chabokan.length)}
          label="ناسازگاری reconciliation"
        />
      </div>

      {initialWallet.low ? <Alert tone="amber">موجودی کیف پول پایین است (آستانه: {faNum(initialWallet.threshold ?? "—")}).</Alert> : null}

      <ChargeWalletCard />

      <IdlePoolCard services={initialServices} />

      <div className="grid c2" style={{ alignItems: "start" }}>
        <div className="card card-pad">
          <div className="card-title">سرویس‌ها</div>
          {initialServices.length === 0 ? <EmptyState title="سرویسی نیست" /> : (
            <div className="table-wrap">
              <table className="tbl">
                <thead><tr><th>نام</th><th>وضعیت</th><th>سایز</th><th>اکشن</th></tr></thead>
                <tbody>
                  {initialServices.map((s) => (
                    <tr key={s.name}>
                      <td><b dir="ltr">{s.name}</b></td>
                      <td><Badge tone={s.status === "running" ? "green" : s.status === "stopped" ? "red" : "gray"}><span dir="ltr">{String(s.status)}</span></Badge></td>
                      <td><span dir="ltr">{String(s.size ?? "—")}</span></td>
                      <td>
                        <div style={{ display: "flex", gap: 4, flexWrap: "wrap" }}>
                          <button className="btn btn-ghost btn-sm" onClick={() => setAction({ kind: "start", service: s.name })}>روشن</button>
                          <button className="btn btn-ghost btn-sm" onClick={() => setAction({ kind: "restart", service: s.name })}>ری‌استارت</button>
                          <button className="btn btn-ghost btn-sm" onClick={() => setAction({ kind: "stop", service: s.name })}>خاموش</button>
                          <button className="btn btn-ghost btn-sm" onClick={() => setAction({ kind: "resize", service: s.name })}>سایز…</button>
                          <button className="btn btn-ghost btn-sm" onClick={() => setAction({ kind: "redeploy", service: s.name })}>دیپلوی مجدد</button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>

        <div>
          <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
            <div className="card-title">آخرین دیپلوی‌ها</div>
            {initialDeployments.data.length === 0 ? <EmptyState title="دیپلویی ثبت نشده" /> : (
              initialDeployments.data.map((d) => (
                <div className="ver-row" key={d.id}>
                  <span dir="ltr">{d.service}</span>
                  <Badge tone={d.status === "failed" ? "red" : d.status === "done" ? "green" : "amber"}><span dir="ltr">{d.status}</span></Badge>
                  <span style={{ flex: 1 }} />
                  <small style={{ color: "var(--text-muted)" }}>{jalali(d.created_at)}</small>
                </div>
              ))
            )}
          </div>

          <div className="card card-pad">
            <div className="card-title">reconciliation <Badge tone="gray">{initialRecon.driver}</Badge></div>
            {initialRecon.missing_in_db.length === 0 && initialRecon.missing_in_chabokan.length === 0 ? (
              <Alert tone="green">ناسازگاری‌ای نیست — DB و چابکان هم‌خوان‌اند.</Alert>
            ) : (
              <div>
                {initialRecon.missing_in_db.map((s) => (
                  <div className="ver-row" key={`db-${s}`}><span dir="ltr">{s}</span><Badge tone="amber">ثبت DB ندارد</Badge></div>
                ))}
                {initialRecon.missing_in_chabokan.map((s) => (
                  <div className="ver-row" key={`ch-${s}`}><span dir="ltr">{s}</span><Badge tone="red">در چابکان نیست</Badge></div>
                ))}
              </div>
            )}
            <p style={{ fontSize: 12, color: "var(--text-muted)", marginBlockEnd: 0 }}>آخرین بررسی: {jalali(initialRecon.checked_at)}</p>
          </div>
        </div>
      </div>

      {action ? (
        <ConfirmStepper
          open
          title={`${ACTION_FA[action.kind]}: ${action.service}`}
          description={
            action.kind === "start"
              ? `سرویس «${action.service}» روشن می‌شود. این عمل در لاگ حسابرسی ثبت می‌گردد.`
              : `عمل «${ACTION_FA[action.kind]}» روی «${action.service}» مخرب/حساس است و با confirm:true به API می‌رود + در لاگ حسابرسی ثبت می‌شود.`
          }
          requirePhrase={action.kind === "stop" ? "توقف" : undefined}
          confirmLabel="بستن"
          onConfirm={runAction}
          onClose={() => { setAction(null); toast("پنجره تأیید بسته شد."); }}
        />
      ) : null}

      {action?.kind === "resize" ? (
        <div className="card card-pad" style={{ marginBlockStart: 12, borderColor: "var(--warning)" }}>
          <div className="field"><label>سایز مقصد</label>
            <div className="seg" style={{ maxInlineSize: 320 }}>
              {(["small", "medium", "large"] as const).map((s) => (
                <button key={s} className={size === s ? "on" : ""} onClick={() => setSize(s)}><span dir="ltr">{s}</span></button>
              ))}
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}

/** دسته UIUX (افزودنی): دکمه «شارژ کیف پول» (ثبت درخواست). */
function ChargeWalletCard() {
  const toast = useToast();
  const router = useRouter();
  const [amount, setAmount] = useState("500000");
  const [confirm, setConfirm] = useState(false);
  const [busy, setBusy] = useState(false);

  const charge = async (): Promise<string> => {
    const n = Number(amount);
    if (!Number.isInteger(n) || n < 10000) throw new Error("مبلغ شارژ باید عدد صحیح حداقل ۱۰٬۰۰۰ باشد.");
    setBusy(true);
    try {
      await authed(`/central/chabokan/wallet/charge`, { method: "POST", body: { amount: n } });
      router.refresh();
      return `درخواست شارژ ${faNum(n)} ثبت شد.`;
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
      <div className="card-title">شارژ کیف پول</div>
      <div style={{ display: "flex", gap: 8, alignItems: "end", flexWrap: "wrap" }}>
        <div className="field" style={{ margin: 0 }}><label>مبلغ (حداقل ۱۰٬۰۰۰)</label>
          <input className="input" dir="ltr" style={{ textAlign: "left", maxInlineSize: 200 }} inputMode="numeric" value={amount} onChange={(e) => setAmount(e.target.value)} />
        </div>
        <button className="btn btn-primary btn-sm" onClick={() => setConfirm(true)} disabled={busy}>شارژ کیف پول…</button>
      </div>
      {confirm ? (
        <ConfirmStepper
          open
          title="شارژ کیف پول"
          description={`درخواست شارژ ${faNum(Number(amount) || 0)} ثبت و در لاگ حسابرسی درج می‌شود (در MVP شارژ واقعی انجام نمی‌شود).`}
          confirmLabel="بستن"
          onConfirm={charge}
          onClose={() => { setConfirm(false); toast("پنجره تأیید بسته شد."); }}
        />
      ) : null}
    </div>
  );
}

/** دسته UIUX (افزودنی): کارت «استخر Idle + ساخت سرویس آماده». */
function IdlePoolCard({ services }: { services: ServiceItem[] }) {
  const toast = useToast();
  const router = useRouter();
  const [name, setName] = useState("");
  const [size, setSize] = useState("small");
  const [busy, setBusy] = useState(false);
  const idle = services.filter((s) => s.status === "stopped");

  const provision = async () => {
    if (!/^[a-z0-9-]{3,40}$/.test(name.trim())) { toast("نام سرویس فقط حروف کوچک انگلیسی، عدد و خط تیره (۳ تا ۴۰ نویسه).", "err"); return; }
    setBusy(true);
    try {
      await authed(`/central/chabokan/services/provision`, { method: "POST", body: { name: name.trim(), size } });
      toast("ساخت سرویس آماده ثبت شد.", "ok");
      setName("");
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "ثبت ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
      <div className="card-title">استخر Idle + ساخت سرویس آماده <Badge tone="gray">{faNum(idle.length)} idle</Badge></div>
      {idle.length > 0 ? (
        <p style={{ fontSize: 12.5, color: "var(--text-muted)" }}>سرویس‌های خاموش آماده استفاده مجدد: {idle.map((s) => s.name).join("، ")}</p>
      ) : (
        <p style={{ fontSize: 12.5, color: "var(--text-muted)" }}>سرویس خاموشی نیست — می‌توانید سرویس آماده جدید بسازید.</p>
      )}
      <div style={{ display: "flex", gap: 8, alignItems: "end", flexWrap: "wrap" }}>
        <div className="field" style={{ margin: 0 }}><label>نام سرویس جدید *</label>
          <input className="input" dir="ltr" style={{ textAlign: "left", maxInlineSize: 220 }} value={name} onChange={(e) => setName(e.target.value)} placeholder="ready-pool-1" />
        </div>
        <div className="field" style={{ margin: 0 }}><label>سایز</label>
          <select className="select" dir="ltr" value={size} onChange={(e) => setSize(e.target.value)}>
            <option value="small">small</option>
            <option value="medium">medium</option>
            <option value="large">large</option>
          </select>
        </div>
        <button className="btn btn-ghost btn-sm" onClick={() => void provision()} disabled={busy}>{busy ? "…" : "ساخت سرویس آماده"}</button>
      </div>
    </div>
  );
}
