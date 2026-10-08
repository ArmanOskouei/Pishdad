"use client";

import { useState } from "react";
import Link from "next/link";
import { useLang, type MessageKey } from "@/lib/i18n";
import { Alert, Badge } from "@/components/ui/primitives";
import { formatPrice, formatRating, filledStars, storeAction, type StoreDetail } from "@/lib/store";
import { useStoreActions } from "@/lib/useStoreActions";

/**
 * WF-H16 — نمای جزئیات: توضیح کامل، تصاویر، فهرست نسخه‌ها/changelog و همان
 * اکشن‌های نصب/خرید/دانلود.
 *
 * WF-H18 — امتیاز (ستاره)، فهرست نظرهای تأییدشده و فرم «ثبت نظر» برای
 * خریدارِ تأییدشده. هر داده‌ای که بک‌اند نداده باشد صادقانه نشان داده می‌شود،
 * نه پنهان.
 */
export function StoreDetailClient({ item }: { item: StoreDetail }) {
  const { t } = useLang();
  const { busy, placeOrder, payOrder, installItem, download, submitReview } = useStoreActions();
  const tr = (key: MessageKey, values?: Record<string, string | number>) => t(key, values);
  const action = storeAction(item);

  const [rating, setRating] = useState(5);
  const [comment, setComment] = useState("");
  const [sending, setSending] = useState(false);

  const onReviewSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (sending) return;
    setSending(true);
    try {
      await submitReview(item, rating, comment.trim());
      setComment("");
    } finally {
      setSending(false);
    }
  };

  const myStatusKey: MessageKey | null =
    item.my_review_status === "pending"
      ? "store.reviewStatusPending"
      : item.my_review_status === "approved"
        ? "store.reviewStatusApproved"
        : item.my_review_status === "rejected"
          ? "store.reviewStatusRejected"
          : "store.reviewAlready";

  return (
    <div style={{ display: "grid", gap: 16 }}>
      <div className="page-head">
        <div>
          <Link className="btn btn-ghost btn-sm" href="/admin/store">← {tr("store.back")}</Link>
          <h1 style={{ marginBlock: "8px 4px" }}>
            {item.name} {item.active ? <Badge tone="green">فعال</Badge> : null}
          </h1>
          <p style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap", margin: 0 }}>
            <span className="muted" dir="ltr">{item.slug}</span>
            <code>v{item.version}</code>
            {item.category ? <Badge tone="gray">{item.category}</Badge> : null}
            {item.delivery.owned ? <Badge tone="green">{tr("store.owned")}</Badge> : null}
            {item.delivery.delivered ? <Badge tone="violet">{tr("store.deliveredOnce")}</Badge> : null}
          </p>
          <p style={{ display: "flex", gap: 12, alignItems: "center", flexWrap: "wrap", margin: "6px 0 0" }}>
            <Stars value={item.rating} />
            <span style={{ fontSize: 13 }}>
              {item.rating !== null && item.rating !== undefined ? (
                <>
                  <b>{formatRating(item.rating)}</b>{" "}
                  <span className="muted">({tr("store.ratingCount", { n: item.rating_count ?? 0 })})</span>
                </>
              ) : (
                <span className="muted">{tr("store.noRating")}</span>
              )}
            </span>
            <span className="muted" style={{ fontSize: 12.5 }}>
              {tr("store.installs")}: <b>{item.active_installs ?? 0}</b>
            </span>
          </p>
        </div>
        <b style={{ fontSize: 16 }}>{formatPrice(item.price, item.currency)}</b>
      </div>

      <Alert tone="amber">{tr("store.manualNote")}</Alert>

      <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
        {!item.active ? (
          <button
            className="btn btn-primary btn-sm"
            disabled={busy === item.id}
            aria-label={`${tr("store.install")} ${item.name}`}
            onClick={() => void installItem(item)}
          >
            {tr("store.install")}
          </button>
        ) : null}

        {action === "download" ? (
          <button className="btn btn-ghost btn-sm" disabled={busy === item.id} onClick={() => download(item)}>
            {tr("store.download")}
          </button>
        ) : null}

        {action === "buy" || action === "get" ? (
          <button className="btn btn-ghost btn-sm" disabled={busy === item.id} onClick={() => placeOrder(item)}>
            {action === "get" ? tr("store.free") : tr("store.buy")}
          </button>
        ) : null}

        {!item.delivery.owned && item.price > 0 ? (
          <button className="btn btn-ghost btn-sm" disabled={busy === item.id} onClick={() => payOrder(item)}>
            {tr("store.payOrder")}
          </button>
        ) : null}
      </div>

      <section className="card card-pad">
        <h2 style={{ marginBlockStart: 0, fontSize: 15 }}>{tr("store.detailDescription")}</h2>
        {item.long_description ? (
          <p style={{ whiteSpace: "pre-wrap", margin: 0, fontSize: 13.5 }}>{item.long_description}</p>
        ) : (
          <p className="muted" style={{ margin: 0, fontSize: 13 }}>{item.description ?? "—"}</p>
        )}
      </section>

      <section className="card card-pad">
        <h2 style={{ marginBlockStart: 0, fontSize: 15 }}>{tr("store.detailScreenshots")}</h2>
        {item.screenshots && item.screenshots.length > 0 ? (
          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(220px, 1fr))", gap: 10 }}>
            {item.screenshots.map((src) => (
              <img
                key={src}
                src={src}
                alt={`${item.name} — ${tr("store.detailScreenshots")}`}
                loading="lazy"
                style={{ inlineSize: "100%", borderRadius: 10, border: "1px solid var(--border)" }}
              />
            ))}
          </div>
        ) : (
          <p className="muted" style={{ margin: 0, fontSize: 13 }}>{tr("store.detailNoScreenshots")}</p>
        )}
      </section>

      <section className="card card-pad">
        <h2 style={{ marginBlockStart: 0, fontSize: 15 }}>
          {tr("store.reviews")}{" "}
          <span className="muted" style={{ fontSize: 12.5 }}>
            ({tr("store.ratingCount", { n: item.rating_count ?? 0 })})
          </span>
        </h2>

        {item.reviews.length > 0 ? (
          <div style={{ display: "grid", gap: 12 }}>
            {item.reviews.map((r) => (
              <div
                key={r.id}
                style={{ borderInlineStart: "3px solid var(--border)", paddingInlineStart: 10 }}
              >
                <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
                  <Stars value={r.rating} />
                  <b style={{ fontSize: 13 }}>{r.author ?? "خریدار"}</b>
                  {r.created_at ? (
                    <span className="muted" style={{ fontSize: 12 }} dir="ltr">
                      {r.created_at.slice(0, 10)}
                    </span>
                  ) : null}
                </div>
                {r.comment ? (
                  <p style={{ whiteSpace: "pre-wrap", margin: "4px 0 0", fontSize: 13 }}>{r.comment}</p>
                ) : null}
              </div>
            ))}
          </div>
        ) : (
          <p className="muted" style={{ margin: 0, fontSize: 13 }}>{tr("store.noReviews")}</p>
        )}

        <div style={{ marginBlockStart: 14, borderBlockStart: "1px solid var(--border)", paddingBlockStart: 12 }}>
          {item.has_reviewed ? (
            <Alert tone={item.my_review_status === "rejected" ? "red" : "blue"}>
              {tr(myStatusKey)}
            </Alert>
          ) : item.can_review ? (
            <form onSubmit={(e) => void onReviewSubmit(e)} style={{ display: "grid", gap: 8 }}>
              <b style={{ fontSize: 13.5 }}>{tr("store.reviewFormTitle")}</b>

              <div style={{ display: "flex", gap: 6, alignItems: "center" }}>
                <span style={{ fontSize: 13 }}>{tr("store.reviewRating")}:</span>
                {[1, 2, 3, 4, 5].map((n) => (
                  <button
                    key={n}
                    type="button"
                    className="btn btn-ghost btn-sm"
                    aria-label={`${n}`}
                    aria-pressed={rating === n}
                    onClick={() => setRating(n)}
                    style={{ fontSize: 16, lineHeight: 1, padding: "2px 6px", color: n <= rating ? "var(--warning, #f59e0b)" : "var(--text-muted)" }}
                  >
                    ★
                  </button>
                ))}
                <span className="muted" style={{ fontSize: 12 }}>{rating}/5</span>
              </div>

              <textarea
                value={comment}
                onChange={(e) => setComment(e.target.value)}
                rows={3}
                maxLength={2000}
                placeholder={tr("store.reviewComment")}
                style={{
                  inlineSize: "100%",
                  padding: 8,
                  borderRadius: 8,
                  border: "1px solid var(--border)",
                  background: "var(--surface)",
                  color: "var(--text)",
                  fontFamily: "inherit",
                  fontSize: 13,
                }}
              />

              <div>
                <button className="btn btn-primary btn-sm" type="submit" disabled={sending || busy === item.id}>
                  {tr("store.reviewSubmit")}
                </button>
              </div>
            </form>
          ) : (
            <Alert tone="amber">{tr("store.reviewNeedPurchase")}</Alert>
          )}
        </div>
      </section>

      <section className="card card-pad">
        <h2 style={{ marginBlockStart: 0, fontSize: 15 }}>{tr("store.detailVersions")}</h2>
        {item.versions.length > 0 ? (
          <div style={{ display: "grid", gap: 10 }}>
            {item.versions.map((v) => (
              <div
                key={`${v.version}-${v.released_at ?? ""}`}
                style={{ borderInlineStart: "3px solid var(--border)", paddingInlineStart: 10 }}
              >
                <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
                  <code>v{v.version}</code>
                  {v.yanked ? <Badge tone="red">{tr("store.yanked")}</Badge> : null}
                  {v.released_at ? (
                    <span className="muted" style={{ fontSize: 12 }}>
                      {tr("store.releasedAt")}: <span dir="ltr">{v.released_at.slice(0, 10)}</span>
                    </span>
                  ) : null}
                </div>
                {v.changelog ? (
                  <p style={{ whiteSpace: "pre-wrap", margin: "4px 0 0", fontSize: 13 }}>{v.changelog}</p>
                ) : null}
              </div>
            ))}
          </div>
        ) : (
          <p className="muted" style={{ margin: 0, fontSize: 13 }}>{tr("store.detailNoVersions")}</p>
        )}
      </section>
    </div>
  );
}

/** ستارهٔ نمایشی — تعداد پُر از `filledStars`؛ خالی‌ها با ستارهٔ توخالی. */
function Stars({ value }: { value: number | null | undefined }) {
  const filled = filledStars(value);
  return (
    <span dir="ltr" aria-label={`${filled}/5`} style={{ color: "var(--warning, #f59e0b)", letterSpacing: 1 }}>
      {"★".repeat(filled)}
      <span style={{ color: "var(--border)" }}>{"☆".repeat(5 - filled)}</span>
    </span>
  );
}
