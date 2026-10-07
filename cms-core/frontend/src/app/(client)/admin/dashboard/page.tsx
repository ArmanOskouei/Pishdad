"use client";

/**
 * داشبورد مشتری (تسک ۲.۲ + دسته ۱ برابری): stats/alerts واقعی — بدون هیچ mock.
 *
 * تسک K6.10: کارت «ویجت‌های پلاگین» و fetch متناظرش حذف شد. endpoint
 * `dashboard/widgets` آرایهٔ خالیِ هاردکد برمی‌گرداند (DashboardController::widgets)
 * و نقطهٔ `admin.dashboard_slot` در قرارداد `deferred`/`closed` است، پس کارت
 * همیشه EmptyState نشان می‌داد و وعدهٔ «پلاگین‌ها اینجا ویجت اضافه می‌کنند» دروغ بود.
 * نگهبانش: src/lib/dashboard-widget-card.test.ts
 */

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { authed, useAuth } from "@/lib/auth";
import type { DashboardStats, DashboardAlert } from "@/lib/api";
import {
  CHECKLIST_STEPS,
  checklistProgress,
  isChecklistComplete,
  readChecklistDismissed,
  shouldShowChecklist,
  writeChecklistDismissed,
} from "@/lib/onboarding";
import { faNum, jalali, relativeFa } from "@/lib/fa";
import { Alert, StatCard, Skeleton } from "@/components/ui/primitives";
import { useToast } from "@/components/ui/Toast";
import { TopPagesCard, type TopPagesPayload } from "./TopPagesCard";
import type { PageItem, Ticket } from "@/lib/domain";

/**
 * WF-M12 — `top_pages_7d` هنوز در تایپِ مشترکِ `DashboardStats` نیست (آن فایل
 * قراردادِ همهٔ تسک‌هاست)، پس اینجا افزوده می‌شود تا تایپ یک‌جا بماند.
 * `undefined`/`null` یعنی «پرمیشن تحلیل ندارد» ⇒ کارت پنهان.
 */
type DashboardStatsWithTopPages = DashboardStats & { top_pages_7d?: TopPagesPayload | null };

type Activity = { kind: string; title: string; at?: string | null };

/** localStorage با محافظِ عدم‌دسترسی (حالت خصوصی/SSR) — مثلِ خودِ Onboarding. */
function browserStorage(): Storage | null {
  try {
    return typeof window === "undefined" ? null : window.localStorage;
  } catch {
    return null;
  }
}

/** تایم‌لاین «آخرین فعالیت‌ها» از APIهای موجود (صفحات/تیکت‌ها) — endpoint جدید نیست. */
async function loadActivity(): Promise<Activity[]> {
  const items: Activity[] = [];
  try {
    const pages = await authed<PageItem[] | { data: PageItem[] }>("/v1/admin/pages?per_page=3");
    const arr = Array.isArray(pages) ? pages : (pages.data ?? []);
    for (const p of arr.slice(0, 3)) {
      items.push({ kind: "page", title: `صفحه «${p.title}» (${p.status === "published" ? "منتشرشده" : "پیش‌نویس"})`, at: p.updated_at ?? p.created_at });
    }
  } catch { /* تایم‌لاین با بقیه منابع ساخته می‌شود */ }
  try {
    const t = await authed<{ data: Ticket[] } | Ticket[]>("/v1/admin/tickets?per_page=3");
    const arr = Array.isArray(t) ? t : (t.data ?? []);
    for (const x of arr.slice(0, 2)) {
      items.push({ kind: "ticket", title: `تیکت #${faNum(x.id)} «${x.subject}»`, at: x.updated_at ?? x.created_at });
    }
  } catch { /* نادیده */ }
  return items
    .sort((a, b) => String(b.at ?? "").localeCompare(String(a.at ?? "")))
    .slice(0, 6);
}

