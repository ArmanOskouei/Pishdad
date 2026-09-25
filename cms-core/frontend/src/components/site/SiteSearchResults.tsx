"use client";
import { useEffect, useState } from "react";
import Link from "next/link";
import { runSiteSearch, siteSearchTypeFa, type SiteSearchHit } from "@/lib/site-search";
import { Highlight } from "@/components/search/Highlight";
import { faNum, jalaliDate } from "@/lib/fa";

/**
 * نتایج جستجوی عمومی: کارت کامل (عنوان، snippet با هایلایت، بخش، تاریخ شمسی).
 * TODO(ratings): جای آماده امتیاز/نظر آینده — وقتی endpoint نظرات آمد، زیر هر کارت
 *   <SiteRating slug={hit.slug} /> رندر شود (avg/count + فرم ثبت).
 * TODO(analytics): لاگ کلیک نتیجه (provider دوم search-analytics) برای بهبود رتبه.
 */
export function SiteSearchResults({ initialQ }: { initialQ: string }) {
  const [q, setQ] = useState(initialQ);
  const [hits, setHits] = useState<SiteSearchHit[] | null>(null);

  useEffect(() => {
    setQ(initialQ);
  }, [initialQ]);

  useEffect(() => {
    let alive = true;
    setHits(null);
    const query = q.trim();
    if (query.length < 2) {
      setHits([]);
      return;
    }
    const t = setTimeout(async () => {
      const res = await runSiteSearch(query, 30);
      if (alive) setHits(res);
    }, 200);
    return () => {
      alive = false;
      clearTimeout(t);
    };
  }, [q]);

  return (
    <div>
      <div className="page-head">
        <div>
          <h1>جستجو در سایت</h1>
          <p>جستجو در همه صفحات منتشرشده (عنوان و متن)</p>
        </div>
      </div>

      <form
        className="toolbar"
        onSubmit={(e) => {
          e.preventDefault();
          const v = new FormData(e.currentTarget).get("q");
          setQ(typeof v === "string" ? v : "");
          const url = new URL(window.location.href);
          url.searchParams.set("q", typeof v === "string" ? v : "");
          window.history.replaceState(null, "", url.toString());
        }}
      >
        <div className="field grow">
          <label htmlFor="site-q">عبارت جستجو</label>
          <input
            id="site-q"
            name="q"
            className="input"
            placeholder="مثلاً: تماس، خدمات، سوالات متداول…"
            defaultValue={q}
            key={initialQ}
            minLength={2}
          />
        </div>
        <button className="btn btn-primary" type="submit">
          جستجو
        </button>
      </form>

      {hits === null ? (
        <div className="card card-pad">
          <p style={{ color: "var(--text-muted)", fontSize: 13 }}>در حال جستجو…</p>
        </div>
      ) : null}

      {hits !== null && q.trim().length < 2 ? (
        <div className="card card-pad" role="status">
          <b>عبارت جستجو را بنویسید</b>
          <p style={{ color: "var(--text-muted)", fontSize: 13 }}>حداقل ۲ نویسه لازم است.</p>
        </div>
      ) : null}

      {hits !== null && q.trim().length >= 2 && hits.length === 0 ? (
        <div className="card card-pad" role="status">
          <b>نتیجه‌ای برای «{q.trim()}» پیدا نشد.</b>
          <p style={{ color: "var(--text-muted)", fontSize: 13 }}>
            املا را بررسی کنید یا از واژه‌های ساده‌تر استفاده کنید.
          </p>
          <div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginBlockStart: 8 }}>
            <Link className="btn btn-ghost btn-sm" href="/">خانه</Link>
          </div>
        </div>
      ) : null}

      {hits !== null && hits.length > 0 ? (
        <p style={{ fontSize: 12.5, color: "var(--text-muted)" }}>
          {faNum(hits.length)} نتیجه برای «{q.trim()}»
        </p>
      ) : null}

      <div style={{ display: "flex", flexDirection: "column", gap: 12, marginBlockStart: 12 }}>
        {(hits ?? []).map((h) => (
          <article key={`${h.type}:${h.slug}`} className="card card-pad">
            <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
              <span className="chip">{siteSearchTypeFa(h.type)}</span>
              <span style={{ fontSize: 12, color: "var(--text-muted)" }}>{jalaliDate(h.updated_at)}</span>
            </div>
            <Link
              href={`/${h.slug.replace(/^\/+/, "")}`}
              style={{ display: "block", fontSize: 16, fontWeight: 800, marginBlock: "6px 4px", color: "var(--text)" }}
            >
              <Highlight text={h.title} query={q} />
            </Link>
            {h.snippet ? (
              <p style={{ fontSize: 13.5, color: "var(--text-muted)", lineHeight: 2, margin: 0 }}>
                <Highlight text={h.snippet} query={q} />
              </p>
            ) : null}
            {/* TODO(ratings): امتیاز و نظرات آینده — <SiteRating slug={h.slug} /> */}
            <div data-todo="ratings" style={{ display: "none" }} aria-hidden />
          </article>
        ))}
      </div>
    </div>
  );
}
