import { serverApi, serverOne } from "@/lib/server-api";
import { asPaginator, type Deployment, type Reconciliation, type ServiceItem, type Wallet } from "@/lib/domain";
import { Alert } from "@/components/ui/primitives";
import { ChabokanClient } from "./ChabokanClient";

/** مدیریت چابکان ۲.۳ — Ops Console: کیف پول + سرویس‌ها + دیپلوی + reconciliation. */
export default async function ChabokanPage() {
  let wallet: Wallet | null = null;
  let services: ServiceItem[] = [];
  let deployments = asPaginator<Deployment>(null);
  let recon: Reconciliation | null = null;
  let error: string | null = null;

  try {
    const [w, s, d, r] = await Promise.all([
      serverOne<Wallet>(`/central/chabokan/wallet`),
      serverApi<unknown>(`/central/chabokan/services`),
      serverApi<unknown>(`/central/chabokan/deployments?per_page=15`),
      serverOne<Reconciliation>(`/central/chabokan/reconciliation`),
    ]);
    wallet = w;
    const sd = (s as { data?: unknown })?.data ?? s;
    services = Array.isArray(sd) ? (sd as ServiceItem[]) : [];
    deployments = asPaginator<Deployment>(d);
    recon = r;
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری چابکان.";
  }

  return (
    <div>
      <div className="page-head">
        <div><h1>مدیریت چابکان</h1><p>Ops Console — همه اکشن‌های مخرب با تأیید دومرحله‌ای و لاگ حسابرسی</p></div>
      </div>
      {error || !wallet || !recon ? (
        <Alert tone="red">{error ?? "داده‌ای نیست."}</Alert>
      ) : (
        <ChabokanClient initialWallet={wallet} initialServices={services} initialDeployments={deployments} initialRecon={recon} />
      )}
    </div>
  );
}
