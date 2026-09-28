import { serverApi } from "@/lib/server-api";
import { asPaginator, type OperatorActivityItem } from "@/lib/domain";
import { Alert } from "@/components/ui/primitives";
import { OperatorClient } from "./OperatorClient";

/** پروفایل اپراتور ۲.۷ — کلید 2FA + توکن نصب + Kill-Switch + لاگ فعالیت. */
export default async function OperatorPage() {
  let activity = asPaginator<OperatorActivityItem>(null);
  let error: string | null = null;
  try {
    const json = await serverApi<unknown>(`/v1/p/central/operator/activity?per_page=25`);
    activity = asPaginator<OperatorActivityItem>(json);
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری لاگ اپراتور.";
  }

  return (
    <div>
      <div className="page-head">
        <div><h1>اپراتور</h1><p>توکن نصب و توقف اضطراری نیازمند 2FA تازه (کمتر از ۵ دقیقه) هستند</p></div>
      </div>
      {error ? <Alert tone="red">{error}</Alert> : <OperatorClient initialActivity={activity} />}
    </div>
  );
}
