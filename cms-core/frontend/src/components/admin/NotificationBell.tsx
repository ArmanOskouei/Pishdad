"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { authed } from "@/lib/auth";
import { useLang } from "@/lib/i18n";
import { relativeFaFine } from "@/lib/fa";
import { safeActionHref, type InboxItem, type InboxResponse } from "@/lib/notifications";

/**
 * F4.2.F — زنگ اعلان در هدر پنل + popover.
 *
 * جایگزینِ زنگِ `K5.8`: آن یکی به `/v1/admin/notifications` می‌رفت که سرویسِ
 * افزونه است و هیچ‌وقت عنوانِ قابل‌نمایش نداشت. این یکی به
 * `/v1/admin/notification-inbox` می‌رود که عنوان و بدنه را از **کاتالوگ**
 * می‌سازد و `action_href` را از allowlistِ رد کرده تحویل می‌دهد.
 *
 * ## ⭐ چرا SVG درون‌خطی و نه ایموجی
 *
 * نسخهٔ قبلی `🔔` بود. ایموجی روی ویندوز/اندروید/لینوکس سه شکلِ متفاوت
 * می‌گیرد، با `font-family` پروژه (Vazirmatn) هم می‌جنگد و در فهرستِ
 * آیکون‌های طراحی‌شده نبود. SVG درون‌خطی همیشه یک شکل است و `currentColor` را
 * می‌گیرد، پس با حالت روشن/تیره و با `aria-hidden` درست کار می‌کند.
 *
 * ## چرا polling و نه SSE
 *
 * الگوی این پروژه polling است. websocket برای اعلانِ پنل، هزینهٔ زیرساختی‌اش
 * را ندارد: یک اتصال دائمی به‌ازای هر کاربر باز، در حالی که اعلان‌ها کم‌تعدادند.
 *
 * فاصله ۶۰ ثانیه است نه ۵ ثانیه، و **فقط وقتی popover بسته است**. اگر کاربر
 * دارد اعلان‌ها را می‌خواند، refresh یعنی محتوایی که زیر انگشتش می‌خواند بی‌صدا
 * عوض شود — و بدتر، اگر صفحه عوض شود ممکن است ردیفی که می‌خواند ناپدید شود.
 *
 * ## لینکِ اقدام: فقط `action_href`، فقط `<a>`
 *
 * سه قاعده، و هر سه لازم‌اند:
 *  1. مقدار فقط از `safeActionHref()` رد می‌گردد (مسیرِ داخلی، نه URLِ آزاد).
 *  2. `<a href>` **نه** `router.push` — چون popover باید بسته شود و مرورگر
 *     مسیرِ واقعی را در history نگه دارد.
 *  3. متن و بدنه **هرگز** HTML نیستند (بک‌اند `strip_tags` می‌کند) و
 *     `dangerouslySetInnerHTML` در این فایل وجود ندارد.
 */

const POLL_MS = 60_000;

const SEVERITY_DOT: Record<InboxItem["severity"], string> = {
  info: "var(--info)",
  warning: "var(--warning)",
  critical: "var(--danger)",
};

