"use client";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { barWidth } from "@/lib/analytics";
import { peakSearches, type SearchQueryBucket, type SearchReportData } from "@/lib/search-report";
import { faNum } from "@/lib/fa";
import { Alert, EmptyState, StatCard } from "@/components/ui/primitives";

/** فهرست عبارت‌ها با میلهٔ CSS — همان قاعدهٔ میله‌های صفحهٔ تحلیل. */
function QueryBars({ items, emptyHint }: { items: SearchQueryBucket[]; emptyHint: string }) {
  if (items.length === 0) return <EmptyState title="داده‌ای نیست" hint={emptyHint} />;
  const max = peakSearches(items);

  return (
    <ul style={{ listStyle: "none", margin: 0, padding: 0, display: "flex", flexDirection: "column", gap: 10 }}>
      {items.map((item) => (
        <li key={item.query}>
          <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 8, fontSize: 13 }}>
            <span style={{ overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{item.query}</span>
            <span style={{ color: "var(--text-muted)", flex: "none" }}>{faNum(item.searches)}</span>
          </div>
          <div
            role="img"
            aria-label={`${item.query}: ${faNum(item.searches)}`}
            style={{ background: "var(--surface-2, rgba(127,127,127,0.15))", borderRadius: 4, blockSize: 8, overflow: "hidden" }}
          >
            <span style={{ display: "block", blockSize: "100%", inlineSize: `${barWidth(item.searches, max)}%`, background: "var(--primary)", borderRadius: 4 }} />
          </div>
        </li>
      ))}
    </ul>
  );
}

/**
 * WF-M11 — گزارش جستجوی سایت: پرجستجوترین‌ها و جستجوهای بی‌نتیجه.
 *
 * سرور دادهٔ بازه را می‌خواند و کلاینت فقط انتخابگر بازه و نمایش دارد
 * (بازآرایی با ناوبری، بدون fetch در render).
 */
export function SearchReportClient({
  initial,
  initialFrom,
  initialTo,
  error,
}: {
  initial: SearchReportData | null;
  initialFrom: string;
  initialTo: string;
  error: string | null;
}) {
  const router = useRouter();
  const [from, setFrom] = useState(initial?.range.from ?? initialFrom);
  const [to, setTo] = useState(initial?.range.to ?? initialTo);

  const apply = () => {
    const q = new URLSearchParams();
    if (from) q.set("from", from);
    if (to) q.set("to", to);
    router.push(`/admin/search-report${q.toString() ? `?${q.toString()}` : ""}`);
  };

  return (
    <div>
      <div className="page-head">
        <div>
          <h1>گزارش جستجو</h1>
          <p>چه چیزی بازدیدکنندگان دنبالش هستند — و چه چیزی پیدا نکردند.</p>
        </div>
      </div>

      <form
        className="toolbar"
        onSubmit={(e) => {
          e.preventDefault();
          apply();
        }}
      >
        <div className="field">
          <label htmlFor="from">از تاریخ</label>
          <input id="from" className="input" type="date" dir="ltr" value={from} onChange={(e) => setFrom(e.target.value)} />
        </div>
        <div className="field">
          <label htmlFor="to">تا تاریخ</label>
          <input id="to" className="input" type="date" dir="ltr" value={to} onChange={(e) => setTo(e.target.value)} />
        </div>
        <button className="btn btn-primary" type="submit">اعمال</button>
      </form>

      {error ? <Alert tone="red">{error}</Alert> : null}

      {!initial && !error ? (
        <div className="card card-pad"><EmptyState title="داده‌ای نیست" hint="هنوز جستجویی ثبت نشده است." /></div>
      ) : null}

      {initial ? (
        <>
          <div className="grid c4" style={{ marginBlockEnd: 16 }}>
            <StatCard icon="⌕" value={faNum(initial.totals.searches)} label="کل جستجوها" />
            <StatCard icon="◈" value={faNum(initial.totals.unique_queries)} label="عبارت‌های یکتا" />
            <StatCard icon="∅" value={faNum(initial.totals.zero_result_searches)} label="جستجوهای بی‌نتیجه" />
            <StatCard icon="٪" value={`${faNum(initial.totals.zero_result_rate)}٪`} label="نرخ بی‌نتیجه" />
          </div>

          <div className="grid c2">
            <div className="card card-pad">
              <div className="card-title">پرجستجوترین‌ها</div>
              <p style={{ fontSize: 12, color: "var(--text-muted)", marginBlockStart: 0 }}>
                بیشترین تقاضای بازدیدکنندگان در این بازه.
              </p>
              <QueryBars items={initial.top_queries} emptyHint="در این بازه جستجویی ثبت نشده است." />
            </div>
            <div className="card card-pad">
              <div className="card-title">جستجوهای بی‌نتیجه</div>
              <p style={{ fontSize: 12, color: "var(--text-muted)", marginBlockStart: 0 }}>
                این عبارت‌ها جستجو شدند ولی هیچ نتیجه‌ای نداشتند — صریح‌ترین سرنخ برای تولید محتوای جدید.
              </p>
              <QueryBars items={initial.zero_result_queries} emptyHint="هیچ جستجوی بی‌نتیجه‌ای ثبت نشده — خوب است!" />
            </div>
          </div>
        </>
      ) : null}
    </div>
  );
}
