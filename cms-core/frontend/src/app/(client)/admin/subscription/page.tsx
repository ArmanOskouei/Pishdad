import { cookies } from "next/headers";
import {
  asInvoices,
  LEGACY_SUBSCRIPTION_API,
  parseSuccessorVersion,
  SUBSCRIPTION_API,
  subscriptionSummaryBlocks,
} from "@/lib/subscription";
import {
  asPaginator,
  INVOICE_STATUS_FA,
  SUB_STATUS_FA,
  TIER_FA,
  type BlockValue,
  type Invoice,
  type MySubscription,
  type Plan,
} from "@/lib/domain";
import { faNum, jalali, relativeFa } from "@/lib/fa";
import { Alert, Badge } from "@/components/ui/primitives";
import { BlockRenderer } from "@/components/site/BlockRenderer";
import { PayFlow } from "./PayFlow";
import { PlanCompare } from "./PlanCompare";
import { PrintInvoiceButton } from "./InvoicePrint";

/**
 * اشتراک من ۱.۱۵ + L-B12 — Server Component اعلانی.
 *
 * داده از catch-all افزونه‌ها (`/v1/p/subscription/*`) می‌آید و خلاصهٔ وضعیت
 * به `BlockValue[]` تبدیل می‌شود که همان `BlockRenderer` هسته رندرش می‌کند —
 * الگوی K7.18/K7.19 (`<h1>{title}</h1>` + بلوک‌ها؛ بدون `next/dynamic`، بدون
 * کد پلاگین در مرورگر). کنش‌ها (پرداخت/ارتقا) جزیره‌های تعاملی خودشان‌اند.
 *
 * سازگاری مسیر: routeهای قدیمی `/v1/admin/subscription*` بازآدایه‌اند و هدر
 * `Deprecation` + `Link rel="successor-version"` می‌فرستند. ترتیب تلاش همیشه
 * «نو، بعد قدیمی» است؛ هدر قدیمی خوانده و لاگ می‌شود تا مهاجرت قابل ردیابی
 * بماند، نه اینکه بی‌صدا روی قدیمی بمانیم.
 */

const UPSTREAM =
  process.env.INTERNAL_API_URL ??
  process.env.NEXT_PUBLIC_API_URL ??
  "http://localhost:8080/api";

type FetchOutcome<T> = { value: T; via: "plugin" | "legacy" };

async function fetchSub<T>(pluginPath: string, legacyPath: string): Promise<FetchOutcome<T>> {
  const token = (await cookies()).get("auth_token")?.value;
  const headers = {
    "Content-Type": "application/json",
    Accept: "application/json",
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
  };
  for (const [path, via] of [
    [pluginPath, "plugin"],
    [legacyPath, "legacy"],
  ] as const) {
    const res = await fetch(`${UPSTREAM}${path}`, { headers, cache: "no-store" });
    if (!res.ok) continue;
    if (via === "legacy") {
      const successor = parseSuccessorVersion(res.headers.get("link"));
      console.info(
        `[subscription] legacy route used: ${path} (Deprecation: ${res.headers.get("deprecation") ?? "?"}, successor: ${successor ?? "?"})`,
      );
    }
    const json = (await res.json().catch(() => ({}))) as { data?: unknown };
    return { value: ((json as { data?: unknown })?.data ?? json) as T, via };
  }
  throw new Error("خطا در بارگذاری اشتراک.");
}

export default async function SubscriptionPage() {
  let sub: MySubscription | null = null;
  let invoices: Invoice[] = [];
  let plans: Plan[] = [];
  let error: string | null = null;
  try {
    const [s, inv, pl] = await Promise.all([
      fetchSub<MySubscription>(SUBSCRIPTION_API.show, LEGACY_SUBSCRIPTION_API.show),
      fetchSub<unknown>(
        `${SUBSCRIPTION_API.invoices}?per_page=20`,
        `${LEGACY_SUBSCRIPTION_API.invoices}?per_page=20`,
      ),
      fetchSub<unknown>(SUBSCRIPTION_API.plans, LEGACY_SUBSCRIPTION_API.plans).catch(
        (): FetchOutcome<unknown> => ({ value: [], via: "plugin" }),
      ),
    ]);
    sub = s.value;
    invoices = asPaginator<Invoice>(inv.value).data;
    const rawPlans = asInvoices(pl.value);
    plans = rawPlans as unknown as Plan[];
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

  // L-B12 — خلاصهٔ وضعیت، اعلانی: فقط واژگان هسته، رندر با BlockRenderer.
  const blocks: BlockValue[] = subscriptionSummaryBlocks({
    planName: sub.plan?.name ?? TIER_FA[sub.tier] ?? sub.tier,
    tierFa: TIER_FA[sub.tier] ?? sub.tier,
    statusFa: SUB_STATUS_FA[sub.status] ?? sub.status,
    dueFa: jalali(sub.due_at),
    daysFa: relativeFa(sub.due_at),
    unpaidFa: faNum(unpaid),
    suspended,
    unpaidCount: unpaid,
  });

  return (
    <div>
      <div className="page-head">
        <div><h1>اشتراک من</h1><p>وضعیت پلن، فاکتورها و پرداخت</p></div>
        {suspended ? <Badge tone="red">معلق</Badge> : <Badge tone="green">فعال</Badge>}
      </div>

      <BlockRenderer blocks={blocks} />

      <div className="grid c2" style={{ alignItems: "start", marginBlockStart: 16 }}>
        <div>
          <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
            <div className="card-title">کارت پلن</div>
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

      <PlanCompare currentPlanId={sub.plan?.id} currentMonthly={sub.plan?.price_monthly} initialPlans={plans} />
    </div>
  );
}
