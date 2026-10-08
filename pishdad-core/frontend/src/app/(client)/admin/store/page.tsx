import { serverApi, serverOne, apiQs } from "@/lib/server-api";
import { asStoreCategories, asStoreList } from "@/lib/store";
import { asPublisherDashboard } from "@/lib/publisher";
import { asPaginator } from "@/lib/domain";
import { Alert } from "@/components/ui/primitives";
import { Pagination } from "@/components/ui/Pagination";
import { Tabs } from "@/components/ui/Tabs";
import { StoreClient } from "./StoreClient";
import { PublisherDashboardClient } from "./PublisherDashboardClient";

/**
 * ECO6 — فروشگاه پنل: کاتالوگ + checkout + تحویل ZIP.
 *
 * چرا سرورساید: `serverApi` توکن را از کوکی httpOnly برمی‌دارد و کاربرِ
 * واردشده را می‌شناسد، پس وضعیتِ خرید/تحویل همان‌جای رندر می‌آید — بدون
 * FOUC و بدون درخواستِ خالی از مرورگر. کلیدواژهٔ `delivery` که بک‌اند
 * برمی‌گرداند، تعیین می‌کند کدام دکمه دیده شود (خرید / دانلود / تحویل‌شده).
 *
 * WF-H16 — فیلترها (`q`/`category`/`price`) هم سرورساید به API می‌روند؛
 * دسته‌های قابل انتخاب از `meta.categories` می‌آید و صفحه‌بندی همان‌ها را
 * حفظ می‌کند.
 *
 * WF-H19 — تبِ «ناشر من» (`?tab=publisher`): داشبورد ناشر (پلاگین‌های من،
 * وضعیت بازبینی، فروش/سهم و تسویه). داده سرورساید از `/v1/market/me/publisher`
 * می‌آید؛ تب‌ها لینک‌محور و حالت در URL است.
 */
export default async function StorePage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const sp = await searchParams;
  const tab = sp.tab === "publisher" ? "publisher" : "catalog";
  const page = Math.max(1, Number(sp.page ?? 1) || 1);
  const q = (sp.q ?? "").trim();
  const category = (sp.category ?? "").trim();
  const price = sp.price === "free" || sp.price === "paid" ? sp.price : "";

  const tabs = [
    { key: "catalog", label: "فروشگاه", href: "/admin/store" },
    { key: "publisher", label: "ناشر من", href: "/admin/store?tab=publisher" },
  ];

  if (tab === "publisher") {
    let dashboard = asPublisherDashboard(null);
    let error: string | null = null;
    try {
      dashboard = asPublisherDashboard(await serverOne<unknown>("/v1/market/me/publisher"));
    } catch (e) {
      error = e instanceof Error ? e.message : "داشبورد ناشر خوانده نشد.";
    }

    return (
      <div className="store-page">
        <Tabs active="publisher" tabs={tabs} />
        {error ? (
          <Alert tone="red">{error}</Alert>
        ) : (
          <PublisherDashboardClient initial={dashboard} />
        )}
      </div>
    );
  }

  let items = asStoreList([]);
  let categories: string[] = [];
  let paginator = asPaginator<unknown>(null);
  let error: string | null = null;
  try {
    const json = await serverApi<unknown>(
      `/v1/market/catalog${apiQs(sp, ["q", "category", "price"], { page })}`,
    );
    paginator = asPaginator<unknown>(json);
    items = asStoreList(json);
    categories = asStoreCategories(json);
  } catch (e) {
    error = e instanceof Error ? e.message : "کاتالوگ فروشگاه خوانده نشد.";
  }

  const preserve: Record<string, string> = { page: String(page) };
  if (q) preserve.q = q;
  if (category) preserve.category = category;
  if (price) preserve.price = price;

  return (
    <div className="store-page">
      <Tabs active="catalog" tabs={tabs} />
      {error ? (
        <Alert tone="red">{error}</Alert>
      ) : (
        <StoreClient
          initial={items}
          categories={categories}
          filters={{ q, category, price }}
        />
      )}
      {!error ? (
        <Pagination
          page={paginator.current_page}
          lastPage={paginator.last_page}
          total={paginator.total}
          base="/admin/store"
          params={preserve}
        />
      ) : null}
    </div>
  );
}
