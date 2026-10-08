import { serverApi } from "@/lib/server-api";
import { asStoreDetail } from "@/lib/store";
import { Alert } from "@/components/ui/primitives";
import { StoreDetailClient } from "./StoreDetailClient";

/**
 * WF-H16 — صفحهٔ جزئیات افزونهٔ فروشگاه.
 *
 * سرورساید می‌خواند تا وضعیتِ خرید/تحویل همان‌جای رندر بیاید (بدون FOUC).
 * اگر افزونه در فروشگاه در دسترس نباشد (yank/غیرِ بازار)، بک‌اند ۴۰۴ می‌دهد و
 * همان پیامِ صادقانه نشان داده می‌شود.
 */
export default async function StoreDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  let detail = null;
  let error: string | null = null;
  try {
    const json = await serverApi<unknown>(`/v1/market/catalog/${encodeURIComponent(id)}`);
    detail = asStoreDetail((json as { data?: unknown })?.data ?? json);
  } catch (e) {
    error = e instanceof Error ? e.message : "جزئیات افزونه خوانده نشد.";
  }

  return (
    <div className="store-page">
      {error ? (
        <Alert tone="red">{error}</Alert>
      ) : detail ? (
        <StoreDetailClient item={detail} />
      ) : (
        <Alert tone="red">افزونه پیدا نشد.</Alert>
      )}
    </div>
  );
}
