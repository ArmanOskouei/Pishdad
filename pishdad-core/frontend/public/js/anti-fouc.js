/**
 * PERF-1 — اسکریپتِ ضدِ FOUC، به‌صورتِ فایلِ استاتیک.
 *
 کلمه‌به‌کلمه از ANTI_FOUC قبلیِ layout.tsx گرفته شده، با دو تفاوت آگاهانه:
 *
 *   ۱. سطحِ صفحه از `window.__CMS_CONFIG__.panelRoots` خوانده می‌شود (که
 *      اسکریپتِ `/runtime-config.js` لحظه‌ای پیش از همین، در همان <head> و
 *      به‌ترتیب ست کرده) — نه از `data-cms-surface` روی <html>، چون حالا
 *      <html> استاتیک رندر می‌شود و آن صفت فقط سمتِ کلاینت بود.
 *   ۲. `documentElement.lang/dir` از مسیر تصحیح می‌شود تا قبل از اولین رنگ
 *      درست باشد (صفحهٔ عمومی استاتیک است و locale را از مسیر می‌گیرد).
 *
 * why static and not inline: an inline script in the layout would force a
 * nonce, and a nonce forces dynamic rendering — which is exactly what PERF-1
 * removes. A classic blocking <script src> in <head> runs before first paint,
 * so the anti-FOUC guarantee is preserved.
 */
(function () {
  var cfg = window.__CMS_CONFIG__ || {};
  var roots = Array.isArray(cfg.panelRoots) && cfg.panelRoots.length ? cfg.panelRoots : ["admin"];
  var first = (window.location.pathname.split("/")[1] || "").toLowerCase();

  var isPanel = roots.indexOf(first) !== -1;
  if (isPanel) {
    try {
      var raw = window.localStorage.getItem("pishdad-theme");
      if (raw) {
        var t = JSON.parse(raw);
        var h = document.documentElement;
        if (t.preset) h.setAttribute("data-preset", t.preset);
        if (t.accent) h.setAttribute("data-accent", t.accent);
        if (t.mode) h.setAttribute("data-mode", t.mode);
        if (t.radius) h.setAttribute("data-radius", t.radius);
        if (t.density) h.setAttribute("data-density", t.density);
        if (t.font) h.setAttribute("data-font", t.font);
        if (t.dir) h.setAttribute("data-direction", t.dir);
      }
    } catch (e) {
      /* localStorage unavailable — theme falls back to CSS defaults. */
    }
    return;
  }

  // سایت عمومی: جهت و زبان از مسیر (پیشوندِ locale) می‌آید.
  var locales = Array.isArray(cfg.locales) && cfg.locales.length ? cfg.locales : ["fa"];
  var primary = locales[0];
  var h = document.documentElement;
  h.lang = locales.indexOf(first) !== -1 ? first : primary;
  h.dir = h.lang === "fa" ? "rtl" : "ltr";
})();
