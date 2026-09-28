"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { Drawer } from "@/components/ui/Overlays";
import { Alert, Badge, EmptyState, Skeleton } from "@/components/ui/primitives";
import { authed } from "@/lib/auth";

/**
 * K5.8 — زنگ اعلان در هدر + drawer.
 *
 * ## چرا polling و نه SSE
 *
 * الگوی این پروژه polling است (`PayFlow` با `setInterval` + `useRef` و پاک‌سازی
 * در `useEffect`). websocket برای اعلان پنل، هزینهٔ زیرساختی‌اش را ندارد: یک
 * اتصال دائمی به‌ازای هر کاربر باز، در حالی که اعلان‌ها کم‌تعداد و کم‌اهمیت‌اند.
 *
 * فاصله ۶۰ ثانیه است نه ۵ ثانیه: اعلان پنل چیزی نیست که کاربر منتظرش بماند،
 * و polling پرتکرار یعنی هر مدیر ۲۴ بار در ساعت یک درخواست بی‌دلیل.
 *
 * ## توقف وقتی drawer باز است
 *
 * اگر کاربر دارد اعلان‌ها را می‌خواند، refresh کردن لیست زیر انگشتش یعنی
 * محتوایی که دارد می‌خواند عوض شود. پس در حالت باز، polling متوقف می‌شود.
 */

type NotificationItem = {
  id: string;
  title: string;
  body?: string | null;
  severity: "info" | "warning" | "critical";
  notification_id?: number | null;
  action_label?: string | null;
  read_at?: string | null;
  created_at?: string | null;
};

type NotificationsResponse = { items?: NotificationItem[]; unread?: number };

const POLL_MS = 60_000;

const SEVERITY_LABEL: Record<NotificationItem["severity"], string> = {
  info: "اطلاع",
  warning: "هشدار",
  critical: "بحرانی",
};

export function NotificationBell() {
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState<NotificationItem[] | null>(null);
  const [unread, setUnread] = useState(0);
  const [error, setError] = useState<string | null>(null);
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);

  const load = useCallback(async () => {
    try {
      const json = await authed<NotificationsResponse>("/v1/admin/notifications");
      setItems(Array.isArray(json?.items) ? json.items : []);
      setUnread(typeof json?.unread === "number" ? json.unread : 0);
      setError(null);
    } catch (e) {
      // خطای شبکه نباید زنگ را قرمز کند — اعلان یک قابلیت جانبی است، نه
      // چیزی که کار با آن متوقف شود.
      setError(e instanceof Error ? e.message : "بارگذاری اعلان‌ها انجام نشد.");
    }
  }, []);

  // بارگذاری اولیه در `useEffect`، نه شرط در بدنهٔ رندر — قانون پروژه برای
  // جلوگیری از React #301.
  useEffect(() => {
    void load();
  }, [load]);

  // polling فقط وقتی drawer بسته است.
  useEffect(() => {
    if (open) return;

    timer.current = setInterval(() => {
      void load();
    }, POLL_MS);

    return () => {
      if (timer.current) {
        clearInterval(timer.current);
        timer.current = null;
      }
    };
  }, [open, load]);

  // در لحظهٔ باز شدن، لیست تازه می‌آید تا کاربر چیزی نبیند که یک دقیقه پیش
  // در حال فرستادن بوده.
  useEffect(() => {
    if (open) void load();
  }, [open, load]);

  const markAll = useCallback(async () => {
    try {
      await authed("/v1/admin/notifications/read-all", { method: "POST" });
      await load();
    } catch {
      // اگر علامت‌زدن ناموفق بود، وضعیت بصری نباید عوض شود — `load()` بعدی
      // خودش درستش می‌کند.
    }
  }, [load]);

  return (
    <>
      <button
        type="button"
        className="icon-btn"
        onClick={() => setOpen(true)}
        aria-label={unread > 0 ? `اعلان‌ها (${unread} خوانده‌نشده)` : "اعلان‌ها"}
        title="اعلان‌ها"
      >
        <span aria-hidden>🔔</span>
        {unread > 0 ? (
          // عدد گرد نشود: ۱۰۰۰ باید «۱۰۰۰+» باشد نه «۱٬۰۰۰» که جای بیشتری
          // می‌گیرد.
          <span className="bell-count">{unread > 999 ? "۱۰۰۰+" : new Intl.NumberFormat("fa-IR").format(unread)}</span>
        ) : null}
      </button>

      {open ? (
        <Drawer title="اعلان‌ها" onClose={() => setOpen(false)}>
          <div style={{ display: "flex", alignItems: "center", gap: 8, padding: "10px 12px 0" }}>
            <span className="muted">
              {unread > 0 ? `${unread.toLocaleString("fa-IR")} خوانده‌نشده` : "همه خوانده شده"}
            </span>
            <div style={{ flex: 1 }} />
            {unread > 0 ? (
              <button className="btn btn-ghost btn-sm" onClick={() => void markAll()}>
                خواندم
              </button>
            ) : null}
          </div>

          {error ? (
            <div style={{ padding: 12 }}>
              <Alert tone="amber">{error}</Alert>
            </div>
          ) : null}

          {!error && items === null ? (
            <div style={{ padding: 12 }}>
              <Skeleton />
            </div>
          ) : null}

          {!error && items !== null && items.length === 0 ? (
            <EmptyState title="اعلانی نداری" hint="اعلان‌های افزونه‌ها و تیکت‌ها اینجا می‌آیند." />
          ) : null}

          <div style={{ display: "grid", gap: 8, padding: 12 }}>
            {(items ?? []).map((n) => {
              return (
                <div key={n.id} className="card" style={{ opacity: n.read_at ? 0.7 : 1 }}>
                  <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                    <Badge
                      tone={
                        n.severity === "critical" ? "red" : n.severity === "warning" ? "amber" : "gray"
                      }
                    >
                      {SEVERITY_LABEL[n.severity]}
                    </Badge>
                    <b>{n.title}</b>
                  </div>
                  {n.body ? (
                    <p className="muted" style={{ margin: "6px 0 0" }}>
                      {n.body}
                    </p>
                  ) : null}
                  {/* ⚠️ عمداً هیچ دکمهٔ «مشاهده»ای نیست.
                      *
                      * `notification_id` فقط وقتی به مسیر تبدیل می‌شود که آن
                      * مسیر در `pageRegistry()` ثبت شده باشد (همان قاعدهٔ
                      * K6.2). این کامپوننت رجیستری را ندارد، پس ساختن دکمه با
                      * URL حدسی دقیقاً همان کاری است که این سرویس ساخته شد تا
                      * جلویش را بگیرد. مسیر درست: بازکردن drawer ابزارها که
                      * رجیستری را دارد و خودش تبدیل می‌کند. */}
                </div>
              );
            })}
          </div>
        </Drawer>
      ) : null}
    </>
  );
}
