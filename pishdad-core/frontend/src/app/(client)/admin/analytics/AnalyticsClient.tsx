"use client";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { barWidth, peakBucket, peakViews, type AnalyticsBucket, type AnalyticsData, type AnalyticsVital } from "@/lib/analytics";
import { faNum, jalaliDate } from "@/lib/fa";
import { classifyVital, formatVitalValue, VITAL_LABELS, VITAL_RATING_LABELS, VITAL_UNITS, vitalBarWidth } from "@/lib/vitals";
import { Alert, Badge, EmptyState, StatCard } from "@/components/ui/primitives";

const COUNTRY_LABELS: Record<string, string> = {
  IR: "ایران",
  US: "آمریکا",
  DE: "آلمان",
  GB: "بریتانیا",
  TR: "ترکیه",
  AE: "امارات",
  CA: "کانادا",
  FR: "فرانسه",
  NL: "هلند",
  IN: "هند",
};

const DEVICE_LABELS: Record<string, string> = {
  desktop: "دسکتاپ",
  mobile: "موبایل",
  tablet: "تبلت",
  bot: "ربات",
  unknown: "نامعلوم",
};

function bucketLabel(kind: "page" | "referrer" | "country" | "device", key: string): string {
  if (kind === "country") return `${COUNTRY_LABELS[key] ?? key} (${key})`;
  if (kind === "device") return DEVICE_LABELS[key] ?? key;
  return key;
}

/** فهرست تجمعی با میلهٔ CSS — بدون کتابخانهٔ نمودار. */
function Bars({
  items,
  kind,
  emptyHint,
}: {
  items: AnalyticsBucket[];
  kind: "page" | "referrer" | "country" | "device";
  emptyHint: string;
}) {
  if (items.length === 0) return <EmptyState title="داده‌ای نیست" hint={emptyHint} />;
  const max = peakBucket(items);

  return (
    <ul style={{ listStyle: "none", margin: 0, padding: 0, display: "flex", flexDirection: "column", gap: 10 }}>
      {items.map((item) => (
        <li key={item.key}>
          <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 8, fontSize: 13 }}>
            <span
              dir={kind === "page" || kind === "referrer" ? "ltr" : undefined}
              style={{ overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}
            >
              {bucketLabel(kind, item.key)}
            </span>
            <span style={{ color: "var(--text-muted)", flex: "none" }}>{faNum(item.views)}</span>
          </div>
          <div
            role="img"
            aria-label={`${bucketLabel(kind, item.key)}: ${faNum(item.views)}`}
            style={{ background: "var(--surface-2, rgba(127,127,127,0.15))", borderRadius: 4, blockSize: 8, overflow: "hidden" }}
          >
            <span style={{ display: "block", blockSize: "100%", inlineSize: `${barWidth(item.views, max)}%`, background: "var(--primary)", borderRadius: 4 }} />
          </div>
        </li>
      ))}
    </ul>
  );
}

/** WF-M14 — کارت یک معیارِ Web Vitals با آستانهٔ خوب/نیازمند بهبود/ضعیف. */
function VitalCard({ vital }: { vital: AnalyticsVital }) {
  const rating = classifyVital(vital.metric, vital.p75);
  const tone = rating === "good" ? "green" : rating === "needs-improvement" ? "amber" : rating === "poor" ? "red" : "gray";
  const unit = VITAL_UNITS[vital.metric];
  const width = vital.p75 === null ? 0 : vitalBarWidth(vital.metric, vital.p75);

  return (
    <div className="card card-pad" style={{ display: "flex", flexDirection: "column", gap: 6 }}>
      <div style={{ fontSize: 12, color: "var(--text-muted)" }}>{VITAL_LABELS[vital.metric]}</div>
      <div style={{ display: "flex", alignItems: "baseline", gap: 6 }}>
        <span className="stat-num">{vital.p75 === null ? "—" : faNum(formatVitalValue(vital.metric, vital.p75))}</span>
        {unit ? <span style={{ fontSize: 12, color: "var(--text-muted)" }}>{unit}</span> : null}
      </div>
      <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
        {rating ? <Badge tone={tone}>{VITAL_RATING_LABELS[rating]}</Badge> : <Badge tone="gray">بدون داده</Badge>}
        <span style={{ fontSize: 12, color: "var(--text-muted)" }}>{faNum(vital.samples)} نمونه</span>
      </div>
      <div style={{ background: "var(--surface-2, rgba(127,127,127,0.15))", borderRadius: 4, blockSize: 6, overflow: "hidden" }}>
        <span style={{ display: "block", blockSize: "100%", inlineSize: `${width}%`, background: "var(--primary)", borderRadius: 4 }} />
      </div>
    </div>
  );
}

