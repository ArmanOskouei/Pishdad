/**
 * ECO2 — فرهنگِ لغتِ سایتِ عمومی (انگلیسی).
 *
 * تایپ `Record<PublicMessageKey, string>` یعنی کلیدِ جاافتاده در `tsc` قرمز
 * می‌شود؛ `parity.test.ts` همان را مستقل از typecheck هم می‌سنجد.
 */
import type { PublicMessageKey } from "./fa.ts";

export const publicEn: Record<PublicMessageKey, string> = {
  // ── Language switcher ──
  "lang.label": "Language",
  "lang.fa": "فارسی",
  "lang.en": "English",

  // ── Home ──
  "home.preparing": "The site is being prepared",
  "home.preparingMeta": "Site coming soon",
  "home.emptyHint": "No page has been published yet. Site content will appear here soon.",
  "home.login": "Sign in to the admin panel",

  // ── Suspended ──
  "suspended.title": "The site is temporarily unavailable",
  "suspended.support": "Contact support: support@example.ir",

  // ── Search ──
  "search.pageTitle": "Search the site",
  "search.queryTitle": "Search: {q}",
  "search.aria": "Site search",
  "search.ariaInput": "Search the site",
  "search.placeholder": "Search…",
  "search.submit": "Search",
  "search.results": "Live search results",
  "search.all": "See all results for “{q}” ↵",
  "search.none": "No results — see the results page for “{q}” ↵",
  "search.hint": "Search across all published pages (title and body)",
  "search.queryLabel": "Search term",
  "search.placeholderLong": "e.g. contact, services, FAQ…",
  "search.loading": "Searching…",
  "search.needQuery": "Type a search term",
  "search.needQueryHint": "At least 2 characters are required.",
  "search.noResult": "No results found for “{q}”.",
  "search.noResultHint": "Check the spelling or try simpler words.",
  "search.count": "{n} result(s) for “{q}”",
  "notFound.title": "Not found",

  // ── Public chrome (header/footer) ──
  "chrome.navAria": "Main navigation",
  "chrome.menu": "Menu",
  "chrome.submenu": "Submenu of {label}",
  "chrome.home": "Home",
  "chrome.brandHome": "Home",
  "chrome.logoAlt": "Logo",
  "chrome.cta": "Action",
  "chrome.phone": "Phone:",
  "chrome.email": "Email:",
  "chrome.support": "Contact support: support@example.ir",
  "chrome.newsletter": "Newsletter signup coming soon.",
  "chrome.defaultTitle": "My website",
  "chrome.socialsAria": "Social networks",

  // ── Page blocks ──
  "block.empty": "No content has been added to this page.",
  "block.unknownNotice": "Block “{name}” was not rendered — this block type is not implemented in the core.",
  "block.emptyName": "(empty)",
  "block.imagePending": "Image #{id} — public media resolution is not implemented in the backend yet (TODO).",
  "block.galleryPending": "The gallery has {n} image(s) — public media resolution is not implemented in the backend yet (TODO).",
  "block.imageAlt": "Image",
  "block.galleryAlt": "Gallery",
  "block.watchVideo": "Watch the video",
  "block.contactForm": "Contact form",
  "block.ctaDefault": "Continue",

  // ── Unknown widget ──
  "widget.unknownNotice": "Widget “{name}” was not rendered — this widget type is not implemented in the core renderer.",
  "widget.emptyName": "(empty)",
};
