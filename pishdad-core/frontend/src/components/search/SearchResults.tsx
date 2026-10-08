"use client";
import { useEffect, useState } from "react";
import Link from "next/link";
import {
  SEARCH_GROUP_FA,
  SEARCH_GROUP_ORDER,
  runSearch,
  type SearchGroup,
  type SearchHit,
} from "@/lib/search-registry";
import { SEARCH_DEBOUNCE_MS, searchReady } from "@/lib/search-timing";
import { Highlight } from "./Highlight";

/** صفحه نتایج admin/search: گروه‌بندی + هایلایت + حالت خالی فارسی. آستانه و دبونس از `search-timing`. */
export function SearchResults({ initialQ }: { initialQ: string }) {
  const [q, setQ] = useState(initialQ);
  const [hits, setHits] = useState<SearchHit[] | null>(null);

  useEffect(() => {
    setQ(initialQ);
  }, [initialQ]);

  useEffect(() => {
    let alive = true;
    setHits(null);
    const query = q.trim();
    // E78 — زیر ۳ حرف ریکوئست نمی‌زند؛ پیامِ «کم است» پایین رندر می‌شود.
    if (!searchReady(query)) {
      setHits([]);
      return;
    }
    const t = setTimeout(async () => {
      const res = await runSearch(query, { limit: 100 });
      if (alive) setHits(res);
    }, SEARCH_DEBOUNCE_MS);
    return () => {
      alive = false;
      clearTimeout(t);
    };
  }, [q]);

  const groups = (SEARCH_GROUP_ORDER.map((g) => ({
    key: g as SearchGroup,
    items: (hits ?? []).filter((h) => h.group === g),
  })).filter((g) => g.items.length > 0) ?? []);

  return (
    <div>
      <div className="page-head">
        <div>
          <h1>جستجو</h1>
          <p>جستجو در صفحات، محتوا، فایل‌ها و تیکت‌ها</p>
        </div>
      </div>

      <form
        className="toolbar"
        onSubmit={(e) => {
          e.preventDefault();
          const v = new FormData(e.currentTarget).get("q");
          setQ(typeof v === "string" ? v : "");
        }}
      >
        <div className="field grow">
          <label htmlFor="sq">عبارت جستجو</label>
          <input
            id="sq"
            name="q"
            className="input"
            placeholder="مثلاً: آپلود عکس، عنوان سایت، تیکت…"
            defaultValue={q}
            key={initialQ}
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

      {hits !== null && q.trim() && !searchReady(q) ? (
        <div className="card card-pad" role="status">
          <b>برای جستجو حداقل ۳ حرف بنویسید.</b>
        </div>
      ) : null}

      {hits !== null && searchReady(q) && hits.length === 0 ? (
        <div className="card card-pad" role="status">
          <b>نتیجه‌ای برای «{q.trim()}» پیدا نشد.</b>
          <p style={{ color: "var(--text-muted)", fontSize: 13 }}>
            املا را بررسی کنید یا از واژه‌های ساده‌تر استفاده کنید؛ مثلاً «عکس»، «تیکت» یا «عنوان».
          </p>
          <div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginBlockStart: 8 }}>
            <Link className="btn btn-ghost btn-sm" href="/admin/pages">صفحات</Link>
            <Link className="btn btn-ghost btn-sm" href="/admin/media">فایل‌ها</Link>
          </div>
        </div>
      ) : null}

      {groups.map((g) => (
        <section key={g.key} aria-label={SEARCH_GROUP_FA[g.key]} style={{ marginBlockStart: 18 }}>
          <h2 style={{ fontSize: 14, margin: "0 0 8px" }}>
            {SEARCH_GROUP_FA[g.key]} <span style={{ color: "var(--text-muted)", fontWeight: 400 }}>({g.items.length})</span>
          </h2>
          <div className="card" style={{ overflow: "hidden" }}>
            {g.items.map((h) => (
              <Link key={h.id} href={h.path} className="search-row">
                <span className="search-ico" aria-hidden>
                  {h.icon}
                </span>
                <span className="search-main">
                  <b>
                    <Highlight text={h.title} query={q} />
                  </b>
                  {h.subtitle ? (
                    <small>
                      <Highlight text={h.subtitle} query={q} />
                    </small>
                  ) : null}
                </span>
                <span className="search-trail" dir="auto">
                  {h.path.split("?")[0]}
                </span>
              </Link>
            ))}
          </div>
        </section>
      ))}
    </div>
  );
}