export function AnalyticsClient({
  initial,
  initialFrom,
  initialTo,
  error,
}: {
  initial: AnalyticsData | null;
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
    router.push(`/admin/analytics${q.toString() ? `?${q.toString()}` : ""}`);
  };

  const seriesMax = initial ? peakViews(initial.series) : 0;

  return (
    <div>
      <div className="page-head">
        <div>
          <h1>تحلیل سایت</h1>
          <p>بازدید خودمیزبان و بدون کوکی — بازه را انتخاب کنید.</p>
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
        <div className="card card-pad"><EmptyState title="داده‌ای نیست" hint="هنوز بازدیدی ثبت نشده است." /></div>
      ) : null}

      {initial ? (
        <>
          <div className="grid c4" style={{ marginBlockEnd: 16 }}>
            <StatCard icon="◉" value={faNum(initial.totals.views)} label="کل بازدید" />
            <StatCard icon="▤" value={faNum(initial.totals.unique_paths)} label="مسیرهای یکتا" />
            <StatCard icon="↩" value={faNum(initial.totals.unique_referrers)} label="ارجاع‌دهنده‌های یکتا" />
            <StatCard icon="◍" value={faNum(initial.totals.countries_known)} label="کشورهای شناخته‌شده" />
          </div>

          <div className="card card-pad">
            <div className="card-title">روند روزانه</div>
            {initial.series.length === 0 ? (
              <EmptyState title="داده‌ای نیست" hint="در این بازه بازدیدی ثبت نشده است." />
            ) : (
              <div
                role="img"
                aria-label="نمودار بازدید روزانه"
                style={{ display: "flex", alignItems: "flex-end", gap: 4, blockSize: 180, overflowX: "auto", paddingBlockEnd: 4 }}
              >
                {initial.series.map((point) => {
                  const height = point.views > 0 ? Math.min(Math.max(barWidth(point.views, seriesMax), 4), 90) : 0;
                  return (
                    <div
                      key={point.date}
                      title={`${jalaliDate(point.date)} — ${faNum(point.views)}`}
                      style={{ display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "flex-end", gap: 4, minInlineSize: 28, blockSize: "100%" }}
                    >
                      <span style={{ inlineSize: 14, blockSize: `${height}%`, minBlockSize: 2, background: "var(--primary)", borderRadius: "3px 3px 0 0" }} />
                      <span style={{ fontSize: 9, color: "var(--text-muted)", writingMode: "vertical-rl", whiteSpace: "nowrap" }}>{jalaliDate(point.date)}</span>
                    </div>
                  );
                })}
              </div>
            )}
          </div>

          <div className="grid c2" style={{ marginBlockStart: 16 }}>
            <div className="card card-pad">
              <div className="card-title">پربازدیدترین صفحه‌ها</div>
              <Bars items={initial.top_pages} kind="page" emptyHint="در این بازه صفحه‌ای دیده نشده است." />
            </div>
            <div className="card card-pad">
              <div className="card-title">ارجاع‌دهنده‌ها</div>
              <Bars items={initial.top_referrers} kind="referrer" emptyHint="ورودی ارجاعی ثبت نشده است." />
            </div>
          </div>

          <div className="grid c2" style={{ marginBlockStart: 16 }}>
            <div className="card card-pad">
              <div className="card-title">کشورها</div>
              <p style={{ fontSize: 12, color: "var(--text-muted)", marginBlockStart: 0 }}>
                پوشش کشور: {faNum(initial.totals.country_coverage)}٪ از بازدیدها؛ بقیه نامعلوم است چون لبهٔ شبکه جغرافیا را اعلام نکرده.
              </p>
              <Bars items={initial.countries} kind="country" emptyHint="لبهٔ شبکه کشوری اعلام نکرده است." />
            </div>
            <div className="card card-pad">
              <div className="card-title">دستگاه‌ها</div>
              <Bars items={initial.devices} kind="device" emptyHint="داده‌ای نیست." />
            </div>
          </div>

          {initial.vitals && initial.vitals.length > 0 ? (
            <div className="card card-pad" style={{ marginBlockStart: 16 }}>
              <div className="card-title">Web Vitals — صدک ۷۵</div>
              <p style={{ fontSize: 12, color: "var(--text-muted)", marginBlockStart: 0 }}>
                سنجشِ تجربهٔ واقعی بازدیدکنندگان؛ داده تجمعی و بدون شناسه است. آستانه‌ها بر پایهٔ Core Web Vitals.
              </p>
              <div className="grid c3">
                {initial.vitals.map((vital) => (
                  <VitalCard key={vital.metric} vital={vital} />
                ))}
              </div>
            </div>
          ) : null}
        </>
      ) : null}
    </div>
  );
}
