"use client";
import { useState } from "react";
import type { ChromeLinkItem } from "@/lib/domain";
// F0.2: منوی هدر هم `href` می‌دهد و تا امروز بدون sanitize بود ⇒
// `javascript:` در ناوبری *همهٔ صفحات* سایت زنده بود.
import { safeHref } from "@/lib/sanitize";
import { localizeHref, publicT, type PublicLocale } from "@/lib/i18n/public";

type Path = number[];
const keyOf = (p: Path) => p.join("-");

/**
 * ECO2 — لینکِ داخلیِ صفحه با پیشوندِ زبان.
 * زبان پایه در ریشه می‌ماند؛ زبان دوم `/{locale}` می‌گیرد. این فقط برای
 * لینک‌های داخلیِ رزولو‌شدهٔ `kind=page` است؛ لینک دستی دست‌نخورده می‌ماند.
 */
function navHref(l: ChromeLinkItem, locale: PublicLocale): string {
  const raw = safeHref(l.url ?? l.href) ?? "#";
  return l.kind === "page" ? localizeHref(raw, locale) : raw;
}

function labelOf(l: ChromeLinkItem): string {
  return l.label || l.title || "—";
}

/**
 * منوی کشویی عمومی تودرتو (CSS خالص + دسترس‌پذیر با کیبورد، بدون lib).
 * - دسکتاپ: هاور/فوکوس دراپ‌داون (li:hover / :focus-within) + دکمه ⌄ برای تاچ.
 * - موبایل: آکاردئونی (دکمه همبرگری + بازشوی هر والد).
 * - فقط var() و logical properties (استایل در globals.css).
 */
export function SiteNav({ links, style, locale = "fa" }: { links: ChromeLinkItem[]; style?: string; locale?: PublicLocale }) {
  const [menuOpen, setMenuOpen] = useState(false);
  const [open, setOpen] = useState<Set<string>>(new Set());

  if (links.length === 0) return null;

  const toggle = (path: Path) => {
    const k = keyOf(path);
    setOpen((prev) => {
      const next = new Set(prev);
      if (next.has(k)) next.delete(k);
      else next.add(k);
      return next;
    });
  };

  const closeAll = () => {
    setOpen(new Set());
    setMenuOpen(false);
  };

  const onKey = (e: React.KeyboardEvent) => {
    if (e.key === "Escape") closeAll();
  };

  return (
    <nav
      aria-label={publicT(locale, "chrome.navAria")}
      className={`site-nav${style === "mega" ? " site-nav-mega" : ""}${menuOpen ? " open" : ""}`}
      onKeyDown={onKey}
    >
      <button
        type="button"
        className="site-menu-btn"
        aria-expanded={menuOpen}
        aria-controls="site-nav-list"
        onClick={() => setMenuOpen((v) => !v)}
      >
        <span aria-hidden>{menuOpen ? "✕" : "☰"}</span> {publicT(locale, "chrome.menu")}
      </button>
      <ul id="site-nav-list" className="site-nav-list">
        {links.map((l, i) => (
          <NavItem
            key={i}
            link={l}
            path={[i]}
            open={open}
            toggle={toggle}
            closeAll={closeAll}
            locale={locale}
          />
        ))}
      </ul>
    </nav>
  );
}

function NavItem({
  link, path, open, toggle, closeAll, locale,
}: {
  link: ChromeLinkItem;
  path: Path;
  open: Set<string>;
  toggle: (p: Path) => void;
  closeAll: () => void;
  locale: PublicLocale;
}) {
  const kids = Array.isArray(link.children) ? link.children : [];
  const k = keyOf(path);
  const isOpen = open.has(k);
  const submenuLabel = publicT(locale, "chrome.submenu", { label: labelOf(link) });
  if (kids.length === 0) {
    return (
      <li className="site-nav-item">
        <a className="site-nav-link" href={navHref(link, locale)} onClick={closeAll}>
          {labelOf(link)}
        </a>
      </li>
    );
  }
  return (
    <li className={`site-nav-item has-children${isOpen ? " open" : ""}`}>
      <a className="site-nav-link" href={navHref(link, locale)} onClick={closeAll}>
        {labelOf(link)}
      </a>
      <button
        type="button"
        className="site-nav-toggle"
        aria-expanded={isOpen}
        aria-haspopup="true"
        aria-label={submenuLabel}
        onClick={() => toggle(path)}
      >
        <span aria-hidden>⌄</span>
      </button>
      <ul className="site-sub" aria-label={submenuLabel}>
        {kids.map((c, j) => (
          <NavItem key={j} link={c} path={[...path, j]} open={open} toggle={toggle} closeAll={closeAll} locale={locale} />
        ))}
      </ul>
    </li>
  );
}
