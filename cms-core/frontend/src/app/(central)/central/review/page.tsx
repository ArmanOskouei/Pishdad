import { serverApi } from "@/lib/server-api";
import type { MarketReviewQueueItem, ReviewQueueItem } from "@/lib/domain";
import { Alert } from "@/components/ui/primitives";
import { ReviewClient } from "./ReviewClient";
import { MarketReviewsClient } from "./MarketReviewsClient";

/**
 * صف بازبینی مرکزی (مورد ۶) — پلاگین‌ها و قالب‌های در انتظار تأیید با مالک.
 * WF-H18 — و صف نظرهای بازار (امتیاز/نظر خریداران) در همان صفحه.
 */
export default async function ReviewPage() {
  let items: ReviewQueueItem[] = [];
  let pluginError: string | null = null;
  try {
    const json = await serverApi<{ data: ReviewQueueItem[] }>(`/v1/p/central/review/queue`);
    items = json.data ?? [];
  } catch (e) {
    pluginError = e instanceof Error ? e.message : "خطا در بارگذاری صف بازبینی.";
  }

  let marketReviews: MarketReviewQueueItem[] = [];
  let marketError: string | null = null;
  try {
    const json = await serverApi<{ data?: { data?: MarketReviewQueueItem[] } }>(`/v1/market/reviews`);
    marketReviews = json.data?.data ?? [];
  } catch (e) {
    marketError = e instanceof Error ? e.message : "خطا در بارگذاری نظرات بازار.";
  }

  return (
    <div style={{ display: "grid", gap: 20 }}>
      <div className="page-head">
        <div>
          <h1>صف بازبینی</h1>
          <p>پلاگین‌ها، قالب‌ها و نظرهای بازار در انتظار تأیید — پیش از نمایش به مشتری</p>
        </div>
      </div>

      <section style={{ display: "grid", gap: 8 }}>
        <h2 style={{ fontSize: 15, margin: 0 }}>افزونه‌ها و قالب‌ها</h2>
        {pluginError ? <Alert tone="red">{pluginError}</Alert> : <ReviewClient initial={items} />}
      </section>

      <section style={{ display: "grid", gap: 8 }}>
        <h2 style={{ fontSize: 15, margin: 0 }}>نظرات بازار</h2>
        {marketError ? <Alert tone="red">{marketError}</Alert> : <MarketReviewsClient initial={marketReviews} />}
      </section>
    </div>
  );
}
