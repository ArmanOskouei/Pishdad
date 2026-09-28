import { serverApi, serverOne, apiQs } from "@/lib/server-api";
import { asPaginator, type CentralClient, type GatewaySettings, type Invoice, type Payment, type Plan, type SuspendedSub } from "@/lib/domain";
import { Alert } from "@/components/ui/primitives";
import { Pagination } from "@/components/ui/Pagination";
import { Tabs } from "@/components/ui/Tabs";
import { BillingTabs } from "./BillingTabs";

const TABS = [
  { key: "plans", label: "پلن‌ها" },
  { key: "invoices", label: "فاکتورها" },
  { key: "payments", label: "پرداخت‌ها" },
  { key: "suspension", label: "کنترل تعلیق" },
  { key: "gateway", label: "درگاه" },
];

/** صورتحساب مرکزی ۲.۴ — Server Component پنج‌تب با داده واقعی هر تب. */
export default async function BillingPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const sp = await searchParams;
  const tab = TABS.some((t) => t.key === sp.tab) ? (sp.tab as string) : "plans";
  const page = Math.max(1, Number(sp.page ?? 1) || 1);

  let error: string | null = null;
  let plans: Plan[] = [];
  let invoices = asPaginator<Invoice>(null);
  let invoiceClients: CentralClient[] = [];
  let payments = asPaginator<Payment>(null);
  let suspended = asPaginator<SuspendedSub>(null);
  let gateway: GatewaySettings | null = null;

  try {
    if (tab === "plans") {
      plans = asPaginator<Plan>(await serverApi<unknown>("/v1/p/central/plans?per_page=50")).data;
    } else if (tab === "invoices") {
      const [inv, clients] = await Promise.all([
        serverApi<unknown>(`/v1/p/central/invoices${apiQs(sp, ["status", "client_id"], { per_page: 20, page })}`),
        serverApi<unknown>("/v1/p/central/clients?per_page=100"),
      ]);
      invoices = asPaginator<Invoice>(inv);
      invoiceClients = asPaginator<CentralClient>(clients).data;
    } else if (tab === "payments") {
      payments = asPaginator<Payment>(
        await serverApi<unknown>(`/v1/p/central/payments${apiQs(sp, ["status"], { per_page: 20, page })}`),
      );
    } else if (tab === "suspension") {
      suspended = asPaginator<SuspendedSub>(await serverApi<unknown>(`/v1/p/central/subscriptions/suspended?per_page=20&page=${page}`));
    } else {
      gateway = await serverOne<GatewaySettings>("/v1/p/central/gateway-settings");
    }
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری.";
  }

  const tabHref = (key: string) => {
    const q = new URLSearchParams();
    q.set("tab", key);
    if (key === "invoices") {
      if (sp.status) q.set("status", sp.status);
      if (sp.client_id) q.set("client_id", sp.client_id);
    }
    if (key === "payments" && sp.status) q.set("status", sp.status);
    return `/central/billing?${q.toString()}`;
  };

  const pagerParams: Record<string, string> = { tab };
  if (tab === "invoices") {
    if (sp.status) pagerParams.status = sp.status;
    if (sp.client_id) pagerParams.client_id = sp.client_id;
  }
  if (tab === "payments" && sp.status) pagerParams.status = sp.status;
  const pagerBase = "/central/billing";
  const activePager = tab === "invoices" ? invoices : tab === "payments" ? payments : tab === "suspension" ? suspended : null;

  return (
    <div>
      <div className="page-head">
        <div><h1>صورتحساب</h1><p>پلن‌ها، فاکتورها، پرداخت‌ها، تعلیق و درگاه</p></div>
      </div>

      <Tabs active={tab} tabs={TABS.map((t) => ({ ...t, href: tabHref(t.key) }))} />

      {tab === "invoices" ? (
        <form className="toolbar no-print" method="get" action="/central/billing">
          <input type="hidden" name="tab" value="invoices" />
          <div className="field">
            <label htmlFor="bs">وضعیت</label>
            <select id="bs" name="status" className="select" defaultValue={sp.status ?? ""}>
              <option value="">همه</option>
              <option value="pending">در انتظار</option>
              <option value="paid">پرداخت‌شده</option>
              <option value="failed">ناموفق</option>
              <option value="cancelled">لغوشده</option>
            </select>
          </div>
          <button className="btn btn-ghost" type="submit">اعمال</button>
        </form>
      ) : null}
      {tab === "payments" ? (
        <form className="toolbar" method="get" action="/central/billing">
          <input type="hidden" name="tab" value="payments" />
          <div className="field">
            <label htmlFor="ps">وضعیت</label>
            <select id="ps" name="status" className="select" defaultValue={sp.status ?? ""}>
              <option value="">همه</option>
              <option value="pending">در انتظار</option>
              <option value="paid">پرداخت‌شده</option>
              <option value="failed">ناموفق</option>
            </select>
          </div>
          <button className="btn btn-ghost" type="submit">اعمال</button>
        </form>
      ) : null}

      {error ? <Alert tone="red">{error}</Alert> : (
        <BillingTabs
          tab={tab} plans={plans} invoices={invoices} invoiceClients={invoiceClients}
          payments={payments} suspended={suspended} gateway={gateway}
        />
      )}

      {activePager ? (
        <Pagination page={activePager.current_page} lastPage={activePager.last_page} total={activePager.total} base={pagerBase} params={pagerParams} />
      ) : null}
    </div>
  );
}
