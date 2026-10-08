"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { authed } from "@/lib/auth";
import { useLang } from "@/lib/i18n";
import { relativeFaFine } from "@/lib/fa";
import { safeActionHref, type InboxItem, type InboxResponse } from "@/lib/notifications";
import { Alert, Badge, Skeleton } from "@/components/ui/primitives";

/**
 * F4.2.F — صندوقِ اعلان.
 *
 * ## چرا این صفحه و نه فقط popoverِ هدر
 *
 * popover چهار ردیف آخر را نشان می‌دهد و عمداً کوتاه است. صندوقِ کامل جایی است
 * که کاربر می‌رود تا بفهمد «آن اعلانِ مالیِ دیروز چه شد» — و جایی که لینک‌های
 * اقدام (که از کاتالوگ می‌آیند) در مقیاسِ کامل معنا پیدا می‌کنند.
 *
 * ## ساختارِ رندرِ امن
 *
 * `title` و `body` **هرگز** HTML نیستند: بک‌اند از `Catalog::plain()` می‌گیرد که
 * `strip_tags` می‌کند، و اینجا هم به عنوانِ متنِ ری‌اکت رندر می‌شوند. هیچ
 * `dangerouslySetInnerHTML` اینجا نیست و نباید اضافه شود.
 */

const SEVERITY_DOT: Record<InboxItem["severity"], string> = {
  info: "var(--info)",
  warning: "var(--warning)",
  critical: "var(--danger)",
};

export function NotificationInbox() {
  const { t } = useLang();
  const [items, setItems] = useState<InboxItem[] | null>(null);
  const [unread, setUnread] = useState(0);
  const [error, setError] = useState<string | null>(null);

  // ⚠️ فقط `useEffect`. فراخوانی در بدنهٔ رندر = React #301 (این خطایی است که
  // دقیقاً وقتی رخ می‌دهد که هیچ خطای دیگری نیست و کل صفحه می‌میرد).
  const load = useCallback(async () => {
    try {
      const json = await authed<InboxResponse>("/v1/admin/notification-inbox?per_page=50");
      setItems(Array.isArray(json?.items) ? json.items : []);
      setUnread(typeof json?.unread === "number" ? json.unread : 0);
      setError(null);
    } catch (e) {
      setError(e instanceof Error ? e.message : t("inbox.loadError"));
    }
  }, [t]);

  useEffect(() => {
    void load();
  }, [load]);

  const markAll = useCallback(async () => {
    try {
      await authed("/v1/admin/notifications/read-all", { method: "POST" });
      await load();
    } catch {
      // وضعیت بصری نباید عوض شود؛ `load()` بعدی خودش درستش می‌کند.
    }
  }, [load]);

  return (
    <div>
      <div className="bell-pop-head" style={{ padding: 0, marginBlockEnd: 12, border: 0 }}>
        <span className="muted">
          {unread > 0 ? t("inbox.unreadCount", { n: new Intl.NumberFormat("fa-IR").format(unread) }) : t("bell.allRead")}
        </span>
        <div className="overlay-head-spacer" />
        {unread > 0 ? (
          <button type="button" className="btn btn-ghost btn-sm" onClick={() => void markAll()}>
            {t("bell.markRead")}
          </button>
        ) : null}
      </div>

      {error ? <Alert tone="red">{error}</Alert> : null}

      {!error && items === null ? <Skeleton lines={4} /> : null}

      {!error && items !== null && items.length === 0 ? (
        <div className="card card-pad">
          <div className="empty">{t("inbox.empty")}</div>
        </div>
      ) : null}

      {items && items.length > 0 ? (
        <div style={{ display: "grid", gap: 8 }}>
          {items.map((n) => {
            const href = safeActionHref(n.action_href);
            return (
              <div key={n.id} className="card card-pad" style={{ opacity: n.read_at ? 0.7 : 1 }}>
                <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                  <span className="dot" style={{ background: SEVERITY_DOT[n.severity] }} aria-hidden />
                  <b>{n.title}</b>
                  <div style={{ flex: 1 }} />
                  {n.catalog_key ? (
                    // `Badge` پراپرتیِ `title` ندارد ⇒ عنوانِ کامل روی پوشش.
                    <span title={n.catalog_key}>
                      <Badge tone="gray">{n.catalog_key}</Badge>
                    </span>
                  ) : null}
                </div>
                {n.body ? (
                  <p className="muted" style={{ margin: "6px 0 0", fontSize: 13 }}>{n.body}</p>
                ) : null}
                <div style={{ display: "flex", gap: 10, alignItems: "center", marginBlockStart: 8, fontSize: 12 }} className="muted">
                  {n.created_at ? <span>{relativeFaFine(n.created_at)}</span> : null}
                  <div style={{ flex: 1 }} />
                  {/*
                    * لینکِ اقدام: مقدار از کاتالوگ و از allowlist گذشته. `<a>` نه
                    * `router.push` — هم مسیرِ واقعی در history می‌ماند، هم اگر
                    * بعداً به لینکِ بیرونی نیاز شد لازم نیست این فایل عوض شود.
                    */}
                  {href ? (
                    <a className="btn btn-ghost btn-sm" href={href}>
                      {n.action_label ?? t("bell.openInbox")}
                    </a>
                  ) : null}
                </div>
              </div>
            );
          })}
        </div>
      ) : null}
    </div>
  );
}

/** تب‌های لینک‌محور — با `Tabs` هسته یکی است، فقط برای این صفحه. */
export function NotificationsTabs({ active }: { active: "inbox" | "preferences" }) {
  const { t } = useLang();
  return (
    <nav className="tabs" aria-label={t("inbox.title")}>
      <Link href="/admin/notifications" className={active === "inbox" ? "on" : ""} aria-current={active === "inbox" ? "page" : undefined}>
        {t("inbox.inbox")}
      </Link>
      <Link href="/admin/notifications/preferences" className={active === "preferences" ? "on" : ""} aria-current={active === "preferences" ? "page" : undefined}>
        {t("inbox.preferences")}
      </Link>
    </nav>
  );
}
