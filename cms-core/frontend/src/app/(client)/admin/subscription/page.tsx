import { serverApi, serverOne } from "@/lib/server-api";
import { asPaginator, INVOICE_STATUS_FA, SUB_STATUS_FA, TIER_FA, type Invoice, type MySubscription } from "@/lib/domain";
import { faNum, jalali, relativeFa } from "@/lib/fa";
import { Alert, Badge, StatCard } from "@/components/ui/primitives";
import { PayFlow } from "./PayFlow";
import { PlanCompare } from "./PlanCompare";
import { PrintInvoiceButton } from "./InvoicePrint";

/** اشتراک من ۱.۱۵ — Server Component: کارت پلن + فاکتورها + Stepper پرداخت + بنر تعلیق. */
export default async function SubscriptionPage() {
  let sub: MySubscription | null = null;
  let invoices: Invoice[] = [];
  let error: string | null = null;
  try {
    const [s, inv] = await Promise.all([
      serverOne<MySubscription>("/v1/admin/subscription"),
      serverApi<unknown>("/v1/admin/subscription/invoices?per_page=20"),
    ]);
    sub = s;
    invoices = asPaginator<Invoice>(inv).data;
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری اشتراک.";
  }

  if (error || !sub) {
    return (
      <div>
        <div className="page-head"><div><h1>اشتراک من</h1></div></div>
        <Alert tone="red">{error ?? "اشتراک یافت نشد."}</Alert>
      </div>
    );
  }

  const suspended = sub.status === "suspended";
  const unpaid = invoices.filter((i) => i.status === "pending").length;

  return (
    <div>
      <div className="page-head">
        <div><h1>اشتراک من</h1><p>وضعیت پلن، فاکتورها و پرداخت</p></div>
      </div>

      {suspended ? (
        <div className="lock-banner" role="alert">
          <span>اشتراک شما معلق است — سایت مسدود و پنل فقط-پرداخت است. با پرداخت فاکتور، تعلیق خودکار رفع می‌شود.</span>
        </div>
      ) : null}

      <div className="grid c4" style={{ marginBlockEnd: 16 }}>
        <StatCard icon="◈" value={sub.plan?.name ?? TIER_FA[sub.tier] ?? sub.tier} label="پلن جاری" />
        <StatCard icon="●" value={SUB_STATUS_FA[sub.status] ?? sub.status} label="وضعیت" up={!suspended} />
        <StatCard icon="◷" value={jalali(sub.due_at)} label="سررسید" delta={relativeFa(sub.due_at)} up={!suspended} />
        <StatCard icon="✉" value={faNum(unpaid)} label="فاکتور پرداخت‌نشده" />
      </div>

      <div className="grid c2" style={{ alignItems: "start" }}>
        <div>
          <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
            <div className="card-title">کارت پلن {suspended ? <Badge tone="red">معلق</Badge> : <Badge tone="green">فعال</Badge>}</div>
            <div className="kv"><span>تیر</span><span>{TIER_FA[sub.tier] ?? sub.tier}</span></div>
            {sub.plan?.price_monthly ? <div className="kv"><span>ماهانه</span><span>{faNum(sub.plan.price_monthly)} تومان</span></div> : null}
            {sub.plan?.price_yearly ? <div className="kv"><span>سالانه (۲۰٪ تخفیف)</span><span>{faNum(sub.plan.price_yearly)} تومان</span></div> : null}
            <div className="kv"><span>روز باقی‌مانده</span><span>{faNum(sub.days_remaining)}</span></div>
            {sub.suspended_at ? <div className="kv"><span>تعلیق از</span><span>{jalali(sub.suspended_at)}</span></div> : null}
          </div>

          <div className="card card-pad">
            <div className="card-title" style={{ marginBlockEnd: 8 }}>تاریخچه فاکتورها</div>
            {invoices.length === 0 ? <p style={{ fontSize: 13, color: "var(--text-muted)" }}>فاکتوری ثبت نشده.</p> : (
              <div className="table-wrap">
                <table className="tbl tbl-cards">
                  <thead><tr><th>فاکتور</th><th>مبلغ</th><th>وضعیت</th><th>سررسید</th><th>عملیات</th></tr></thead>
                  <tbody>
                    {invoices.map((i) => (
                      <tr key={i.id}>
                        <td data-label="فاکتور">#{faNum(i.id)}</td>
                        <td data-label="مبلغ">{faNum(i.amount)}</td>
                        <td data-label="وضعیت">
                          <Badge tone={i.status === "paid" ? "green" : i.status === "pending" ? "amber" : "gray"}>
                            {INVOICE_STATUS_FA[i.status] ?? i.status}
                          </Badge>
                        </td>
                        <td data-label="سررسید">{jalali(i.due_at)}</td>
                        <td data-label="عملیات">{i.status === "paid" ? <PrintInvoiceButton invoice={i} /> : <span style={{ fontSize: 12, color: "var(--text-muted)" }}>—</span>}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>

        <PayFlow invoices={invoices} />
      </div>

      <PlanCompare currentPlanId={sub.plan?.id} currentMonthly={sub.plan?.price_monthly} />
    </div>
  );
}
