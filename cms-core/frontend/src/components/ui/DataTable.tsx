"use client";
import { useMemo, useState } from "react";
import { EmptyState } from "./primitives";

export type Column<T> = { key: string; title: string; render: (row: T) => React.ReactNode };

/** DataTable تسک ۰.۱: جستجو + صفحه‌بندی سمت کلاینت (سرور در فاز حلقه پول). */
export function DataTable<T extends { id?: number | string }>({
  rows, columns, searchKeys, pageSize = 8, emptyTitle = "موردی نیست",
}: {
  rows: T[]; columns: Column<T>[]; searchKeys: (keyof T)[]; pageSize?: number; emptyTitle?: string;
}) {
  const [q, setQ] = useState("");
  const [page, setPage] = useState(1);
  const filtered = useMemo(() => {
    if (!q.trim()) return rows;
    return rows.filter((r) => searchKeys.some((k) => String(r[k] ?? "").includes(q.trim())));
  }, [rows, q, searchKeys]);
  const pages = Math.max(1, Math.ceil(filtered.length / pageSize));
  const cur = Math.min(page, pages);
  const slice = filtered.slice((cur - 1) * pageSize, cur * pageSize);
  return (
    <div>
      <div className="field" style={{ maxInlineSize: 320 }}>
        <input className="input" placeholder="جستجو…" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} aria-label="جستجو" />
      </div>
      {slice.length === 0 ? (
        <div className="card card-pad"><EmptyState title={emptyTitle} hint={q ? "عبارت دیگری را امتحان کنید." : undefined} /></div>
      ) : (
        <div className="table-wrap">
          <table className="tbl">
            <thead><tr>{columns.map((c) => <th key={c.key}>{c.title}</th>)}</tr></thead>
            <tbody>
              {slice.map((r, i) => (
                <tr key={String(r.id ?? i)}>{columns.map((c) => <td key={c.key}>{c.render(r)}</td>)}</tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {pages > 1 ? (
        <div style={{ display: "flex", gap: 8, alignItems: "center", marginBlockStart: 12 }}>
          <button className="btn btn-ghost btn-sm" disabled={cur <= 1} onClick={() => setPage(cur - 1)}>قبلی</button>
          <span style={{ fontSize: 12.5, color: "var(--text-muted)" }}>صفحه {cur} از {pages}</span>
          <button className="btn btn-ghost btn-sm" disabled={cur >= pages} onClick={() => setPage(cur + 1)}>بعدی</button>
        </div>
      ) : null}
    </div>
  );
}
