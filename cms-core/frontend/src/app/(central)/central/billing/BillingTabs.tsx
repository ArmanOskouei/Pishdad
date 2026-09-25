"use client";
import { useRouter } from "next/navigation";
import { Badge, Alert } from "@/components/ui/primitives";
import { PlanManager, InvoiceCreate, InvoiceRemind, InvoiceStatus, SuspensionActions, GatewayForm } from "./BillingClients";
import { faNum, jalali } from "@/lib/fa";
import {
  INVOICE_STATUS_FA, TIER_FA,
  type CentralClient, type GatewaySettings, type Invoice, type Paginator, type Payment, type Plan, type SuspendedSub,
} from "@/lib/domain";

/** پوسته تب‌های Billing: رندر جدول‌های سرور + اتصال اکشن‌های تعاملی. */
export function BillingTabs({
  tab, plans, invoices, invoiceClients, payments, suspended, gateway,
}: {
  tab: string;
  plans: Plan[];
  invoices: Paginator<Invoice>;
  invoiceClients: CentralClient[];
  payments: Paginator<Payment>;
  suspended: Paginator<SuspendedSub>;
  gateway: GatewaySettings | null;
}) {
  const router = useRouter();
  const refresh = () => router.refresh();

  if (tab === "plans") {
    return <PlanManager plans={plans} onDone={refresh} />;
  }

  if (tab === "invoices") {
    return (
      <div>
        <div style={{ display: "flex", marginBlockEnd: 12, gap: 8 }} className="no-print">
          <div style={{ flex: 1 }} />
          <button className="btn btn-ghost" onClick={() => window.print()}>چاپ فهرست</button>
          <InvoiceCreate clients={invoiceClients} onDone={refresh} />
        </div>
        {invoices.data.length === 0 ? <div className="card card-pad">فاکتوری نیست.</div> : (
          <div className="table-wrap">
            <table className="tbl tbl-cards">
              <thead><tr><th>فاکتور</th><th>مشتری</th><th>مبلغ</th><th>وضعیت</th><th>سررسید</th><th className="no-print">تغییر وضعیت</th><th className="no-print">یادآوری</th></tr></thead>
              <tbody>
                {invoices.data.map((i) => (
                  <tr key={i.id}>
                    <td data-label="فاکتور">#{faNum(i.id)}{i.gateway_ref ? <><br /><small dir="ltr" style={{ color: "var(--text-muted)" }}>{i.gateway_ref}</small></> : null}</td>
                    <td data-label="مشتری">{i.client?.name ?? `#${faNum(i.client_id)}`}</td>
                    <td data-label="مبلغ">{faNum(i.amount)} {i.currency ?? "تومان"}</td>
                    <td data-label="وضعیت">
                      <Badge tone={i.status === "paid" ? "green" : i.status === "pending" ? "amber" : "gray"}>
                        {INVOICE_STATUS_FA[i.status] ?? i.status}
                      </Badge>
                    </td>
                    <td data-label="سررسید">{jalali(i.due_at)}</td>
                    <td data-label="تغییر وضعیت" className="no-print"><InvoiceStatus id={i.id} status={i.status} onDone={refresh} /></td>
                    <td data-label="یادآوری" className="no-print">
                      {i.status === "pending" ? <InvoiceRemind id={i.id} onDone={refresh} /> : <span style={{ fontSize: 12, color: "var(--text-muted)" }}>—</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    );
  }

  if (tab === "payments") {
    return (
      <div>
        {payments.data.length === 0 ? <div className="card card-pad">تراکنشی نیست.</div> : (
          <div className="table-wrap">
            <table className="tbl tbl-cards">
              <thead><tr><th>کد پیگیری</th><th>درگاه</th><th>فاکتور</th><th>مبلغ</th><th>وضعیت</th><th>زمان</th></tr></thead>
              <tbody>
                {payments.data.map((p) => (
                  <tr key={p.id}>
                    <td data-label="کد پیگیری"><span dir="ltr">{p.ref}</span> <CopyRef text={p.ref} /></td>
                    <td data-label="درگاه"><span dir="ltr">{p.gateway}</span></td>
                    <td data-label="فاکتور">{p.invoice_id != null ? `#${faNum(p.invoice_id)}` : "—"}</td>
                    <td data-label="مبلغ">{faNum(p.amount)}</td>
                    <td data-label="وضعیت"><Badge tone={p.status === "paid" ? "green" : p.status === "pending" ? "amber" : "gray"}>{p.status}</Badge></td>
                    <td data-label="زمان">{jalali(p.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    );
  }

  if (tab === "suspension") {
    return (
      <div>
        <Alert tone="amber"><b>طیف تعلیق:</b> یادآوری D-7 تا D-0 ← تعلیق در سررسید ← هشدار D+5 ← حذف خودکار D+7</Alert>
        <p style={{ fontSize: 13, color: "var(--text-muted)" }}>مشتریان معلق + روز باقی‌مانده تا حذف (D+7). مهم‌ترین تب عملیاتی.</p>
        {suspended.data.length === 0 ? <div className="card card-pad">مشتری معلقی نیست.</div> : (
          <div className="table-wrap">
            <table className="tbl tbl-cards">
              <thead><tr><th>مشتری</th><th>پلن</th><th>تعلیق از</th><th>روز مانده تا حذف</th><th>اکشن</th></tr></thead>
              <tbody>
                {suspended.data.map((s) => (
                  <tr key={s.id}>
                    <td data-label="مشتری"><b>{s.client?.name ?? `#${faNum(s.client_id)}`}</b><br /><small dir="ltr" style={{ color: "var(--text-muted)" }}>{s.client?.site_id}</small></td>
                    <td data-label="پلن">{s.plan?.name ?? "—"}</td>
                    <td data-label="تعلیق از">{jalali(s.suspended_at)}</td>
                    <td data-label="روز مانده"><Badge tone={s.days_remaining <= 2 ? "red" : "amber"}>{faNum(s.days_remaining)} روز</Badge></td>
                    <td data-label="اکشن"><SuspensionActions subId={s.id} onDone={refresh} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    );
  }

  return gateway ? <GatewayForm initial={gateway} /> : <div className="card card-pad">تنظیمات درگاه بارگذاری نشد.</div>;
}

function CopyRef({ text }: { text: string }) {
  return (
    <button
      className="btn btn-ghost btn-sm no-print"
      onClick={() => { void navigator.clipboard.writeText(text).catch(() => undefined); }}
      title="کپی کد پیگیری"
    >کپی</button>
  );
}

export function TierName({ tier }: { tier: string }) {
  return <>{TIER_FA[tier] ?? tier}</>;
}
