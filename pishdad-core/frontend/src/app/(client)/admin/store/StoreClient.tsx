"use client";

import Link from "next/link";
import { useLang, type MessageKey } from "@/lib/i18n";
import { Alert, Badge, EmptyState } from "@/components/ui/primitives";
import { formatPrice, formatRating, storeAction, type StoreItem } from "@/lib/store";
import { useStoreActions } from "@/lib/useStoreActions";

/**
 * ECO6 — فروشگاه پنل (RTL). کاتالوگ + checkout + تحویل.
 *
 * WF-H16 — نوار فیلتر (جستجو/دسته/رایگان-پولی) با فرم GET و رندرِ سرورساید.
 * فرم بدون JS هم کار می‌کند؛ `key` از remount ورودی‌ها بعد از تغییر فیلتر
 * مطمئن می‌شود. دکمهٔ «نصب»/«خرید»/«دانلود» از هوکِ مشترک `useStoreActions`
 * می‌آید تا صفحهٔ جزئیات هم همان منطق را داشته باشد.
 */

export type StoreFilters = { q: string; category: string; price: string };

export function StoreClient({
  initial,
  categories,
  filters,
}: {
  initial: StoreItem[];
  categories: string[];
  filters: StoreFilters;
}) {
  const { t } = useLang();
  const { busy, placeOrder, payOrder, installItem, download } = useStoreActions();

  const tr = (key: MessageKey, values?: Record<string, string | number>) => t(key, values);
  const hasFilter = filters.q !== "" || filters.category !== "" || filters.price !== "";

  const reviewBadge = (item: StoreItem) => {
    if (item.review_status === "pending") return <Badge tone="amber">در انتظار بازبینی</Badge>;
    if (item.review_status === "rejected") return <Badge tone="red">ردشده</Badge>;
    return null;
  };

  return (
    <div style={{ display: "grid", gap: 16 }}>
      <div className="page-head">
        <div>
          <h1>{tr("store.title")}</h1>
          <p>{tr("store.subtitle")}</p>
        </div>
      </div>

      {/* صراحت: درگاه نیست. کاربر نباید منتظر «پرداخت» بماند. */}
      <Alert tone="amber">{tr("store.manualNote")}</Alert>

      <form
        method="get"
        action="/admin/store"
        key={`${filters.q}|${filters.category}|${filters.price}`}
        className="card card-pad"
        style={{ display: "flex", gap: 10, flexWrap: "wrap", alignItems: "end" }}
      >
        <label className="field" style={{ flex: "1 1 240px" }}>
          <span>{tr("store.searchPlaceholder")}</span>
          <input className="input" type="search" name="q" defaultValue={filters.q} />
        </label>

        <label className="field">
          <span>{tr("store.category")}</span>
          <select className="input" name="category" defaultValue={filters.category}>
            <option value="">{tr("store.allCategories")}</option>
            {categories.map((c) => (
              <option key={c} value={c}>{c}</option>
            ))}
          </select>
        </label>

        <label className="field">
          <span>{tr("store.priceLabel")}</span>
          <select className="input" name="price" defaultValue={filters.price}>
            <option value="">{tr("store.allPrices")}</option>
            <option value="free">{tr("store.free")}</option>
            <option value="paid">{tr("store.pricePaid")}</option>
          </select>
        </label>

        <button className="btn btn-primary btn-sm" type="submit">{tr("store.apply")}</button>
        {hasFilter ? (
          <Link className="btn btn-ghost btn-sm" href="/admin/store">{tr("store.clear")}</Link>
        ) : null}
      </form>

      {initial.length === 0 ? (
        <EmptyState title={tr("store.empty")} hint={tr("store.emptyHint")} />
      ) : (
        <div style={{ display: "grid", gap: 12 }}>
          {initial.map((item) => {
            const action = storeAction(item);
            return (
              <div key={item.id} className="card card-pad" style={{ display: "grid", gap: 8 }}>
                <div style={{ display: "flex", justifyContent: "space-between", alignItems: "baseline", gap: 10, flexWrap: "wrap" }}>
                  <div>
                    <b style={{ fontSize: 15 }}>{item.name}</b>{" "}
                    <span className="muted" style={{ fontSize: 12 }} dir="ltr">{item.slug}</span>{" "}
                    <code style={{ fontSize: 12 }}>v{item.version}</code>{" "}
                    {item.category ? <Badge tone="gray">{item.category}</Badge> : null}
                  </div>
                  <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
                    {item.active ? <Badge tone="green">فعال</Badge> : null}
                    {!item.active && item.delivery.owned ? <Badge tone="violet">نصب‌شده</Badge> : null}
                    {reviewBadge(item)}
                    {item.delivery.owned ? <Badge tone="green">{tr("store.owned")}</Badge> : null}
                    {item.delivery.delivered ? <Badge tone="violet">{tr("store.deliveredOnce")}</Badge> : null}
                    <b style={{ fontSize: 14 }}>{formatPrice(item.price, item.currency)}</b>
                  </div>
                </div>

                {item.long_description || item.description ? (
                  <p className="muted" style={{ margin: 0, fontSize: 13 }}>
                    {item.long_description ?? item.description}
                  </p>
                ) : null}

                {/* WF-H18 — social proof: امتیاز، شمار نظر و نصب فعال (دادهٔ واقعی). */}
                <div style={{ display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap", fontSize: 12.5 }}>
                  {item.rating !== null && item.rating !== undefined ? (
                    <>
                      <span dir="ltr" style={{ color: "var(--warning, #f59e0b)" }}>
                        ★ {formatRating(item.rating)}
                      </span>
                      <span className="muted">{tr("store.ratingCount", { n: item.rating_count ?? 0 })}</span>
                    </>
                  ) : (
                    <span className="muted">{tr("store.noRating")}</span>
                  )}
                  <span className="muted">
                    {tr("store.installs")}: <b>{item.active_installs ?? 0}</b>
                  </span>
                </div>

                <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                  {/* WF-C5 — نصب یک‌کلیکی (بدون ZIP دستی). */}
                  {!item.active ? (
                    <button
                      className="btn btn-primary btn-sm"
                      disabled={busy === item.id}
                      aria-label={`${tr("store.install")} ${item.name}`}
                      onClick={() => void installItem(item)}
                    >
                      {tr("store.install")}
                    </button>
                  ) : null}

                  {action === "already_delivered" ? (
                    <span className="muted" style={{ fontSize: 12.5 }}>{tr("store.deliveredOnce")}</span>
                  ) : null}

                  {action === "download" ? (
                    <button className="btn btn-ghost btn-sm" disabled={busy === item.id} onClick={() => download(item)}>
                      {tr("store.download")}
                    </button>
                  ) : null}

                  {action === "buy" || action === "get" ? (
                    <button className="btn btn-ghost btn-sm" disabled={busy === item.id} onClick={() => placeOrder(item)}>
                      {action === "get" ? tr("store.free") : tr("store.buy")}
                    </button>
                  ) : null}

                  {/* پرداخت دستی: فقط وقتی سفارش pending داریم. تشخیص در هندلر است،
                      ولی دکمه را برای کاربرِ نخریده نشان نمی‌دهیم. */}
                  {!item.delivery.owned && item.price > 0 ? (
                    <button className="btn btn-ghost btn-sm" disabled={busy === item.id} onClick={() => payOrder(item)}>
                      {tr("store.payOrder")}
                    </button>
                  ) : null}

                  <Link className="btn btn-ghost btn-sm" href={`/admin/store/${item.id}`}>
                    {tr("store.viewDetail")}
                  </Link>
                </div>

                {(action === "buy" || action === "get") ? (
                  <p className="muted" style={{ margin: 0, fontSize: 12 }}>{tr("store.buyHint")}</p>
                ) : null}
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
