"use client";
import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Alert } from "@/components/ui/primitives";
import { Stepper } from "@/components/ui/primitives";
import { faNum, jalali } from "@/lib/fa";
import { INVOICE_STATUS_FA, type Invoice, type PaySession } from "@/lib/domain";
import { LEGACY_SUBSCRIPTION_API, SUBSCRIPTION_API } from "@/lib/subscription";
import { InvoicePrint } from "./InvoicePrint";

type Phase = "idle" | "starting" | "manual" | "verifying" | "done";

/**
 * L-B12 — پروکسیِ پنل مسیرِ `/v1/p/…` را مسدود می‌کند ⇒ در جزیره‌های کلاینت
 * مسیرِ legacy (که سرور به افزونه forward می‌کند) اول و کارآمد است. پاسخِ
 * legacy هدر `Deprecation` دارد ولی کار می‌کند و در نبودِ افزونه هم دیگر ۴۰۴/۴۰۳
 * نمی‌دهد (fallback هسته).
 */
async function authedSub<T>(pluginPath: string, legacyPath: string, init?: { method?: string }): Promise<T> {
  try {
    return await authed<T>(legacyPath, init);
  } catch {
    return authed<T>(pluginPath, init);
  }
}

/**
 * J10 — فاکتور (دستی) → راهنمایی پرداخت → بررسی وضعیت.
 *
 * نسخهٔ قبلی سه گامِ «فاکتور → درگاه → تأیید» داشت و در گامِ دوم کاربر را به
 * `sandbox.zarinpal.com` می‌فرستاد. آن صفحه هرگز پرداختی نمی‌کرد، پس کاربر بعد
 * از برگشت می‌پرسید چرا فاکتور `paid` نشد — و هیچ پاسخِ درستی وجود نداشت.
 *
 * حالا گامِ دوم «درگاه» حذف شده چون **درگاهی وجود ندارد**. جایش یک راهنمای
 * صادقانه است: مبلغ را نشان بده، بگو پرداخت بیرون از سامانه است، و بگو
 * اپراتور مرکزی وضعیت را ثبت می‌کند. polling می‌ماند چون وضعیت واقعاً بیرون از
 * این صفحه عوض می‌شود.
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
      const list = await authedSub<Invoice[] | { data: Invoice[] }>(
        `${SUBSCRIPTION_API.invoices}?per_page=50`,
        `${LEGACY_SUBSCRIPTION_API.invoices}?per_page=50`,
      );
      const arr = Array.isArray(list) ? list : (list.data ?? []);
      const cur = arr.find((x) => x.id === inv.id);
      if (cur?.status === "paid") {
        if (timer.current) clearInterval(timer.current);
        setPhase("done");
        setNote("پرداخت تأیید شد — فاکتور paid شد و تعلیق (در صورت وجود) رفع می‌شود.");
        toast("پرداخت موفق بود.", "ok");
        router.refresh();
      } else if (!silent) {
        toast("هنوز پرداخت ثبت نشده — اپراتور مرکزی پس از واریز، وضعیت را دستی ثبت می‌کند.", "err");
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
      const s = await authedSub<PaySession>(
        SUBSCRIPTION_API.pay(invoice.id),
        LEGACY_SUBSCRIPTION_API.pay(invoice.id),
        { method: "POST" },
      );
      setSession(s);
      // J10 — پاسخ سرور حرف می‌زند و می‌گوید `manual_billing`. اینجا به آن تکیه
      // نمی‌کنیم و فقط `manual` را نشان می‌دهیم: اگر روزی درگاهی واقعاً اضافه
      // شد، همین صفحه بدون هیچ تغییری باید مسیرِ «انتقال به درگاه» را داشته
      // باشد، و آن شاخه در `SubscriptionData` زنده نگه داشته شده است.
      setPhase("manual");
      beginVerify();
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
        setNote("پس از ۶۰ ثانیه هنوز paid ثبت نشده — اپراتور مرکزی وضعیت را دستی ثبت می‌کند. تا آن زمان اشتراک شما فعال است.");
      }
    }, 5000);
  };

  const cancel = () => {
    if (timer.current) clearInterval(timer.current);
    setInv(null); setSession(null); setNote(null); setPhase("idle"); setReceipt(false);
  };

  const stepIndex = phase === "idle" || phase === "starting" ? 0 : phase === "manual" ? 1 : 2;

  return (
    <div className="card card-pad">
      <div className="card-title" style={{ marginBlockEnd: 10 }}>پرداخت فاکتور</div>
      <Stepper steps={["فاکتور", "پرداخت دستی", "تأیید"]} current={stepIndex} />

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

      {(phase === "manual" || phase === "verifying") && inv && (
        <div>
          <Alert tone="amber">
            صورتحساب دستی است. مبلغ <span dir="ltr">{faNum(session?.amount ?? inv.amount)}</span>{" "}
            {session?.currency ?? inv.currency} را بیرون از این سامانه بپردازید؛ اپراتور مرکزی
            پس از تأیید، وضعیت این فاکتور را دستی <span dir="ltr">paid</span> می‌کند.
            <br />
            این صفحه هر ۵ ثانیه وضعیت را می‌پرسد و به‌محض ثبت شدن، همین‌جا خبر می‌دهد.
          </Alert>
          <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
            <button className="btn btn-primary" onClick={() => void checkStatus(false)}>بررسی اکنون</button>
            <button className="btn btn-ghost" onClick={cancel}>بستن</button>
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
