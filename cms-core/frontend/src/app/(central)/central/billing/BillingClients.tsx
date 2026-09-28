"use client";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Modal } from "@/components/ui/Overlays";
import { ConfirmStepper } from "@/components/ui/ConfirmStepper";
import { JalaliDatePicker } from "@/components/ui/JalaliDatePicker";
import { faNum } from "@/lib/fa";
import { TIER_FA, type CentralClient, type GatewaySettings, type Plan } from "@/lib/domain";

/* ── پلن‌ها: ساخت/ویرایش/حذف ── */

export function PlanManager({ plans, onDone }: { plans: Plan[]; onDone: () => void }) {
  const toast = useToast();
  const [editing, setEditing] = useState<Plan | "new" | null>(null);
  const [deleting, setDeleting] = useState<Plan | null>(null);

  return (
    <div>
      <div style={{ display: "flex", marginBlockEnd: 12 }}>
        <div style={{ flex: 1 }} />
        <button className="btn btn-primary" onClick={() => setEditing("new")}>＋ پلن جدید</button>
      </div>
      <div className="table-wrap">
        <table className="tbl tbl-cards">
          <thead><tr><th>نام</th><th>اسلاگ</th><th>تیر</th><th>ماهانه</th><th>سالانه</th><th>اکشن</th></tr></thead>
          <tbody>
            {plans.map((p) => (
              <tr key={p.id}>
                <td data-label="نام"><b>{p.name}</b></td>
                <td data-label="اسلاگ"><span dir="ltr">{p.slug}</span></td>
                <td data-label="تیر">{TIER_FA[p.tier] ?? p.tier}</td>
                <td data-label="ماهانه">{p.price_monthly != null ? faNum(p.price_monthly) : "—"}</td>
                <td data-label="سالانه">{p.price_yearly != null ? faNum(p.price_yearly) : "—"}</td>
                <td data-label="اکشن">
                  <div style={{ display: "flex", gap: 6 }}>
                    <button className="btn btn-ghost btn-sm" onClick={() => setEditing(p)}>ویرایش</button>
                    <button className="btn btn-ghost btn-sm" onClick={() => setDeleting(p)}>حذف</button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {editing ? <PlanForm plan={editing === "new" ? null : editing} onClose={() => { setEditing(null); onDone(); }} /> : null}
      <ConfirmStepper
        open={deleting !== null}
        title="حذف پلن"
        description={`«${deleting?.name}» حذف می‌شود. اگر اشتراک فعال داشته باشد، سرور 422 می‌دهد.`}
        requirePhrase="حذف"
        confirmLabel="بستن"
        onConfirm={async () => {
          if (!deleting) return "—";
          await authed(`/v1/p/central/plans/${deleting.id}`, { method: "DELETE" });
          toast("پلن حذف شد.", "ok");
          setDeleting(null);
          onDone();
          return "پلن حذف شد.";
        }}
        onClose={() => setDeleting(null)}
      />
    </div>
  );
}

function PlanForm({ plan, onClose }: { plan: Plan | null; onClose: () => void }) {
  const toast = useToast();
  const [name, setName] = useState(plan?.name ?? "");
  const [slug, setSlug] = useState(plan?.slug ?? "");
  const [tier, setTier] = useState<string>(plan?.tier ?? "budget");
  const [monthly, setMonthly] = useState(plan?.price_monthly != null ? String(plan.price_monthly) : "");
  const [yearly, setYearly] = useState(plan?.price_yearly != null ? String(plan.price_yearly) : "");
  const [busy, setBusy] = useState(false);

  const save = async () => {
    if (!name.trim() || !slug.trim()) { toast("نام و اسلاگ الزامی است.", "err"); return; }
    setBusy(true);
    try {
      const body = {
        name: name.trim(), slug: slug.trim(), tier,
        price_monthly: monthly === "" ? null : Number(monthly),
        price_yearly: yearly === "" ? null : Number(yearly),
      };
      if (plan) await authed(`/v1/p/central/plans/${plan.id}`, { method: "PUT", body });
      else await authed("/v1/p/central/plans", { method: "POST", body });
      toast(plan ? "پلن به‌روزرسانی شد." : "پلن ساخته شد.", "ok");
      onClose();
    } catch (e) {
      toast(e instanceof Error ? e.message : "ذخیره ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal title={plan ? "ویرایش پلن" : "پلن جدید"} onClose={onClose}>
      <div className="field"><label>نام *</label><input className="input" value={name} onChange={(e) => setName(e.target.value)} autoFocus /></div>
      <div className="field"><label>اسلاگ *</label><input className="input" dir="ltr" style={{ textAlign: "left" }} value={slug} onChange={(e) => setSlug(e.target.value)} /></div>
      <div className="field"><label>تیر</label>
        <select className="select" value={tier} onChange={(e) => setTier(e.target.value)}>
          <option value="budget">بودجه</option><option value="standard">استاندارد</option><option value="enterprise">سازمانی</option>
        </select>
      </div>
      <div style={{ display: "flex", gap: 8 }}>
        <div className="field" style={{ flex: 1 }}><label>ماهانه (تومان)</label><input className="input" type="number" dir="ltr" style={{ textAlign: "left" }} value={monthly} onChange={(e) => setMonthly(e.target.value)} /></div>
        <div className="field" style={{ flex: 1 }}><label>سالانه (تومان)</label><input className="input" type="number" dir="ltr" style={{ textAlign: "left" }} value={yearly} onChange={(e) => setYearly(e.target.value)} /></div>
      </div>
      <div style={{ display: "flex", gap: 8 }}>
        <button className="btn btn-ghost" onClick={onClose}>انصراف</button>
        <button className="btn btn-primary" onClick={() => void save()} disabled={busy}>{busy ? "…" : "ذخیره"}</button>
      </div>
    </Modal>
  );
}

/* ── فاکتور دستی + تغییر وضعیت + چاپ ── */

export function InvoiceCreate({ clients, onDone }: { clients: CentralClient[]; onDone: () => void }) {
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const [clientId, setClientId] = useState(clients[0]?.id ? String(clients[0].id) : "");
  const [amount, setAmount] = useState("");
  const [dueAt, setDueAt] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const save = async () => {
    if (!clientId || !amount) { toast("مشتری و مبلغ الزامی است.", "err"); return; }
    setBusy(true);
    try {
      await authed("/v1/p/central/invoices", {
        method: "POST",
        body: { client_id: Number(clientId), amount: Number(amount), due_at: dueAt },
      });
      toast("فاکتور ساخته شد.", "ok");
      setOpen(false);
      onDone();
    } catch (e) {
      toast(e instanceof Error ? e.message : "ساخت فاکتور ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <button className="btn btn-primary" onClick={() => setOpen(true)}>＋ فاکتور دستی</button>
      {open ? (
        <Modal title="فاکتور دستی" onClose={() => setOpen(false)}>
          <div className="field"><label>مشتری *</label>
            <select className="select" value={clientId} onChange={(e) => setClientId(e.target.value)}>
              {clients.map((c) => <option key={c.id} value={c.id}>{c.name} (#{c.id})</option>)}
            </select>
          </div>
          <div className="field"><label>مبلغ (تومان) *</label><input className="input" type="number" dir="ltr" style={{ textAlign: "left" }} value={amount} onChange={(e) => setAmount(e.target.value)} /></div>
          <JalaliDatePicker value={dueAt} onChange={setDueAt} label="سررسید" />
          <div style={{ display: "flex", gap: 8, marginBlockStart: 14 }}>
            <button className="btn btn-ghost" onClick={() => setOpen(false)}>انصراف</button>
            <button className="btn btn-primary" onClick={() => void save()} disabled={busy}>{busy ? "…" : "ساخت فاکتور"}</button>
          </div>
        </Modal>
      ) : null}
    </>
  );
}

/* ── یادآوری فاکتور پرداخت‌نشده (دسته ۱ — دکمه per-row دمو) ── */

export function InvoiceRemind({ id, onDone }: { id: number; onDone: () => void }) {
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  const send = async () => {
    setBusy(true);
    try {
      await authed(`/v1/p/central/invoices/${id}/remind`, { method: "POST" });
      toast("یادآوری فاکتور ثبت شد.", "ok");
      onDone();
    } catch (e) {
      toast(e instanceof Error ? e.message : "خطا رخ داد.", "err");
    } finally {
      setBusy(false);
    }
  };
  return (
    <button className="btn btn-soft btn-sm" disabled={busy} onClick={() => void send()}>
      {busy ? "…" : "یادآوری"}
    </button>
  );
}

export function InvoiceStatus({ id, status, onDone }: { id: number; status: string; onDone: () => void }) {
  const toast = useToast();
  const change = async (s: string) => {
    try {
      await authed(`/v1/p/central/invoices/${id}/status`, { method: "PUT", body: { status: s } });
      toast("وضعیت فاکتور به‌روزرسانی شد.", "ok");
      onDone();
    } catch (e) {
      toast(e instanceof Error ? e.message : "خطا رخ داد.", "err");
    }
  };
  return (
    <select className="select" value={status} onChange={(e) => void change(e.target.value)} aria-label={`وضعیت فاکتور ${id}`} style={{ minInlineSize: 130 }}>
      <option value="pending">در انتظار</option>
      <option value="paid">پرداخت‌شده</option>
      <option value="failed">ناموفق</option>
      <option value="cancelled">لغوشده</option>
    </select>
  );
}

/* ── کنترل تعلیق: suspend/resume/delete اشتراک ── */

export function SuspensionActions({ subId, onDone }: { subId: number; onDone: () => void }) {
  const toast = useToast();
  const [confirm, setConfirm] = useState<"resume" | "delete" | null>(null);
  const act = async (kind: "suspend" | "resume" | "delete") => {
    try {
      await authed(`/v1/p/central/subscriptions/${subId}/${kind}`, { method: "POST" });
      toast(kind === "resume" ? "اشتراک بازگشایی شد." : kind === "delete" ? "اشتراک حذف شد." : "اشتراک معلق شد.", "ok");
      onDone();
      return "انجام شد.";
    } catch (e) {
      toast(e instanceof Error ? e.message : "خطا رخ داد.", "err");
      throw e;
    }
  };
  return (
    <div style={{ display: "flex", gap: 6 }}>
      <button className="btn btn-primary btn-sm" onClick={() => setConfirm("resume")}>بازگشایی</button>
      <button className="btn btn-ghost btn-sm" onClick={() => setConfirm("delete")}>حذف</button>
      {confirm ? (
        <ConfirmStepper
          open
          title={confirm === "resume" ? "بازگشایی اشتراک" : "حذف اشتراک"}
          description={confirm === "resume" ? "اشتراک و مشتری فعال می‌شوند." : "اشتراک حذف منطقی می‌شود. این عمل مخرب است."}
          requirePhrase={confirm === "delete" ? "حذف" : undefined}
          confirmLabel="بستن"
          onConfirm={() => act(confirm)}
          onClose={() => setConfirm(null)}
        />
      ) : null}
    </div>
  );
}

/* ── تنظیمات درگاه (کلید ماسک‌شده) ── */

export function GatewayForm({ initial }: { initial: GatewaySettings }) {
  const toast = useToast();
  const router = useRouter();
  const [driver, setDriver] = useState(initial.driver);
  const [merchant, setMerchant] = useState("");
  const [sandbox, setSandbox] = useState(initial.sandbox);
  const [busy, setBusy] = useState(false);
  const [testing, setTesting] = useState(false);

  const save = async () => {
    setBusy(true);
    try {
      await authed("/v1/p/central/gateway-settings", {
        method: "PUT",
        body: { driver, ...(merchant ? { merchant_id: merchant } : {}), sandbox },
      });
      toast("تنظیمات درگاه ذخیره شد.", "ok");
      setMerchant("");
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "ذخیره ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="card card-pad" style={{ maxInlineSize: 560 }}>
      <div className="field"><label>درگاه فعال</label>
        <select className="select" value={driver} onChange={(e) => setDriver(e.target.value)}>
          <option value="zarinpal-stub">زرین‌پال Stub (تست)</option>
          <option value="zarinpal">زرین‌پال</option>
          <option value="idpay">آیدی‌پی</option>
        </select>
      </div>
      <div className="field"><label>مرچنت (فعلی: <span dir="ltr">{initial.merchant_id_masked ?? "—"}</span>)</label>
        <input className="input" type="password" dir="ltr" style={{ textAlign: "left" }} value={merchant} onChange={(e) => setMerchant(e.target.value)} placeholder="خالی = بدون تغییر" autoComplete="off" />
        <span className="hint">کلید کامل هرگز برنمی‌گردد و لاگ نمی‌شود.</span>
      </div>
      <div className="field"><label>حالت تست (sandbox)</label>
        <button type="button" role="switch" aria-checked={sandbox} className="switch" aria-label="sandbox" onClick={() => setSandbox((v) => !v)} />
      </div>
      <button className="btn btn-primary" onClick={() => void save()} disabled={busy}>{busy ? "…" : "ذخیره تنظیمات"}</button>
      <div style={{ display: "flex", gap: 8, marginBlockStart: 10 }}>
        <button
          className="btn btn-ghost"
          disabled={testing}
          onClick={() => {
            setTesting(true);
            authed<{ ok: boolean }>("/v1/p/central/gateway-settings/test", { method: "POST" })
              .then((r) => toast(r.ok ? "اتصال موفق ✓ (تست نمایشی — بدون تراکنش)" : "اتصال ناموفق بود.", r.ok ? "ok" : "err"))
              .catch((e: unknown) => toast(e instanceof Error ? e.message : "تست ناموفق بود.", "err"))
              .finally(() => setTesting(false));
          }}
        >{testing ? "…" : "تست اتصال"}</button>
      </div>
    </div>
  );
}
