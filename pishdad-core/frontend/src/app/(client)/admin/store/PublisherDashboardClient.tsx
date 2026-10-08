"use client";

import { useRef, useState, type ChangeEvent, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { authed, authedForm, ApiError } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Alert, Badge, EmptyState, StatCard } from "@/components/ui/primitives";
import { faNum, jalali } from "@/lib/fa";
import type { PublisherDashboard, PublisherPayout, PublisherPlugin } from "@/lib/publisher";

/**
 * WF-H19 — «ناشر من»: پلاگین‌های من + وضعیت بازبینی، آپلود نسخهٔ جدید،
 * فروش و سهم (دفتر تسویه) و درخواست تسویه. دادهٔ اولیه سرورساید می‌آید؛
 * هر اکشن بعد از انجام، `router.refresh()` می‌زند. هیچ fetch/setState در
 * render نیست (قانون ضد React #301).
 */
export function PublisherDashboardClient({ initial }: { initial: PublisherDashboard }) {
  const toast = useToast();
  const router = useRouter();
  const fileRef = useRef<HTMLInputElement>(null);
  const [uploadFor, setUploadFor] = useState<PublisherPlugin | null>(null);
  const [busy, setBusy] = useState(false);
  const [amount, setAmount] = useState("");
  const [note, setNote] = useState("");
  const [keyId, setKeyId] = useState(
    initial.publishers.length === 1 ? String(initial.publishers[0].id) : "",
  );

  const sales = initial.sales;
  const money = (n: number) => `${faNum(n.toLocaleString("en-US"))} ${sales.currency === "IRT" ? "تومان" : sales.currency}`;

  const onPickFile = (plugin: PublisherPlugin) => {
    if (busy) return;
    setUploadFor(plugin);
    fileRef.current?.click();
  };

  const onFile = async (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    e.target.value = "";
    const plugin = uploadFor;
    if (!file || !plugin) return;
    if (!file.name.toLowerCase().endsWith(".zip")) {
      toast("فایل نسخهٔ جدید باید ZIP باشد.", "err");
      setUploadFor(null);
      return;
    }

    const form = new FormData();
    form.append("file", file);
    setBusy(true);
    try {
      await authedForm(`/v1/market/me/plugins/${plugin.id}/versions`, form);
      toast("نسخهٔ جدید ارسال شد و در انتظار بازبینی است.", "ok");
      router.refresh();
    } catch (err) {
      const details = err instanceof ApiError
        ? (err.payload as { details?: { errors?: Array<{ message?: string }> } } | undefined)?.details?.errors
        : undefined;
      const first = Array.isArray(details)
        ? details.map((d) => d?.message).filter((m): m is string => typeof m === "string" && m !== "")[0]
        : undefined;
      toast(first ?? (err instanceof Error ? err.message : "آپلود نسخهٔ جدید ناموفق بود."), "err");
    } finally {
      setBusy(false);
      setUploadFor(null);
    }
  };

  const requestPayout = async (e: FormEvent) => {
    e.preventDefault();
    if (busy) return;
    const value = Number(amount);
    if (!Number.isInteger(value) || value <= 0) {
      toast("مبلغ معتبر وارد کنید.", "err");
      return;
    }

    const chosen = keyId !== ""
      ? Number(keyId)
      : initial.publishers.length === 1
        ? initial.publishers[0].id
        : undefined;

    setBusy(true);
    try {
      await authed("/v1/market/me/payouts", {
        method: "POST",
        body: {
          amount: value,
          note: note.trim() === "" ? null : note.trim(),
          ...(chosen !== undefined ? { publisher_key_id: chosen } : {}),
        },
      });
      toast("درخواست تسویه ثبت شد و در انتظار تأیید اپراتور است.", "ok");
      setAmount("");
      setNote("");
      router.refresh();
    } catch (err) {
      toast(err instanceof Error ? err.message : "ثبت درخواست تسویه ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  const reviewBadge = (p: PublisherPlugin) => {
    if (p.yanked) return <Badge tone="red">برداشته‌شده</Badge>;
    if (p.review_status === "approved") return <Badge tone="green">تأییدشده</Badge>;
    if (p.review_status === "pending") return <Badge tone="amber">در انتظار بازبینی</Badge>;
    if (p.review_status === "rejected") return <Badge tone="red">ردشده</Badge>;
    return <Badge tone="gray">بررسی‌نشده</Badge>;
  };

  const payoutBadge = (p: PublisherPayout) =>
    p.status === "paid" ? <Badge tone="green">پرداخت‌شده</Badge> : <Badge tone="amber">در انتظار</Badge>;

  return (
    <div style={{ display: "grid", gap: 16 }}>
      <input
        ref={fileRef}
        type="file"
        accept=".zip,application/zip"
        style={{ display: "none" }}
        onChange={onFile}
        aria-hidden
        tabIndex={-1}
      />

      <div className="page-head">
        <div>
          <h1>ناشر من</h1>
          <p>پلاگین‌های شما، وضعیت بازبینی، فروش و سهم، و درخواست تسویه.</p>
        </div>
      </div>

      {initial.publishers.length === 0 ? (
        <EmptyState
          title="هنوز به‌عنوان ناشر ثبت نشده‌اید."
          hint="برای فروش افزونه، ابتدا کلید عمومی ناشر را ثبت کنید و یک بستهٔ ZIP با امضای همان کلید ارسال کنید."
        />
      ) : (
        <div className="card card-pad" style={{ display: "grid", gap: 8 }}>
          <div style={{ display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap" }}>
            <b>پروفایل ناشر</b>
            {initial.publishers.map((p) => (
              <span key={p.id} style={{ display: "inline-flex", gap: 6, alignItems: "center" }}>
                <Badge tone={p.status === "active" ? "green" : "red"}>
                  {p.status === "active" ? "فعال" : "غیرفعال"}
                </Badge>
                <b style={{ fontSize: 13.5 }}>{p.name}</b>
                <span className="muted" dir="ltr" style={{ fontSize: 12 }}>{p.slug}</span>
                <code style={{ fontSize: 11 }} dir="ltr" title={p.key_fingerprint}>
                  {p.key_fingerprint.slice(0, 12)}…
                </code>
              </span>
            ))}
          </div>
        </div>
      )}

      <div style={{ display: "grid", gap: 12, gridTemplateColumns: "repeat(auto-fit, minmax(150px, 1fr))" }}>
        <StatCard icon="◈" value={money(sales.gross)} label="فروش کل" />
        <StatCard icon="✂" value={money(sales.platform_fee)} label="سهم پلتفرم" />
        <StatCard icon="✓" value={money(sales.publisher_share)} label="سهم شما" />
        <StatCard icon="↩" value={money(sales.payout)} label="تسویه‌شده" />
        <StatCard icon="◎" value={money(sales.unsettled)} label="تسویه‌نشده" />
        <StatCard icon="▤" value={faNum(sales.sales_count)} label="تعداد فروش" />
      </div>

      <div className="card card-pad" style={{ display: "grid", gap: 10 }}>
        <b>پلاگین‌های من</b>
        {initial.plugins.length === 0 ? (
          <p className="muted" style={{ margin: 0 }}>هنوز پلاگینی ارسال نکرده‌اید.</p>
        ) : (
          <div style={{ display: "grid", gap: 10 }}>
            {initial.plugins.map((p) => (
              <div key={p.id} className="card card-pad" style={{ display: "grid", gap: 6 }}>
                <div style={{ display: "flex", justifyContent: "space-between", gap: 10, flexWrap: "wrap", alignItems: "baseline" }}>
                  <div>
                    <b style={{ fontSize: 14.5 }}>{p.name}</b>{" "}
                    <span className="muted" dir="ltr" style={{ fontSize: 12 }}>{p.slug}</span>{" "}
                    <code style={{ fontSize: 12 }}>v{p.version}</code>
                    {p.previous_version ? (
                      <span className="muted" style={{ fontSize: 11.5 }}> (قبلی: v{p.previous_version})</span>
                    ) : null}
                  </div>
                  <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
                    {reviewBadge(p)}
                    {p.active ? <Badge tone="green">فعال</Badge> : null}
                    <span style={{ fontSize: 12.5 }}>{money(p.price)}</span>
                  </div>
                </div>

                <div className="muted" style={{ fontSize: 12, display: "flex", gap: 12, flexWrap: "wrap" }}>
                  <span>ارسال: {jalali(p.submitted_at)}</span>
                  <span>بازبینی: {jalali(p.reviewed_at)}</span>
                </div>

                {p.review_status === "rejected" && p.review_note ? (
                  <Alert tone="red">دلیل رد: {p.review_note}</Alert>
                ) : null}
                {p.yanked && p.yank_reason ? (
                  <Alert tone="red">دلیل برداشتن: {p.yank_reason}</Alert>
                ) : null}

                <div>
                  <button
                    className="btn btn-ghost btn-sm"
                    type="button"
                    disabled={busy}
                    onClick={() => onPickFile(p)}
                  >
                    {busy && uploadFor?.id === p.id ? "در حال ارسال…" : "آپلود نسخهٔ جدید"}
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      <div className="card card-pad" style={{ display: "grid", gap: 12 }}>
        <b>درخواست تسویه</b>
        <p className="muted" style={{ margin: 0, fontSize: 12.5 }}>
          حداکثر تا سهم تسویه‌نشده. اجرای پرداخت دستی است و پس از تأیید اپراتور ثبت می‌شود.
        </p>

        <form onSubmit={requestPayout} style={{ display: "flex", gap: 10, flexWrap: "wrap", alignItems: "end" }}>
          <label className="field">
            <span>مبلغ (تومان)</span>
            <input
              className="input"
              type="number"
              min={1}
              step={1}
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              placeholder={String(Math.max(0, sales.unsettled))}
            />
          </label>

          {initial.publishers.length > 1 ? (
            <label className="field">
              <span>کلید ناشر</span>
              <select className="input" value={keyId} onChange={(e) => setKeyId(e.target.value)}>
                <option value="">— انتخاب —</option>
                {initial.publishers.map((p) => (
                  <option key={p.id} value={String(p.id)}>{p.name} ({p.slug})</option>
                ))}
              </select>
            </label>
          ) : null}

          <label className="field" style={{ flex: "1 1 240px" }}>
            <span>توضیح (اختیاری)</span>
            <input
              className="input"
              type="text"
              value={note}
              onChange={(e) => setNote(e.target.value)}
              maxLength={1000}
            />
          </label>

          <button
            className="btn btn-primary btn-sm"
            type="submit"
            disabled={busy || initial.publishers.length === 0}
          >
            ثبت درخواست تسویه
          </button>
        </form>

        {initial.payouts.length === 0 ? (
          <p className="muted" style={{ margin: 0, fontSize: 12.5 }}>هنوز درخواست تسویه‌ای ثبت نشده است.</p>
        ) : (
          <div className="table-wrap">
            <table className="tbl tbl-cards">
              <thead>
                <tr>
                  <th>مبلغ</th>
                  <th>وضعیت</th>
                  <th>تاریخ ثبت</th>
                  <th>توضیح</th>
                </tr>
              </thead>
              <tbody>
                {initial.payouts.map((p) => (
                  <tr key={p.id}>
                    <td data-label="مبلغ">{money(p.amount)}</td>
                    <td data-label="وضعیت">{payoutBadge(p)}</td>
                    <td data-label="تاریخ ثبت">{jalali(p.created_at)}</td>
                    <td data-label="توضیح">{p.note ?? "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
