import { serverApi } from "@/lib/server-api";
import type { ReviewQueueItem } from "@/lib/domain";
import { Alert } from "@/components/ui/primitives";
import { ReviewClient } from "./ReviewClient";

/** صف بازبینی مرکزی (مورد ۶) — پلاگین‌ها و قالب‌های در انتظار تأیید با مالک. */
export default async function ReviewPage() {
  let items: ReviewQueueItem[] = [];
  let error: string | null = null;
  try {
    const json = await serverApi<{ data: ReviewQueueItem[] }>(`/central/review/queue`);
    items = json.data ?? [];
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری صف بازبینی.";
  }

  return (
    <div>
      <div className="page-head">
        <div><h1>صف بازبینی</h1><p>پلاگین‌ها و قالب‌های در انتظار تأیید — بررسی امنیتی پیش از فعال‌سازی مشتری</p></div>
      </div>
      {error ? <Alert tone="red">{error}</Alert> : <ReviewClient initial={items} />}
    </div>
  );
}
