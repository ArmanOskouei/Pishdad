import { serverApi } from "@/lib/server-api";
import { asStoreList } from "@/lib/store";
import { asPaginator } from "@/lib/domain";
import { Alert } from "@/components/ui/primitives";
import { Pagination } from "@/components/ui/Pagination";
import { StoreClient } from "./StoreClient";

/**
 * ECO6 — فروشگاه پنل: کاتالوگ + checkout + تحویل ZIP.
 *
 * چرا سرورساید: `serverApi` توکن را از کوکی httpOnly برمی‌دارد و کاربرِ
 * واردشده را می‌شناسد، پس وضعیتِ خرید/تحویل همان‌جای رندر می‌آید — بدون
 * FOUC و بدون درخواستِ خالی از مرورگر. کلیدواژهٔ `delivery` که بک‌اند
 * برمی‌گرداند، تعیین می‌کند کدام دکمه دیده شود (خرید / دانلود / تحویل‌شده).
 */
export default async function StorePage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const sp = await searchParams;
  const page = Math.max(1, Number(sp.page ?? 1) || 1);

  let items = asStoreList([]);
  let paginator = asPaginator<unknown>(null);
  let error: string | null = null;
  try {
    const json = await serverApi<unknown>(`/v1/market/catalog?page=${page}`);
    paginator = asPaginator<unknown>(json);
    items = asStoreList(json);
  } catch (e) {
    error = e instanceof Error ? e.message : "کاتالوگ فروشگاه خوانده نشد.";
  }

  return (
    <div className="store-page">
      {error ? (
        <Alert tone="red">{error}</Alert>
      ) : (
        <StoreClient initial={items} />
      )}
      {!error ? (
        <Pagination
          page={paginator.current_page}
          lastPage={paginator.last_page}
          total={paginator.total}
          base="/admin/store"
          params={{ page: String(page) }}
        />
      ) : null}
    </div>
  );
}
