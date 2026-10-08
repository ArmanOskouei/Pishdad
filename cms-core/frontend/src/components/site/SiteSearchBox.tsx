"use client";
import { useEffect, useId, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { runSiteSearch, siteSearchTypeFa, type SiteSearchHit } from "@/lib/site-search";
import { SEARCH_DEBOUNCE_MS, searchReady } from "@/lib/search-timing";
import { localizeHref, publicT, type PublicLocale } from "@/lib/i18n/public";
import { Highlight } from "@/components/search/Highlight";

const MAX_DROP = 8;

/**
 * ویجت جستجوی واقعی هدر: لایو دراپ‌داون (دبونس، عنوان + snippet + نوع بخش)
 * + Enter → صفحه نتایج /search?q= . آستانه و دبونس از `search-timing`.
 */
export function SiteSearchBox({ placeholder, locale = "fa" }: { placeholder?: string; locale?: PublicLocale }) {
  const router = useRouter();
  const boxId = useId();
  const [q, setQ] = useState("");
  const [hits, setHits] = useState<SiteSearchHit[]>([]);
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);
  const [busy, setBusy] = useState(false);
  const wrapRef = useRef<HTMLDivElement>(null);
  const seq = useRef(0);

  useEffect(() => {
    const onDown = (e: PointerEvent) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener("pointerdown", onDown);
    return () => document.removeEventListener("pointerdown", onDown);
  }, []);

  useEffect(() => {
    const query = q.trim();
    // E78 — زیر ۳ حرف ریکوئست نمی‌زند.
    if (!searchReady(query)) {
      setHits([]);
      setOpen(false);
      setActive(-1);
      setBusy(false);
      return;
    }
    setBusy(true);
    const my = ++seq.current;
    const t = setTimeout(async () => {
      const res = await runSiteSearch(query, MAX_DROP);
      if (seq.current !== my) return;
      setHits(res);
      setActive(res.length > 0 ? 0 : -1);
      setOpen(true);
      setBusy(false);
    }, SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(t);
  }, [q]);

  const goResults = (query: string) => {
    const v = query.trim();
    // E78 — سابمیت هم بدون ۳ حرف جایی نمی‌رود.
    if (!searchReady(v)) return;
    setOpen(false);
    router.push(localizeHref(`/search?q=${encodeURIComponent(v)}`, locale));
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
        router.push(localizeHref(`/${hits[active].slug.replace(/^\/+/, "")}`, locale));
      } else {
        goResults(q);
      }
    } else if (e.key === "Escape") {
      setOpen(false);
      setActive(-1);
    }
  };

  const listId = `${boxId}-list`;

  return (
    <div className="site-search search-wrap" ref={wrapRef}>
      <form
        role="search"
        aria-label={publicT(locale, "search.aria")}
        onSubmit={(e) => {
          e.preventDefault();
          goResults(q);
        }}
        style={{ display: "flex", gap: 6 }}
      >
        <input
          name="q"
          className="input"
          placeholder={placeholder || publicT(locale, "search.placeholder")}
          aria-label={publicT(locale, "search.ariaInput")}
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
          style={{ maxInlineSize: 200 }}
        />
        <button type="submit" className="btn btn-ghost btn-sm" aria-label={publicT(locale, "search.submit")}>
          {busy ? "…" : "⌕"}
        </button>
      </form>
      {open && hits.length > 0 ? (
        <div className="search-drop" role="listbox" id={listId} aria-label={publicT(locale, "search.results")}>
          {hits.map((h, i) => (
            <button
              key={`${h.type}:${h.slug}`}
              id={`${boxId}-opt-${i}`}
              type="button"
              role="option"
              aria-selected={i === active}
              className={`search-item${i === active ? " active" : ""}`}
              onMouseEnter={() => setActive(i)}
              onClick={() => {
                setOpen(false);
                router.push(localizeHref(`/${h.slug.replace(/^\/+/, "")}`, locale));
              }}
            >
              <span className="search-main">
                <b>
                  <Highlight text={h.title} query={q} />
                </b>
                {h.snippet ? (
                  <small>
                    <Highlight text={h.snippet} query={q} />
                  </small>
                ) : null}
              </span>
              <span className="search-trail" dir="auto">
                {siteSearchTypeFa(h.type)}
              </span>
            </button>
          ))}
          <button type="button" className="search-more" onClick={() => goResults(q)} onMouseEnter={() => setActive(-1)}>
            {publicT(locale, "search.all", { q: q.trim() })}
          </button>
        </div>
      ) : null}
      {open && searchReady(q) && hits.length === 0 && !busy ? (
        <div className="search-drop" role="status">
          <button type="button" className="search-more" onClick={() => goResults(q)}>
            {publicT(locale, "search.none", { q: q.trim() })}
          </button>
        </div>
      ) : null}
    </div>
  );
}
