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

import { useEffect, useState } from "react";
import Link from "next/link";
import { authed } from "@/lib/auth";
import type { DashboardStats, DashboardAlert } from "@/lib/api";
import { deriveSubscriptionView } from "@/lib/dashboard-subscription";
import { faNum, jalali, jalaliDate, relativeFa } from "@/lib/fa";
import { Alert, StatCard, Skeleton, Badge } from "@/components/ui/primitives";
import { useToast } from "@/components/ui/Toast";
import type { Invoice, PageItem, Ticket } from "@/lib/domain";

type Activity = { kind: string; title: string; at?: string | null };

/** تایم‌لاین «آخرین فعالیت‌ها» از APIهای موجود (صفحات/فاکتورها/تیکت‌ها) — endpoint جدید نیست. */
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
    const inv = await authed<Invoice[] | { data: Invoice[] }>("/v1/admin/subscription/invoices?per_page=3");
    const arr = Array.isArray(inv) ? inv : (inv.data ?? []);
    for (const i of arr.slice(0, 2)) {
      items.push({ kind: "invoice", title: `فاکتور #${faNum(i.id)} — ${faNum(i.amount)} تومان (${i.status === "paid" ? "پرداخت‌شده" : "در انتظار"})`, at: i.created_at });
    }
  } catch { /* نادیده */ }
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
  const [stats, setStats] = useState<DashboardStats | null>(null);
  const [alerts, setAlerts] = useState<DashboardAlert[] | null>(null);
  const [activity, setActivity] = useState<Activity[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    (async () => {
      try {
        const [s, a] = await Promise.all([
          authed<DashboardStats>("/v1/admin/dashboard/stats"),
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

  // ⚠️ `subscription` می‌تواند `null` باشد و یعنی «افزونهٔ اشتراک نصب نیست» —
  // نه خطا. این را خودِ `DashboardController` مستند کرده. سوپرادمین همیشه
  // همین حالت را دارد، پس خواندنِ بدون guard کل صفحه را می‌کشت
  // («Cannot read properties of null (reading 'status')»).
  const sub = stats.subscription;
  const subView = deriveSubscriptionView(sub);
  const suspended = subView.suspended;
  const recent = stats.recent ?? { pages_new_7d: 0, media_new_7d: 0, tickets_new_7d: 0 };
  const deltaOf = (n: number) => (n > 0 ? `+${faNum(n)} در ۷ روز` : "—");
  const periodPct = subView.periodPct;
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

      <div className="grid c4" style={{ marginBlockEnd: 16 }}>
        <StatCard icon="▤" value={faNum(stats.pages_count)} label="صفحات" delta={deltaOf(recent.pages_new_7d)} up={recent.pages_new_7d > 0} />
        <StatCard icon="🗎" value={faNum(stats.media_count)} label="فایل‌ها" delta={deltaOf(recent.media_new_7d)} up={recent.media_new_7d > 0} />
        <StatCard icon="✉" value={faNum(stats.open_tickets)} label="تیکت‌های باز" delta={recent.tickets_new_7d > 0 ? `${faNum(recent.tickets_new_7d)} جدید در ۷ روز` : "—"} up={false} />
        {sub ? <StatCard icon="◈" value={sub.tier} label="پلن اشتراک" delta={sub.due_at ? relativeFa(sub.due_at) : "—"} up={!suspended} /> : null}
      </div>

      <div className="grid c2" style={{ alignItems: "start" }}>
        {/* وقتی کارتِ اشتراک پنهان است، میانبرها تمام عرض را می‌گیرد تا
            نیمهٔ خالیِ ستون دوم جا نماند. */}
        <div className="card card-pad" style={sub ? undefined : { gridColumn: "1 / -1" }}>
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
                    <span aria-hidden style={{ color: "var(--primary)" }}>{a.kind === "page" ? "▤" : a.kind === "invoice" ? "🧾" : "✉"}</span>
                    <div><b>{a.title}</b><div style={{ fontSize: 11.5, color: "var(--text-muted)" }}>{a.at ? `${jalali(a.at)} — ${relativeFa(a.at)}` : "—"}</div></div>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
        {sub ? (
        <div className="card card-pad">
          <div className="card-title">وضعیت اشتراک {suspended ? <Badge tone="red">معلق</Badge> : null}</div>
          <div className="kv"><span>وضعیت</span><span>{sub.status}</span></div>
          <div className="kv"><span>سررسید</span><span>{jalaliDate(sub.due_at)} ({relativeFa(sub.due_at)})</span></div>
          <div className="kv"><span>روز باقی‌مانده</span><span>{faNum(sub.days_remaining)}</span></div>
          {/* ⭐ `aria-valuetext` لازم است، نه تزئینی.
              `aria-valuenow="7"` برای صفحه‌خوان یعنی «عدد ۷» که هیچ ربطی
              به «۷ درصد» ندارد. متنِ صریح می‌گوید چه معنایی دارد — و متنِ
              مجاور هم در DOM هست ولی صفحه‌خوان آن را به نوارِ پیشرفت نسبت
              نمی‌دهد. */}
          <div className="progress" style={{ marginBlockStart: 10 }} role="progressbar" aria-valuenow={periodPct} aria-valuemin={0} aria-valuemax={100} aria-valuetext={`${faNum(periodPct)}٪ از دوره گذشته`} aria-label="پیشرفت دوره اشتراک">
            <i style={{ inlineSize: `${periodPct}%` }} />
          </div>
          <div style={{ fontSize: 11.5, color: "var(--text-muted)", marginBlock: "6px 10px" }}>{faNum(periodPct)}٪ از دوره گذشته</div>
          <Link className="btn btn-soft btn-sm btn-block" href="/admin/subscription">مدیریت اشتراک</Link>
        </div>
        ) : null}
      </div>
    </div>
  );
}
