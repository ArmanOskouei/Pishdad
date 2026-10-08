"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useLang, type MessageKey } from "@/lib/i18n";
import { authed, authedEnvelope, ApiError } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import type { StoreItem } from "@/lib/store";

/**
 * WF-H16 — کشیدن اکشن‌های فروشگاه به یک هوک تا صفحهٔ فهرست و صفحهٔ جزئیات هر دو
 * از یک منطق استفاده کنند (بدون کپی). جریان همان ECO6/WF-C5 است: checkout ⇒
 * پرداخت دستی ⇒ تحویل/نصب. هیچ `setState`/fetch در render نیست؛ همه در هندلرها.
 */

type OrderRow = { id: number; status: string; plugin_id: number };

function orderList(raw: unknown): OrderRow[] {
  if (Array.isArray(raw)) return raw as OrderRow[];
  const data = (raw as { data?: unknown } | null)?.data;
  return Array.isArray(data) ? (data as OrderRow[]) : [];
}

export function useStoreActions() {
  const { t } = useLang();
  const toast = useToast();
  const router = useRouter();
  const [busy, setBusy] = useState<number | null>(null);

  const tr = (key: MessageKey) => t(key);

  const ensurePendingOrder = async (item: StoreItem): Promise<number> => {
    const raw = await authed<unknown>(`/v1/market/orders`);
    const pending = orderList(raw).find((o) => o.plugin_id === item.id && o.status === "pending");
    if (pending) return pending.id;
    const order = await authed<{ id: number }>(`/v1/market/catalog/${item.id}/checkout`, { method: "POST" });
    return order.id;
  };

  const placeOrder = async (item: StoreItem) => {
    if (busy !== null) return;
    setBusy(item.id);
    try {
      const order = await authed<{ id: number }>(`/v1/market/catalog/${item.id}/checkout`, { method: "POST" });
      toast(tr("store.orderPlaced"), "info");
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
      const orderId = await ensurePendingOrder(item);
      await authed(`/v1/market/orders/${orderId}/pay`, { method: "POST" });
      toast(tr("store.markPaid"), "ok");
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : tr("store.loadError"), "err");
    } finally {
      setBusy(null);
    }
  };

  const installItem = async (item: StoreItem) => {
    if (busy !== null) return;
    setBusy(item.id);
    try {
      if (!item.delivery.owned && item.price === 0) {
        const orderId = await ensurePendingOrder(item);
        await authed(`/v1/market/orders/${orderId}/pay`, { method: "POST" });
      }

      await authed(`/v1/market/plugins/${item.id}/install`, { method: "POST" });

      const res = await authedEnvelope(`/v1/admin/plugins/${item.id}/activate`, { method: "POST" });
      if (res.warning) {
        toast(`نصب شد و فعال شد — ولی فیلد «hooks» در مانیفست بی‌اثر است.`, "info");
      } else {
        toast("نصب و فعال‌سازی انجام شد.", "ok");
      }
      router.refresh();
    } catch (e) {
      if (e instanceof ApiError && e.status === 402) {
        try {
          await ensurePendingOrder(item);
          toast("این افزونه پولی است. سفارش ثبت شد؛ برای نصب، پرداخت را دستی تأیید کنید.", "info");
        } catch (e2) {
          toast(e2 instanceof Error ? e2.message : "ثبت سفارش ناموفق بود.", "err");
        }
        router.refresh();
        return;
      }
      const msg = e instanceof Error ? e.message : "نصب افزونه ناموفق بود.";
      toast(msg, "err");
      router.refresh();
    } finally {
      setBusy(null);
    }
  };

  /** WF-H18 — ثبت امتیاز (۱..۵) و نظر؛ فقط برای خریدارِ تأییدشده (گیتِ سرور). */
  const submitReview = async (item: StoreItem, rating: number, comment: string) => {
    if (busy !== null) return;
    setBusy(item.id);
    try {
      await authed(`/v1/market/catalog/${item.id}/reviews`, {
        method: "POST",
        body: { rating, comment: comment === "" ? null : comment },
      });
      toast(tr("store.reviewSubmitted"), "ok");
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

  return { busy, placeOrder, payOrder, installItem, download, submitReview };
}
