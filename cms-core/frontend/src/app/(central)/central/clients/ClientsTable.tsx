"use client";
import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Badge, EmptyState } from "@/components/ui/primitives";
import { Drawer } from "@/components/ui/Overlays";
import { Modal } from "@/components/ui/Overlays";
import { CopyToClipboard } from "@/components/ui/CopyToClipboard";
import { ConfirmStepper } from "@/components/ui/ConfirmStepper";
import { faNum, jalali, relativeFa } from "@/lib/fa";
import {
  HEALTH_FA, INVOICE_STATUS_FA, TIER_FA,
  type Backup, type CentralClient, type Invoice, type Paginator,
} from "@/lib/domain";

const NEXT_TIER: Record<string, string> = { budget: "standard", standard: "enterprise" };
const healthTone = (h?: string) => h === "healthy" ? "green" : h === "warning" ? "amber" : h === "critical" ? "red" : "gray";

/** جدول مشتریان + Drawer تب‌دار + ساخت مشتری (داده اولیه از سرور). */
export function ClientsTable({ initial, autoCreate }: { initial: Paginator<CentralClient>; autoCreate?: boolean }) {
  const toast = useToast();
  const router = useRouter();
  const [drawerId, setDrawerId] = useState<number | null>(null);
  const [creating, setCreating] = useState(autoCreate === true);

  return (
    <div>
      <div style={{ display: "flex", marginBlockEnd: 12 }}>
        <span style={{ fontSize: 12.5, color: "var(--text-muted)" }}>{faNum(initial.total)} مشتری</span>
        <div style={{ flex: 1 }} />
        <button className="btn btn-primary" onClick={() => setCreating(true)}>＋ مشتری جدید</button>
      </div>

      {initial.data.length === 0 ? (
        <div className="card card-pad"><EmptyState title="مشتری‌ای نیست" /></div>
      ) : (
        <div className="table-wrap">
          <table className="tbl tbl-cards">
            <thead><tr><th>نام</th><th>دامنه</th><th>تیر</th><th>اشتراک</th><th>سلامت</th><th>اکشن</th></tr></thead>
            <tbody>
              {initial.data.map((c) => (
                <tr key={c.id}>
                  <td data-label="نام"><b>{c.name}</b><br /><small style={{ color: "var(--text-muted)" }} dir="ltr">{c.site_id}</small></td>
                  <td data-label="دامنه">{c.domain ? <span dir="ltr">{c.domain}</span> : "—"}</td>
                  <td data-label="تیر"><Badge tone="violet">{TIER_FA[c.tier] ?? c.tier}</Badge></td>
                  <td data-label="اشتراک">
                    <Badge tone={c.subscription?.status === "active" ? "green" : c.subscription?.status === "suspended" ? "red" : "gray"}>
                      {c.subscription?.status ?? c.status}
                    </Badge>
                  </td>
                  <td data-label="سلامت"><Badge tone={healthTone(c.health_status)}>{HEALTH_FA[c.health_status ?? "unknown"]}</Badge></td>
                  <td data-label="اکشن"><button className="btn btn-ghost btn-sm" onClick={() => setDrawerId(c.id)}>جزئیات</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {drawerId !== null ? <ClientDrawer id={drawerId} onClose={() => { setDrawerId(null); router.refresh(); }} /> : null}
      {creating ? <ClientCreate onClose={() => { setCreating(false); router.refresh(); }} /> : null}
    </div>
  );
}

function ClientCreate({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const [name, setName] = useState("");
  const [domain, setDomain] = useState("");
  const [tier, setTier] = useState("budget");
  const [busy, setBusy] = useState(false);

  const save = async () => {
    if (!name.trim()) { toast("نام مشتری الزامی است.", "err"); return; }
    setBusy(true);
    try {
      await authed("/central/clients", { method: "POST", body: { name: name.trim(), domain: domain.trim() || undefined, tier } });
      toast("مشتری ساخته شد (اشتراک ۳۰ روزه).", "ok");
      onClose();
    } catch (e) {
      toast(e instanceof Error ? e.message : "ساخت مشتری ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal title="مشتری جدید" onClose={onClose}>
      <div className="field"><label>نام *</label><input className="input" value={name} onChange={(e) => setName(e.target.value)} autoFocus /></div>
      <div className="field"><label>دامنه</label><input className="input" dir="ltr" style={{ textAlign: "left" }} value={domain} onChange={(e) => setDomain(e.target.value)} placeholder="example.ir" /></div>
      <div className="field"><label>تیر</label>
        <select className="select" value={tier} onChange={(e) => setTier(e.target.value)}>
          <option value="budget">بودجه</option><option value="standard">استاندارد</option><option value="enterprise">سازمانی</option>
        </select>
      </div>
      <p style={{ fontSize: 12.5, color: "var(--text-muted)" }}>با ساخت، اشتراک ۳۰ روزه فعال می‌شود. Provisioning زیرساخت در مراحل بعد است.</p>
      <div style={{ display: "flex", gap: 8 }}>
        <button className="btn btn-ghost" onClick={onClose}>انصراف</button>
        <button className="btn btn-primary" onClick={() => void save()} disabled={busy}>{busy ? "…" : "ساخت مشتری"}</button>
      </div>
    </Modal>
  );
}

/** Drawer تب‌دار مشتری: اطلاعات/سرویس‌ها/بکاپ‌ها/فاکتورها + اکشن‌های اپراتور. */
function ClientDrawer({ id, onClose }: { id: number; onClose: () => void }) {
  const toast = useToast();
  const router = useRouter();
  const [tab, setTab] = useState("info");
  const [client, setClient] = useState<CentralClient | null>(null);
  const [backups, setBackups] = useState<Backup[] | null>(null);
  const [invoices, setInvoices] = useState<Invoice[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [confirm, setConfirm] = useState<null | { kind: "upgrade" | "suspend" | "resume" | "delete" }>(null);
  const [token, setToken] = useState<{ token: string; expires_at: string } | null>(null);
  const [busyToken, setBusyToken] = useState(false);

  const load = async () => {
    try {
      setError(null);
      const c = await authed<CentralClient>(`/central/clients/${id}`);
      setClient(c);
      if (tab === "backups") {
        const b = await authed<Backup[] | { data: Backup[] }>(`/central/clients/${id}/backups`);
        setBackups(Array.isArray(b) ? b : (b.data ?? []));
      }
      if (tab === "invoices") {
        const v = await authed<{ data: { data: Invoice[] } } | { data: Invoice[] }>(`/central/invoices?client_id=${id}&per_page=20`);
        const d = (v as { data: unknown }).data;
        setInvoices(Array.isArray(d) ? d : ((d as { data?: Invoice[] })?.data ?? []));
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : "خطا در بارگذاری.");
    }
  };

  const reloadOnTab = (t: string) => { setTab(t); setBackups(null); setInvoices(null); };

  // بارگذاری در effect، نه حین render: setState در چرخه رندر باعث React #301
  // (too many re-renders) می‌شد. کلید `id:tab` یعنی با تعویض تب دوباره load.
  useEffect(() => {
    void load();
  }, [id, tab]);

  const issueToken = async () => {
    setBusyToken(true);
    try {
      const r = await authed<{ token: string; expires_at: string }>(`/central/clients/${id}/support-token`, { method: "POST" });
      setToken(r);
    } catch (e) {
      toast(e instanceof Error ? e.message : "صدور توکن ناموفق بود.", "err");
    } finally {
      setBusyToken(false);
    }
  };

  const doBackup = async () => {
    try {
      await authed(`/central/clients/${id}/backup`, { method: "POST" });
      toast("بکاپ ثبت شد (Stub).", "ok");
      await load();
    } catch (e) {
      toast(e instanceof Error ? e.message : "خطا رخ داد.", "err");
    }
  };

  const doRestore = async (backupId: number) => {
    try {
      await authed(`/central/clients/${id}/restore`, { method: "POST", body: { backup_id: backupId } });
      toast("ریستور آغاز شد (Stub).", "ok");
      await load();
    } catch (e) {
      toast(e instanceof Error ? e.message : "خطا رخ داد.", "err");
    }
  };

  const confirmMeta = confirm ? {
    upgrade: {
      title: "ارتقا تیر",
      desc: client ? `«${client.name}» از ${TIER_FA[client.tier]} به ${TIER_FA[NEXT_TIER[client.tier] ?? ""] ?? "—"} ارتقا می‌یابد (فقط یک پله رو به جلو).` : "",
      label: "تأیید ارتقا",
      run: async () => {
        if (!client || !NEXT_TIER[client.tier]) return "ارتقای دیگری ممکن نیست.";
        await authed(`/central/clients/${client.id}/upgrade-tier`, { method: "POST", body: { tier: NEXT_TIER[client.tier] } });
        await load(); router.refresh();
        return "تیر مشتری ارتقا یافت.";
      },
    },
    suspend: {
      title: "تعلیق مشتری", desc: `«${client?.name}» معلق می‌شود (سایت + اشتراک).`, label: "بستن",
      run: async () => {
        if (!client) return "—";
        await authed(`/central/clients/${client.id}/suspend`, { method: "POST" });
        await load(); router.refresh();
        return "مشتری معلق شد.";
      },
    },
    resume: {
      title: "بازگشایی مشتری", desc: `«${client?.name}» بازگشایی می‌شود.`, label: "بستن",
      run: async () => {
        if (!client) return "—";
        await authed(`/central/clients/${client.id}/resume`, { method: "POST" });
        await load(); router.refresh();
        return "مشتری بازگشایی شد.";
      },
    },
    delete: {
      title: "حذف مشتری", desc: `«${client?.name}» حذف منطقی می‌شود (همراه اشتراک). این عمل مخرب است.`, label: "بستن",
      run: async () => {
        if (!client) return "—";
        await authed(`/central/clients/${client.id}/delete`, { method: "POST" });
        router.refresh();
        onClose();
        return "مشتری حذف شد.";
      },
    },
  }[confirm.kind] : null;

  return (
    <Drawer title={client ? `مشتری: ${client.name}` : "جزئیات مشتری"} onClose={onClose} wide>
      {error ? <div className="alert a-red">{error}</div> : null}
      {!client && !error ? <p style={{ color: "var(--text-muted)" }}>در حال بارگذاری…</p> : null}
      {client ? (
        <div>
          {/* تب‌های محلی Drawer (state — بدون ناوبری) */}
          <div style={{ display: "flex", gap: 4, marginBlock: "-12px 12px" }}>
            {(["info", "services", "backups", "invoices"] as const).map((t) => (
              <button key={t} className={`btn btn-sm ${tab === t ? "btn-primary" : "btn-ghost"}`} onClick={() => reloadOnTab(t)}>
                {t === "info" ? "اطلاعات" : t === "services" ? "سرویس‌ها" : t === "backups" ? "بکاپ‌ها" : "فاکتورها"}
              </button>
            ))}
          </div>

          {tab === "info" ? (
            <div>
              <div className="kv"><span>نام</span><span>{client.name}</span></div>
              <div className="kv"><span>دامنه</span><span dir="ltr">{client.domain ?? "—"}</span></div>
              <div className="kv"><span>تیر</span><span>{TIER_FA[client.tier]}</span></div>
              <div className="kv"><span>وضعیت</span><span>{client.status}</span></div>
              <div className="kv"><span>site_id</span><span dir="ltr">{client.site_id}</span></div>
              <div className="kv"><span>نسخه نصب</span><span dir="ltr">{client.current_version ?? "—"}</span></div>
              <div className="kv"><span>سلامت</span><span>{HEALTH_FA[client.health_status ?? "unknown"]}</span></div>

              <div className="card card-pad" style={{ marginBlockStart: 12 }}>
                <div className="card-title" style={{ marginBlockEnd: 8 }}>توکن پشتیبانی (یکبارمصرف)</div>
                {token ? (
                  <div className="alert a-amber">
                    <div style={{ inlineSize: "100%" }}>
                      <div dir="ltr" style={{ fontFamily: "monospace", wordBreak: "break-all", marginBlockEnd: 6 }}>{token.token}</div>
                      <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
                        <CopyToClipboard text={token.token} label="کپی توکن" />
                        <small>انقضا: {jalali(token.expires_at)} — فقط همین‌بار نمایش داده می‌شود.</small>
                      </div>
                    </div>
                  </div>
                ) : (
                  <button className="btn btn-ghost btn-sm" disabled={busyToken} onClick={() => void issueToken()}>
                    {busyToken ? "…" : "صدور توکن (TTL ‏۱۵ دقیقه)"}
                  </button>
                )}
              </div>

              <div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginBlockStart: 12 }}>
                {NEXT_TIER[client.tier] ? <button className="btn btn-primary btn-sm" onClick={() => setConfirm({ kind: "upgrade" })}>ارتقا به {TIER_FA[NEXT_TIER[client.tier]]}</button> : null}
                {client.status === "suspended"
                  ? <button className="btn btn-ghost btn-sm" onClick={() => setConfirm({ kind: "resume" })}>رفع تعلیق</button>
                  : <button className="btn btn-ghost btn-sm" onClick={() => setConfirm({ kind: "suspend" })}>تعلیق</button>}
                <button className="btn btn-ghost btn-sm" onClick={() => setConfirm({ kind: "delete" })}>حذف…</button>
              </div>
            </div>
          ) : null}

          {tab === "services" ? (
            <div>
              <div className="kv"><span>وضعیت اشتراک</span><span>{client.subscription?.status ?? "—"}</span></div>
              <div className="kv"><span>پلن</span><span>{client.subscription?.plan?.name ?? "—"}</span></div>
              <div className="kv"><span>سررسید</span><span>{jalali(client.subscription?.due_at)} ({relativeFa(client.subscription?.due_at)})</span></div>
              <p style={{ fontSize: 12.5, color: "var(--text-muted)" }}>مدیریت سرویس‌های زیرساخت (start/stop/…) در صفحه چابکان (تسک ۳.۳) می‌آید.</p>
            </div>
          ) : null}

          {tab === "backups" ? (
            <div>
              <button className="btn btn-primary btn-sm" onClick={() => void doBackup()} style={{ marginBlockEnd: 10 }}>＋ بکاپ دستی (Stub)</button>
              {!backups ? <p style={{ color: "var(--text-muted)", fontSize: 13 }}>در حال بارگذاری…</p> : null}
              {backups?.length === 0 ? <p style={{ color: "var(--text-muted)", fontSize: 13 }}>بکاپی ثبت نشده.</p> : null}
              {backups?.map((b) => (
                <div className="ver-row" key={b.id}>
                  <span>#{faNum(b.id)} — {b.type} — {b.status}</span>
                  <small style={{ color: "var(--text-muted)" }}>{jalali(b.created_at)}</small>
                  <span style={{ flex: 1 }} />
                  <button className="btn btn-ghost btn-sm" onClick={() => void doRestore(b.id)}>ریستور (Stub)</button>
                </div>
              ))}
            </div>
          ) : null}

          {tab === "invoices" ? (
            <div>
              {!invoices ? <p style={{ color: "var(--text-muted)", fontSize: 13 }}>در حال بارگذاری…</p> : null}
              {invoices?.length === 0 ? <p style={{ color: "var(--text-muted)", fontSize: 13 }}>فاکتوری نیست.</p> : null}
              {invoices?.map((i) => (
                <div className="ver-row" key={i.id}>
                  <span>#{faNum(i.id)} — {faNum(i.amount)} تومان</span>
                  <Badge tone={i.status === "paid" ? "green" : i.status === "pending" ? "amber" : "gray"}>{INVOICE_STATUS_FA[i.status]}</Badge>
                  <span style={{ flex: 1 }} />
                  <small style={{ color: "var(--text-muted)" }}>{jalali(i.due_at)}</small>
                </div>
              ))}
            </div>
          ) : null}
        </div>
      ) : null}

      {confirmMeta ? (
        <ConfirmStepper
          open
          title={confirmMeta.title}
          description={confirmMeta.desc}
          requirePhrase={confirm?.kind === "delete" ? "حذف" : undefined}
          confirmLabel={confirmMeta.label}
          onConfirm={confirmMeta.run}
          onClose={() => setConfirm(null)}
        />
      ) : null}
    </Drawer>
  );
}