export function NotificationBell() {
  const { t } = useLang();
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState<InboxItem[] | null>(null);
  const [unread, setUnread] = useState(0);
  const [error, setError] = useState<string | null>(null);
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);
  const popRef = useRef<HTMLDivElement | null>(null);

  const load = useCallback(async () => {
    try {
      const json = await authed<InboxResponse>("/v1/admin/notification-inbox");
      setItems(Array.isArray(json?.items) ? json.items : []);
      setUnread(typeof json?.unread === "number" ? json.unread : 0);
      setError(null);
    } catch (e) {
      // خطای شبکه نباید زنگ را قرمز کند — اعلان قابلیتِ جانبی است، نه چیزی که
      // کار با آن متوقف شود. مقدارِ قبلی حفظ می‌شود.
      setError(e instanceof Error ? e.message : t("bell.loadError"));
    }
  }, [t]);

  // بارگذاری اولیه در `useEffect`، نه شرط در بدنهٔ رندر — قانون پروژه برای
  // جلوگیری از React #301 (Too many re-renders).
  useEffect(() => {
    void load();
  }, [load]);

  // polling فقط وقتی popover بسته است.
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

  // در لحظهٔ باز شدن، لیست تازه می‌آید تا کاربر چیزی نبیند که یک دقیقه پیش در
  // حال فرستادن بوده.
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

  /**
   * کلیک بیرون popover را می‌بندد. `pointerdown` نه `click`: با `click`، کشیدن
   * متن (text selection) به بیرون popover آن را می‌بندد و متنِ انتخاب‌شده از بین
   * می‌رود.
   */
  useEffect(() => {
    if (!open) return;
    const onDown = (e: PointerEvent) => {
      const root = popRef.current;
      if (root && e.target instanceof Node && !root.contains(e.target)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    document.addEventListener("pointerdown", onDown);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("pointerdown", onDown);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);

  const label = unread > 0 ? t("bell.unread", { n: unread }) : t("bell.label");

  return (
    <div className="bell-wrap" ref={popRef}>
      <button
        type="button"
        className="icon-btn"
        onClick={() => setOpen((o) => !o)}
        aria-label={label}
        title={t("bell.label")}
        aria-expanded={open}
        aria-haspopup="dialog"
      >
        <svg className="bell-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
          <path d="M18 8a6 6 0 1 0-12 0c0 6-2 7-2 7h16s-2-1-2-7" />
          <path d="M13.7 20a2 2 0 0 1-3.4 0" />
        </svg>
        {/*
          * نشانگر فقط وقتی `unread` صفر نیست — و رنگش از توکن می‌آید نه مقدار
          * ثابت. عددِ گرد نشود: ۱۰۰۰ باید «۱۰۰۰+» باشد نه «۱٬۰۰۰».
          */}
        {unread > 0 ? (
          <span className="bell-count">
            <span className="dot ring" aria-hidden />
            {unread > 999 ? "۱۰۰۰+" : new Intl.NumberFormat("fa-IR").format(unread)}
          </span>
        ) : null}
      </button>

      {open ? (
        <div className="bell-pop" role="dialog" aria-label={t("bell.label")}>
          <div className="bell-pop-head">
            <b>{t("bell.label")}</b>
            <div className="overlay-head-spacer" />
            {unread > 0 ? (
              <button type="button" className="btn btn-ghost btn-sm" onClick={() => void markAll()}>
                {t("bell.markRead")}
              </button>
            ) : null}
            <button type="button" className="icon-btn" onClick={() => setOpen(false)} aria-label={t("common.close")}>✕</button>
          </div>

          {error ? <div className="bell-pop-body"><div className="alert a-amber">{error}</div></div> : null}

          {!error && items === null ? (
            <div className="bell-pop-body" aria-busy="true">
              <div className="skel" style={{ blockSize: 18 }} />
              <div className="skel" style={{ blockSize: 18, marginBlockStart: 8 }} />
              <div className="skel" style={{ blockSize: 18, marginBlockStart: 8 }} />
            </div>
          ) : null}

          {!error && items !== null && items.length === 0 ? (
            <div className="bell-pop-body">
              <div className="empty">
                <div style={{ fontSize: 15, fontWeight: 700, color: "var(--text)" }}>{t("bell.emptyTitle")}</div>
                <p style={{ margin: "6px 0 0" }}>{t("bell.emptyHint")}</p>
              </div>
            </div>
          ) : null}

          {items && items.length > 0 ? (
            <ul className="bell-pop-list" style={{ listStyle: "none", margin: 0, padding: 0 }}>
              {items.slice(0, 8).map((n) => {
                const href = safeActionHref(n.action_href);
                return (
                  <li key={n.id} className="bell-row" style={{ opacity: n.read_at ? 0.68 : 1 }}>
                    {/* نقطهٔ شدت — رنگ از توکن، پس در تم روشن/تیره یکی است. */}
                    <span className="dot" style={{ background: SEVERITY_DOT[n.severity] }} aria-hidden />
                    <div style={{ minInlineSize: 0, flex: 1 }}>
                      <div className="bell-row-title">{n.title}</div>
                      {n.body ? <div className="bell-row-body">{n.body}</div> : null}
                      <div className="bell-row-meta">
                        {n.created_at ? relativeFaFine(n.created_at) : null}
                        {href ? (
                          <a className="bell-row-cta" href={href}>
                            {n.action_label ?? t("bell.openInbox")}
                          </a>
                        ) : null}
                      </div>
                    </div>
                  </li>
                );
              })}
            </ul>
          ) : null}

          <div className="bell-pop-foot">
            {/* ⚠️ مسیرِ ثبت‌شده، نه مسیرِ ساخته. صفحهٔ صندوق یک مسیرِ ثابت و
                معلوم است (برخلاف `notification_id` که باید در رجیستری باشد). */}
            <Link href="/admin/notifications" onClick={() => setOpen(false)}>
              {t("bell.openInbox")} ←
            </Link>
          </div>
        </div>
      ) : null}
    </div>
  );
}
