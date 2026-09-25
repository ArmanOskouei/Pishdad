"use client";
import { useEffect, useId, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { getSubscriptionLock, runSearch, type SearchHit } from "@/lib/search-registry";
import { Highlight } from "./Highlight";

const DEBOUNCE_MS = 150;
const MAX_DROP = 8;

/** لایو‌سرچ Topbar: دراپ‌داون (حداکثر ۸)، کیبورد ↑↓/Enter/Escape، دبونس ۱۵۰ms. */
export function AdminSearch() {
  const router = useRouter();
  const boxId = useId();
  const [q, setQ] = useState("");
  const [hits, setHits] = useState<SearchHit[]>([]);
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);
  const [locked, setLocked] = useState(false);
  const wrapRef = useRef<HTMLDivElement>(null);
  const seq = useRef(0);

  useEffect(() => {
    void getSubscriptionLock().then(setLocked).catch(() => undefined);
  }, []);

  // بستن با کلیک بیرون
  useEffect(() => {
    const onDown = (e: PointerEvent) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener("pointerdown", onDown);
    return () => document.removeEventListener("pointerdown", onDown);
  }, []);

  // جستجوی دبونس‌شده
  useEffect(() => {
    const query = q.trim();
    if (query.length < 1) {
      setHits([]);
      setOpen(false);
      setActive(-1);
      return;
    }
    const my = ++seq.current;
    const t = setTimeout(async () => {
      const res = await runSearch(query, { locked, limit: MAX_DROP });
      if (seq.current !== my) return;
      setHits(res);
      setActive(res.length > 0 ? 0 : -1);
      setOpen(true);
    }, DEBOUNCE_MS);
    return () => clearTimeout(t);
  }, [q, locked]);

  const goResults = (query: string) => {
    setOpen(false);
    router.push(`/admin/search?q=${encodeURIComponent(query.trim())}`);
  };

  const onKey = (e: React.KeyboardEvent) => {
    if (e.key === "ArrowDown" && hits.length > 0) {
      e.preventDefault();
      setOpen(true);
      setActive((a) => (a + 1) % hits.length);
    } else if (e.key === "ArrowUp" && hits.length > 0) {
      e.preventDefault();
      setActive((a) => (a - 1 + hits.length) % hits.length);
    } else if (e.key === "Enter") {
      e.preventDefault();
      if (open && active >= 0 && hits[active]) {
        setOpen(false);
        router.push(hits[active].path);
      } else if (q.trim()) {
        goResults(q);
      }
    } else if (e.key === "Escape") {
      setOpen(false);
      setActive(-1);
    }
  };

  const listId = `${boxId}-list`;

  return (
    <div className="top-search search-wrap" ref={wrapRef}>
      <span aria-hidden>⌕</span>
      <input
        placeholder="جستجو…"
        aria-label="جستجوی پنل"
        role="combobox"
        aria-expanded={open}
        aria-controls={listId}
        aria-activedescendant={active >= 0 ? `${boxId}-opt-${active}` : undefined}
        autoComplete="off"
        value={q}
        onChange={(e) => setQ(e.target.value)}
        onKeyDown={onKey}
        onFocus={() => {
          if (hits.length > 0) setOpen(true);
        }}
      />
      {open && hits.length > 0 ? (
        <div className="search-drop" role="listbox" id={listId} aria-label="نتایج جستجو">
          {hits.map((h, i) => (
            <button
              key={h.id}
              id={`${boxId}-opt-${i}`}
              role="option"
              aria-selected={i === active}
              className={`search-item${i === active ? " active" : ""}`}
              onMouseEnter={() => setActive(i)}
              onClick={() => {
                setOpen(false);
                router.push(h.path);
              }}
            >
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
                {trailFa(h.path)}
              </span>
            </button>
          ))}
          <button
            className="search-more"
            onClick={() => goResults(q)}
            onMouseEnter={() => setActive(-1)}
          >
            مشاهده همه نتایج برای «{q.trim()}» ↵
          </button>
        </div>
      ) : null}
      {open && q.trim() && hits.length === 0 ? (
        <div className="search-drop" role="status">
          <button className="search-more" onClick={() => goResults(q)}>
            نتیجه‌ای نیست — جستجوی کامل برای «{q.trim()}» ↵
          </button>
        </div>
      ) : null}
    </div>
  );
}

/** مسیر نمایشی فارسی از روی path (بدون وابستگی به منو). */
function trailFa(path: string): string {
  const seg = path.split("?")[0].split("/").filter(Boolean);
  if (seg[0] === "admin") seg[0] = "پنل";
  return seg.join(" › ");
}
