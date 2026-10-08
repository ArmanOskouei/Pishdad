"use client";

/**
 * WF-M12 — کارت «صفحات پربازدید ۷ روز» در داشبورد مشتری.
 *
 * داده از `dashboard/stats` می‌آید (همان تحلیلِ WF-H12) و **هیچ fetch اینجا
 * نیست**: کارت یک جزئی از همان پاسخی است که صفحهٔ داشبورد قبلاً می‌گیرد.
 *
 * این کارت «هسته» است، نه از رجیستریِ ویجتِ افزونه‌ها (K6.10 آن رجیستری عمداً
 * خالی است). `days` را سرور می‌فرستد تا عنوانِ کارت از یک منبع بیاید و با
 * تغییرِ پنجرهٔ سرور، عنوانِ کلاینت عقب نماند.
 */

import Link from "next/link";
import { barWidth } from "@/lib/analytics";
import { faNum } from "@/lib/fa";
import { EmptyState } from "@/components/ui/primitives";

export type TopPageEntry = {
  path: string;
  page_id: number;
  title: string;
  views: number;
};

/** `null` (در تایپِ صفحهٔ داشبورد) یعنی «کاربر پرمیشن تحلیل ندارد» ⇒ کارت پنهان. */
export type TopPagesPayload = { days: number; items: TopPageEntry[] };

function maxViews(items: readonly TopPageEntry[]): number {
  return items.reduce((max, item) => (item.views > max ? item.views : max), 0);
}

export function TopPagesCard({ widget }: { widget: TopPagesPayload }) {
  const { days, items } = widget;
  const peak = maxViews(items);

  return (
    <div className="card card-pad" style={{ marginBlockEnd: 16 }}>
      <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
        <div className="card-title" style={{ margin: 0 }}>صفحات پربازدید {faNum(days)} روز</div>
        <div style={{ flex: 1 }} />
        <Link className="btn btn-ghost btn-sm" href="/admin/analytics">گزارش کامل</Link>
      </div>
      <p className="card-sub">شمارشِ واقعیِ بازدیدِ صفحه‌های منتشرشده — بدون کوکی و بدون شناسه.</p>

      {items.length === 0 ? (
        <EmptyState
          title="هنوز بازدیدی ثبت نشده است"
          hint={`تا ${faNum(days)} روزِ گذشته هیچ بازدیدی برای صفحهٔ منتشرشده‌ای ثبت نشده؛ به‌محض اولین بازدید، فهرست همین‌جا پر می‌شود.`}
        />
      ) : (
        <ul style={{ listStyle: "none", margin: "10px 0 0", padding: 0, display: "flex", flexDirection: "column", gap: 10 }}>
          {items.map((item) => (
            <li key={`${item.page_id}:${item.path}`}>
              <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 8, fontSize: 13 }}>
                <Link
                  href={`/admin/pages/${item.page_id}/edit`}
                  style={{ overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}
                >
                  {item.title}
                </Link>
                <span style={{ color: "var(--text-muted)", flex: "none" }}>{faNum(item.views)} بازدید</span>
              </div>
              <div
                role="img"
                aria-label={`${item.title}: ${faNum(item.views)} بازدید`}
                style={{ background: "var(--surface-2, rgba(127,127,127,0.15))", borderRadius: 4, blockSize: 8, overflow: "hidden" }}
              >
                <span style={{ display: "block", blockSize: "100%", inlineSize: `${barWidth(item.views, peak)}%`, background: "var(--primary)", borderRadius: 4 }} />
              </div>
              <div dir="ltr" style={{ fontSize: 11.5, color: "var(--text-muted)", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
                {item.path}
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
