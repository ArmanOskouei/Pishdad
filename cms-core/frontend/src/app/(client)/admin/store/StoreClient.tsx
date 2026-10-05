"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useLang, type MessageKey } from "@/lib/i18n";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Alert, Badge, EmptyState } from "@/components/ui/primitives";
import { formatPrice, storeAction, type StoreItem } from "@/lib/store";

/**
 * ECO6 — فروشگاه پنل (RTL). کاتالوگ + checkout + تحویل.
 *
 * جریان صادقانه است و UI هم صادق می‌ماند:
 *  ۱. خرید ⇒ `POST /v1/market/catalog/{id}/checkout` سفارش pending می‌سازد.
 *  ۲. پرداخت دستی ⇒ `POST /v1/market/orders/{id}/pay` (درگاه نیست؛ این
 *     دکمه صراحتاً «دستی» است، نه «پرداخت شد» جعلی).
 *  ۳. تحویل ⇒ دانلود واقعی ZIP از route پروکسی باینری، یک‌بار.
 *
 * هیچ `setState`/`load` در بدنهٔ render نیست (قانون React #301) — همه در
 * هندلرها. بعد از هر تغییر `router.refresh()` می‌آید تا وضعیت سرور تازه شود.
 */
export function StoreClient({ initial }: { initial: StoreItem[] }) {
  const { t } = useLang();
  const toast = useToast();
  const router = useRouter();
  const [busy, setBusy] = useState<number | null>(null);

  const tr = (key: MessageKey) => t(key);

  const placeOrder = async (item: StoreItem) => {
    if (busy !== null) return;
    setBusy(item.id);
    try {
      const order = await authed<{ id: number }>(`/v1/market/catalog/${item.id}/checkout`, { method: "POST" });
      toast(tr("store.orderPlaced"), "info");
      // اگر رایگان بود، می‌توان بی‌درنگ تأیید کرد؛ ولی ما پرداخت را جدا
      // نگه می‌داریم تا «صفر بودن قیمت» با «پرداخت‌شده بودن» قاطی نشود.
      if (item.price === 0) {
        await authed(`/v1/market/orders/${order.id}/pay`, { method: "POST" });
        toast(tr("store.downloaded"), "ok");
      }
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : tr("store.loadError"), "err");
    } finally {
      setBusy(null);
    }
  };

  const payOrder = async (item: StoreItem) => {
    if (busy !== null) return;
    setBusy(item.id);
    try {
      const orders = await authed<Array<{ id: number; status: string; plugin_id: number }>>(
        `/v1/market/orders`,
      );
      const pending = orders.find((o) => o.plugin_id === item.id && o.status === "pending");
      if (!pending) {
        toast(tr("store.pending"), "err");
        return;
      }
      await authed(`/v1/market/orders/${pending.id}/pay`, { method: "POST" });
      toast(tr("store.markPaid"), "ok");
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : tr("store.loadError"), "err");
    } finally {
      setBusy(null);
    }
  };

  const download = async (item: StoreItem) => {
    if (busy !== null) return;
    setBusy(item.id);
    try {
      const res = await fetch(`/api/store/download/${item.id}`, { cache: "no-store" });
      if (!res.ok) {
        const j = await res.json().catch(() => ({}));
        toast(j?.message ?? tr("store.deliveredOnce"), "err");
        router.refresh();
        return;
      }
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `${item.slug}-${item.version}.zip`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
      toast(tr("store.downloaded"), "ok");
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : tr("store.loadError"), "err");
    } finally {
      setBusy(null);
    }
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
                    <code style={{ fontSize: 12 }}>v{item.version}</code>
                  </div>
                  <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
                    {item.delivery.owned ? <Badge tone="green">{tr("store.owned")}</Badge> : null}
                    {item.delivery.delivered ? <Badge tone="violet">{tr("store.deliveredOnce")}</Badge> : null}
                    <b style={{ fontSize: 14 }}>{formatPrice(item.price, item.currency)}</b>
                  </div>
                </div>

                {item.description ? (
                  <p className="muted" style={{ margin: 0, fontSize: 13 }}>{item.description}</p>
                ) : null}

                <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                  {action === "already_delivered" ? (
                    <span className="muted" style={{ fontSize: 12.5 }}>{tr("store.deliveredOnce")}</span>
                  ) : null}

                  {action === "download" ? (
                    <button className="btn btn-primary btn-sm" disabled={busy === item.id} onClick={() => download(item)}>
                      {tr("store.download")}
                    </button>
                  ) : null}

                  {action === "buy" || action === "get" ? (
                    <button className="btn btn-primary btn-sm" disabled={busy === item.id} onClick={() => placeOrder(item)}>
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
