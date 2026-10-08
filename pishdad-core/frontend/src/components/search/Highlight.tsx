"use client";
import { useMemo } from "react";
import { findRanges } from "@/lib/search-normalize";

/** هایلایت tolerant-فارسی عبارت در متن (با <mark>، فقط var()). */
export function Highlight({ text, query }: { text: string; query: string }) {
  const parts = useMemo(() => {
    const ranges = findRanges(text, query);
    if (ranges.length === 0) return [{ t: text, hl: false as const }];
    const out: Array<{ t: string; hl: boolean }> = [];
    let cur = 0;
    for (const [s, e] of ranges) {
      if (s > cur) out.push({ t: text.slice(cur, s), hl: false });
      out.push({ t: text.slice(s, e), hl: true });
      cur = Math.max(cur, e);
    }
    if (cur < text.length) out.push({ t: text.slice(cur), hl: false });
    return out;
  }, [text, query]);
  return (
    <>
      {parts.map((p, i) =>
        p.hl ? (
          <mark key={i} className="search-hl">
            {p.t}
          </mark>
        ) : (
          <span key={i}>{p.t}</span>
        ),
      )}
    </>
  );
}
