"use client";
import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Alert } from "@/components/ui/primitives";
import { Stepper } from "@/components/ui/primitives";
import { CopyToClipboard } from "@/components/ui/CopyToClipboard";
import { faNum, jalali } from "@/lib/fa";
import { INVOICE_STATUS_FA, type Invoice, type PaySession } from "@/lib/domain";
import { InvoicePrint } from "./InvoicePrint";

type Phase = "idle" | "starting" | "redirect" | "verifying" | "done";

/**
 * Stepper پرداخت فاکتور (۱.۱۵): فاکتور → شروع pay → هدایت به درگاه Stub →
 * بازگشت و polling وضعیت. در حالت Stub، تکمیل واقعی با وب‌هوک/اپراتور است.
 */
export function PayFlow({ invoices }: { invoices: Invoice[] }) {
  const toast = useToast();
  const router = useRouter();
  const [inv, setInv] = useState<Invoice | null>(null);
  const [phase, setPhase] = useState<Phase>("idle");
  const [session, setSession] = useState<PaySession | null>(null);
  const [note, setNote] = useState<string | null>(null);
  const [receipt, setReceipt] = useState(false);
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);

  const pending = invoices.filter((i) => i.status === "pending");

  useEffect(() => () => { if (timer.current) clearInterval(timer.current); }, []);

  const checkStatus = async (silent = false) => {
    if (!inv) return;
    try {
      const list = await authed<Invoice[] | { data: Invoice[] }>("/v1/admin/subscription/invoices?per_page=50");
      const arr = Array.isArray(list) ? list : (list.data ?? []);
      const cur = arr.find((x) => x.id === inv.id);
      if (cur?.status === "paid") {
        if (timer.current) clearInterval(timer.current);
        setPhase("done");
        setNote("پرداخت تأیید شد — فاکتور paid شد و تعلیق (در صورت وجود) رفع می‌شود.");
        toast("پرداخت موفق بود.", "ok");
        router.refresh();
      } else if (!silent) {
        toast("هنوز پرداخت ثبت نشده (در Stub، تکمیل با وب‌هوک درگاه یا ثبت اپراتور است).", "err");
      }
    } catch (e) {
      if (!silent) toast(e instanceof Error ? e.message : "خطا در بررسی.", "err");
    }
  };

  const start = async (invoice: Invoice) => {
    setInv(invoice);
    setPhase("starting");
    setNote(null);
    try {
      const s = await authed<PaySession>(`/v1/admin/subscription/invoices/${invoice.id}/pay`, { method: "POST" });
      setSession(s);
      setPhase("redirect");
    } catch (e) {
      setPhase("idle");
      toast(e instanceof Error ? e.message : "شروع پرداخت ناموفق بود.", "err");
    }
  };

  const beginVerify = () => {
    setPhase("verifying");
    if (timer.current) clearInterval(timer.current);
    let n = 0;
    timer.current = setInterval(() => {
      n++;
      void checkStatus(true);
      if (n >= 12 && timer.current) {
        clearInterval(timer.current);
        setNote("پس از ۶۰ ثانیه هنوز paid ثبت نشده — در محیط Stub، اپراتور مرکزی وضعیت را ثبت می‌کند.");
      }
    }, 5000);
  };

  const cancel = () => {
    if (timer.current) clearInterval(timer.current);
    setInv(null); setSession(null); setNote(null); setPhase("idle"); setReceipt(false);
  };

  const stepIndex = phase === "idle" || phase === "starting" ? 0 : phase === "redirect" ? 1 : 2;

  return (
    <div className="card card-pad">
      <div className="card-title" style={{ marginBlockEnd: 10 }}>پرداخت فاکتور</div>
      <Stepper steps={["فاکتور", "درگاه", "تأیید"]} current={stepIndex} />

      {(phase === "idle" || phase === "starting") && (
        pending.length === 0
          ? <p style={{ fontSize: 13, color: "var(--text-muted)" }}>فاکتور پرداخت‌نشده‌ای نیست.</p>
          : <div className="table-wrap">
            <table className="tbl tbl-cards">
              <thead><tr><th>فاکتور</th><th>مبلغ (تومان)</th><th>سررسید</th><th></th></tr></thead>
              <tbody>
                {pending.map((i) => (
                  <tr key={i.id}>
                    <td data-label="فاکتور">#{faNum(i.id)}</td>
                    <td data-label="مبلغ">{faNum(i.amount)}</td>
                    <td data-label="سررسید">{jalali(i.due_at)}</td>
                    <td data-label="پرداخت">
                      <button className="btn btn-primary btn-sm" disabled={phase === "starting"} onClick={() => void start(i)}>
                        {phase === "starting" && inv?.id === i.id ? "در حال شروع…" : "پرداخت"}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
      )}

      {phase === "redirect" && inv && session && (
        <div>
          <Alert tone="blue">در حال انتقال به درگاه (Stub زرین‌پال — بدون شارژ واقعی). شناسه ارجاع: <span dir="ltr">{session.authority}</span> <CopyToClipboard text={session.authority} label="کپی ارجاع" /></Alert>
          <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
            <a className="btn btn-primary" href={session.pay_url} target="_blank" rel="noreferrer" onClick={beginVerify}>رفتن به درگاه پرداخت</a>
            <button className="btn btn-ghost" onClick={beginVerify}>رد شدم — بررسی وضعیت</button>
            <button className="btn btn-ghost" onClick={cancel}>انصراف</button>
          </div>
        </div>
      )}

      {phase === "verifying" && (
        <div>
          <Alert tone="amber">در انتظار تأیید پرداخت فاکتور #{faNum(inv?.id ?? 0)}… (polling خودکار هر ۵ ثانیه)</Alert>
          <div style={{ display: "flex", gap: 8 }}>
            <button className="btn btn-primary btn-sm" onClick={() => void checkStatus(false)}>بررسی اکنون</button>
            <button className="btn btn-ghost btn-sm" onClick={cancel}>انصراف</button>
          </div>
          {note ? <p style={{ fontSize: 12.5, color: "var(--text-muted)", marginBlockStart: 8 }}>{note}</p> : null}
        </div>
      )}

      {phase === "done" && (
        <div>
          <Alert tone="green">{note ?? "پرداخت شد."} وضعیت فاکتور: {inv ? INVOICE_STATUS_FA[invoices.find((x) => x.id === inv.id)?.status ?? "paid"] : ""}</Alert>
          <div style={{ display: "flex", gap: 8, marginBlockStart: 8 }}>
            {inv ? <button className="btn btn-primary btn-sm" onClick={() => setReceipt(true)}>مشاهده رسید</button> : null}
            <button className="btn btn-ghost btn-sm" onClick={cancel}>بستن</button>
          </div>
          {receipt && inv ? <InvoicePrint invoice={{ ...inv, status: "paid" }} onClose={() => setReceipt(false)} /> : null}
        </div>
      )}
    </div>
  );
}