export default function DashboardPage() {
  const toast = useToast();
  const { user, loading: authLoading } = useAuth();
  const [stats, setStats] = useState<DashboardStatsWithTopPages | null>(null);
  const [alerts, setAlerts] = useState<DashboardAlert[] | null>(null);
  const [activity, setActivity] = useState<Activity[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [checklistDismissed, setChecklistDismissed] = useState(false);

  // WF-H21 — علامتِ پنهان‌کردنِ چک‌لیست per-user است؛ خواندنش در useEffect
  // (نه در render) تا قاعدهٔ ضد React #301 رعایت شود.
  useEffect(() => {
    setChecklistDismissed(readChecklistDismissed(browserStorage(), user?.id ?? null));
  }, [user?.id]);

  const dismissChecklist = useCallback(() => {
    writeChecklistDismissed(browserStorage(), user?.id ?? null);
    setChecklistDismissed(true);
  }, [user?.id]);

  useEffect(() => {
    (async () => {
      try {
        const [s, a] = await Promise.all([
          authed<DashboardStatsWithTopPages>("/v1/admin/dashboard/stats"),
          authed<DashboardAlert[]>("/v1/admin/dashboard/alerts"),
        ]);
        setStats(s); setAlerts(a);
        setActivity(await loadActivity());
      } catch (e) {
        const msg = e instanceof Error ? e.message : "خطا در بارگذاری داشبورد.";
        setError(msg); toast(msg, "err");
      }
    })();
  }, [toast]);

  if (error && !stats) {
    return (
      <div>
        <div className="page-head"><div><h1>داشبورد</h1><p>نمای کلی سایت</p></div></div>
        <Alert tone="red">{error} <button className="btn btn-ghost btn-sm" onClick={() => window.location.reload()}>تلاش مجدد</button></Alert>
      </div>
    );
  }
  if (!stats || !alerts) {
    return (
      <div>
        <div className="page-head"><div><h1>داشبورد</h1><p>در حال بارگذاری…</p></div></div>
        <div className="grid c4"><Skeleton lines={4} /><Skeleton lines={4} /><Skeleton lines={4} /><Skeleton lines={4} /></div>
        <div style={{ marginBlockStart: 16 }}><Skeleton lines={3} /></div>
      </div>
    );
  }

  const recent = stats.recent ?? { pages_new_7d: 0, media_new_7d: 0, tickets_new_7d: 0 };
  const deltaOf = (n: number) => (n > 0 ? `+${faNum(n)} در ۷ روز` : "—");

  // WF-H21 — چک‌لیست راه‌اندازی: وضعیت از سرور (`stats.checklist`)، نمایش/پنهان
  // در کلاینت. با کامل‌شدنِ هر ۵ گام خودکار پنهان می‌شود؛ پنهان‌کردنِ دستی هم
  // per-user در localStorage می‌ماند.
  const checklist = stats.checklist;
  const progress = checklist ? checklistProgress(checklist) : null;
  const showChecklist =
    checklist !== undefined &&
    shouldShowChecklist({
      loading: authLoading,
      userId: user?.id ?? null,
      complete: isChecklistComplete(checklist),
      dismissed: checklistDismissed,
    });

  // WF-M12 — کارتِ هستهٔ «صفحات پربازدید ۷ روز». `null` یعنی «پرمیشنِ تحلیل
  // ندارد» و کارت پنهان می‌شود؛ `items: []` یعنی «مجاز ولی بی‌بازدید» و خودِ
  // کارت حالتِ خالی نشان می‌دهد.
  const topPages = stats.top_pages_7d ?? null;

  return (
    <div>
      <div className="page-head">
        <div><h1>داشبورد</h1><p>نمای کلی سایت</p></div>
        <div style={{ flex: 1 }} />
        <a className="btn btn-ghost btn-sm" href="/" target="_blank" rel="noreferrer">👁 مشاهده سایت</a>
        <Link className="btn btn-primary btn-sm" href="/admin/pages">＋ صفحه جدید</Link>
      </div>

      {alerts.map((a) => (
        <Alert key={a.type} tone={a.severity === "critical" ? "red" : a.severity === "warning" ? "amber" : "blue"}>
          <span>{a.message} <a href={a.action} style={{ color: "var(--primary)", fontWeight: 700 }}>مشاهده ←</a></span>
        </Alert>
      ))}

      {showChecklist && progress ? (
        <div className="card card-pad" style={{ marginBlockEnd: 16 }}>
          <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
            <div className="card-title" style={{ margin: 0 }}>راه‌اندازی سایت</div>
            <div style={{ flex: 1 }} />
            <button type="button" className="btn btn-ghost btn-sm" onClick={dismissChecklist}>پنهان کن</button>
          </div>
          <p className="card-sub">
            {faNum(progress.completed)} از {faNum(progress.total)} گام انجام شده — با تکمیل همه، این راهنما خودکار پنهان می‌شود.
          </p>
          <div
            className="progress"
            role="progressbar"
            aria-valuenow={progress.percent}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuetext={`${faNum(progress.percent)}٪ راه‌اندازی کامل شده`}
            aria-label="پیشرفت راه‌اندازی سایت"
          >
            <i style={{ inlineSize: `${progress.percent}%` }} />
          </div>
          <ul style={{ listStyle: "none", margin: "12px 0 0", padding: 0 }}>
            {CHECKLIST_STEPS.map((s, i) => {
              const done = checklist[s.key];
              return (
                <li
                  key={s.key}
                  style={{
                    display: "flex",
                    gap: 10,
                    alignItems: "flex-start",
                    paddingBlock: 8,
                    borderBlockEnd: i < CHECKLIST_STEPS.length - 1 ? "1px dashed var(--border)" : "none",
                  }}
                >
                  <span aria-hidden style={{ color: done ? "var(--primary)" : "var(--text-muted)", fontWeight: 700 }}>{done ? "✓" : "○"}</span>
                  <div style={{ flex: 1 }}>
                    <div style={{ fontWeight: done ? 700 : 400, textDecoration: done ? "line-through" : "none", color: done ? "var(--text-muted)" : "var(--text)" }}>{s.label}</div>
                    {!done ? <div style={{ fontSize: 12, color: "var(--text-muted)" }}>{s.hint}</div> : null}
                  </div>
                  {!done ? <Link className="btn btn-ghost btn-sm" href={s.href}>انجام بده</Link> : null}
                </li>
              );
            })}
          </ul>
        </div>
      ) : null}

      <div className="grid c3" style={{ marginBlockEnd: 16 }}>
        <StatCard icon="▤" value={faNum(stats.pages_count)} label="صفحات" delta={deltaOf(recent.pages_new_7d)} up={recent.pages_new_7d > 0} />
        <StatCard icon="🗎" value={faNum(stats.media_count)} label="فایل‌ها" delta={deltaOf(recent.media_new_7d)} up={recent.media_new_7d > 0} />
        <StatCard icon="✉" value={faNum(stats.open_tickets)} label="تیکت‌های باز" delta={recent.tickets_new_7d > 0 ? `${faNum(recent.tickets_new_7d)} جدید در ۷ روز` : "—"} up={false} />
      </div>

      {topPages ? <TopPagesCard widget={topPages} /> : null}

      <div style={{ alignItems: "start" }}>
        <div className="card card-pad">
          <div className="card-title">میانبرها</div>
          <p className="card-sub">کارهای پرتکرار امروز</p>
          <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
            <Link className="btn btn-soft" href="/admin/pages">＋ صفحه جدید</Link>
            <Link className="btn btn-ghost" href="/admin/media">آپلود فایل</Link>
            <Link className="btn btn-ghost" href="/admin/tickets">پاسخ تیکت{stats.open_tickets > 0 ? ` (${faNum(stats.open_tickets)})` : ""}</Link>
            <a className="btn btn-ghost" href="/" target="_blank" rel="noreferrer">مشاهده سایت</a>
          </div>
          <div style={{ marginBlockStart: 16 }}>
            <div className="card-title">آخرین فعالیت‌ها</div>
            {activity === null ? (
              <p style={{ fontSize: 12.5, color: "var(--text-muted)" }}>در حال بارگذاری…</p>
            ) : activity.length === 0 ? (
              <p style={{ fontSize: 12.5, color: "var(--text-muted)" }}>فعالیت تازه‌ای نیست.</p>
            ) : (
              <ul style={{ listStyle: "none", margin: "10px 0 0", padding: 0 }}>
                {activity.map((a, i) => (
                  <li key={i} style={{ display: "flex", gap: 10, paddingBlock: 8, borderBlockEnd: i < activity.length - 1 ? "1px dashed var(--border)" : "none", fontSize: 13 }}>
                    <span aria-hidden style={{ color: "var(--primary)" }}>{a.kind === "page" ? "▤" : "✉"}</span>
                    <div><b>{a.title}</b><div style={{ fontSize: 11.5, color: "var(--text-muted)" }}>{a.at ? `${jalali(a.at)} — ${relativeFa(a.at)}` : "—"}</div></div>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
