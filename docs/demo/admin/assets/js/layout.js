/* ═══ Shell مشترک: رجیستری منو + رندر سایدبار/تاپ‌بار/ناوبری موبایل + تم ═══ */
const I = (p, f) => `<svg viewBox="0 0 24 24" fill="${f ? "currentColor" : "none"}" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${p}</svg>`;
const ICONS = {
  dash: I('<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>'),
  page: I('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h6"/>'),
  media: I('<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>'),
  ticket: I('<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2M13 17v2M13 11v2"/>'),
  users: I('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>'),
  user: I('<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>'),
  gear: I('<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>'),
  share: I('<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/>'),
  plug: I('<path d="M9 2v6M15 2v6M6 8h12v4a6 6 0 0 1-12 0V8zM12 18v4"/>'),
  layout: I('<rect x="3" y="3" width="18" height="7" rx="1.5"/><rect x="3" y="14" width="9" height="7" rx="1.5"/><rect x="16" y="14" width="5" height="7" rx="1.5"/>'),
  blocks: I('<rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/>'),
  theme: I('<circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 0 0 18c1.5 0 2-1 1.5-2s.5-2.5 2-2.5H17a4 4 0 0 0 4-4c0-1-.5-1.5-1-2S19.5 8 18 8h-1A9 9 0 0 0 12 3z"/><circle cx="7.5" cy="11" r="1" fill="currentColor"/><circle cx="10" cy="7.5" r="1" fill="currentColor"/><circle cx="15" cy="8" r="1" fill="currentColor"/>'),
  card: I('<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>'),
  eye: I('<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>'),
  bell: I('<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/>'),
  moon: I('<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>'),
  sun: I('<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>'),
  search: I('<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>'),
  menu: I('<path d="M4 6h16M4 12h16M4 18h16"/>'),
  plus: I('<path d="M12 5v14M5 12h14"/>'),
  check: I('<path d="M20 6 9 17l-5-5"/>'),
  x: I('<path d="M18 6 6 18M6 6l12 12"/>'),
  lock: I('<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>'),
  out: I('<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>'),
  server: I('<rect x="2" y="3" width="20" height="7" rx="2"/><rect x="2" y="14" width="20" height="7" rx="2"/><path d="M6 6.5h.01M6 17.5h.01"/>'),
  chart: I('<path d="M3 3v18h18"/><path d="M7 15v3M12 10v8M17 6v12"/>'),
  shield: I('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>'),
  sync: I('<path d="M21 12a9 9 0 1 1-2.64-6.36M21 3v6h-6"/>'),
  wallet: I('<path d="M20 7H4a2 2 0 0 1 0-4h14v4M20 7a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5"/><circle cx="17" cy="14" r="1.2" fill="currentColor"/>'),
  copy: I('<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>'),
  edit: I('<path d="M17 3a2.8 2.8 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>'),
  trash: I('<path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>'),
  alert: I('<path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>'),
  info: I('<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>'),
  back: I('<path d="M19 12H5M12 19l-7-7 7-7"/>'),
  more: I('<circle cx="5" cy="12" r="1.4" fill="currentColor"/><circle cx="12" cy="12" r="1.4" fill="currentColor"/><circle cx="19" cy="12" r="1.4" fill="currentColor"/>'),
  globe: I('<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18z"/>'),
  clock: I('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'),
  key: I('<circle cx="7.5" cy="15.5" r="4.5"/><path d="m11 12 9-9M17 5l2.5 2.5M14 8l2 2"/>'),
};
const ic = (n) => ICONS[n] || ICONS.info;

/* ── رجیستری منو (قرارداد AdminMenu) ── */
const MENU_CLIENT = [
  { group: "اصلی" },
  { id: "dash", title: "داشبورد", icon: "dash", path: "dashboard.html" },
  { id: "pages", title: "صفحات", icon: "page", path: "pages.html" },
  { id: "media", title: "فایل‌ها", icon: "media", path: "files.html" },
  { id: "tickets", title: "تیکت‌ها", icon: "ticket", path: "tickets.html", bdg: "۳" },
  { id: "subs", title: "اشتراک من", icon: "card", path: "subscription.html" },
  { group: "ظاهر سایت" },
  { id: "header", title: "هدر و فوتر", icon: "layout", path: "header-footer.html" },
  { id: "blocks", title: "بلوک‌های سایت", icon: "blocks", path: "blocks.html" },
  { id: "themes", title: "قالب‌ها", icon: "theme", path: "themes.html" },
  { id: "appearance", title: "قالب پنل", icon: "eye", path: "appearance.html" },
  { group: "تنظیمات" },
  { id: "settings", title: "تنظیمات سایت", icon: "gear", path: "settings.html" },
  { id: "socials", title: "شبکه‌های اجتماعی", icon: "share", path: "socials.html" },
  { id: "plugins", title: "پلاگین‌ها", icon: "plug", path: "plugins.html", bdg: "۱", soft: true },
  { id: "managers", title: "مدیران", icon: "users", path: "managers.html" },
  { id: "profile", title: "پروفایل", icon: "user", path: "profile.html" },
];
const MENU_CENTRAL = [
  { group: "نمای کلی" },
  { id: "cdash", title: "داشبورد مرکزی", icon: "dash", path: "dashboard.html" },
  { group: "مرکزی", central: true },
  { id: "clients", title: "مشتریان", icon: "users", path: "clients.html" },
  { id: "chabokan", title: "چابکان", icon: "server", path: "chabokan.html" },
  { id: "billing", title: "صورتحساب", icon: "wallet", path: "billing.html", bdg: "۲" },
  { id: "monitoring", title: "مانیتورینگ", icon: "chart", path: "monitoring.html" },
  { id: "updates", title: "آپدیت‌ها", icon: "sync", path: "updates.html" },
  { id: "operator", title: "پروفسور اپراتور", icon: "shield", path: "operator.html" },
];
const BN_CLIENT = ["dash", "pages", "media", "tickets", "subs"];
const BN_CENTRAL = ["cdash", "clients", "billing", "monitoring", "operator"];

/* ── تم (شبیه‌سازی ذخیره per-user) ── */
const Theme = {
  get() { try { return JSON.parse(localStorage.getItem("ui-demo-v2")) || {}; } catch { return {}; } },
  set(p) { localStorage.setItem("ui-demo-v2", JSON.stringify({ ...this.get(), ...p })); },
  apply(panel) {
    const s = this.get(), h = document.documentElement;
    /* پیش‌فرض پیش‌نمایش (تا انتخاب نهایی جهت در تسک ۰.۲): عملیات دارک برای هر دو پنل */
    h.dataset.direction = s.direction || "amaliyat";
    h.dataset.theme = s.theme || "dark";
    h.dataset.accent = s.accent || "indigo";
    h.dataset.radius = s.radius || "default";
    h.dataset.density = s.density || "comfortable";
    h.dataset.fontSize = s.fontSize || "md";
    document.querySelectorAll("[data-theme-ic]").forEach(b => b.innerHTML = ic(h.dataset.theme === "dark" ? "sun" : "moon"));
  }
};

/* ── رندر Shell ── */
function shell({ panel, active, title, sub, suspended }) {
  const menu = panel === "central" ? MENU_CENTRAL : MENU_CLIENT;
  const bnIds = panel === "central" ? BN_CENTRAL : BN_CLIENT;
  const isC = panel === "central";
  Theme.apply(panel);
  const navHtml = menu.map(m => {
    if (m.group) return `<div class="nav-group${m.central ? " central" : ""}">${m.group}</div>`;
    const lock = suspended && m.id !== "subs";
    return `<a class="nav-item${m.id === active ? " active" : ""}${lock ? " locked" : ""}" ${lock ? `onclick="toast('در حالت تعلیق فقط اشتراک فعال است')" tabindex="0"` : `href="${m.path}"`}>
      ${ic(m.icon)}<span class="nav-label">${m.title}</span>${m.bdg ? `<span class="nav-badge${m.soft ? " soft" : ""}">${m.bdg}</span>` : ""}${lock ? ic("lock") : ""}</a>`;
  }).join("");
  const bnHtml = bnIds.map(id => {
    const m = menu.find(x => x.id === id);
    return `<button class="bn-item${id === active ? " active" : ""}" onclick="location.href='${m.path}'">${ic(m.icon)}<span>${m.title}</span></button>`;
  }).join("");
  document.getElementById("shell").innerHTML = `
  <div class="app" id="app">
    <aside class="sidebar">
      <div class="brand"><div class="brand-mark">${isC ? "م" : "س"}</div>
        <div><div class="brand-name">${isC ? "پنل مرکزی" : M.user.site}</div>
        <div class="brand-sub">${isC ? "armanoskouei.ir/admin" : "پنل مدیریت مشتری"}</div></div></div>
      <nav class="nav">${navHtml}</nav>
      <div class="side-user"><div class="avatar">آ</div>
        <div class="u-info"><div style="font-size:13px;font-weight:700">${M.user.name}</div>
        <div style="font-size:11.5px;color:var(--text-muted)">${isC ? "اپراتور مرکزی" : M.user.role}</div></div></div>
    </aside>
    <div class="main">
      <header class="topbar">
        <button class="icon-btn" onclick="toggleDrawer()" aria-label="منو">${ic("menu")}</button>
        <button class="icon-btn" onclick="document.getElementById('app').classList.toggle('collapsed')" aria-label="جمع‌شدن" style="display:none" data-desk>${ic("menu")}</button>
        <div class="crumbs"><span class="crumb-root">${isC ? "پنل مرکزی" : "پنل مشتری"}</span><span>‹</span><b>${title}</b></div>
        <div class="top-search">${ic("search")}<input placeholder="جستجو…"></div>
        <button class="icon-btn" onclick="location.href='${isC ? "../client/dashboard.html" : "../central/dashboard.html"}'" title="سوییچ پنل">${ic("sync")}</button>
        <button class="icon-btn" onclick="cycleTheme('${panel}')" data-theme-ic title="تم"></button>
        <button class="icon-btn" onclick="toast('۲ اعلان خوانده‌نشده')" title="اعلان‌ها">${ic("bell")}<span class="dot"></span></button>
        <button class="icon-btn" onclick="location.href='../client/login.html'" title="خروج">${ic("out")}</button>
      </header>
      <main class="content">
        <div class="page-head"><div><h1>${title}</h1>${sub ? `<p>${sub}</p>` : ""}</div><div class="spacer"></div><div id="page-actions" style="display:flex;gap:10px;flex-wrap:wrap"></div></div>
        <div id="page"></div>
      </main>
    </div>
    <nav class="bottomnav"><div class="bn-row">${bnHtml}</div></nav>
  </div><div class="scrim" id="scrim" onclick="toggleDrawer(false)"></div>`;
  if (window.innerWidth > 1024) document.querySelector("[data-desk]").style.display = "grid";
  Theme.apply(panel); /* اعمال مجدد بعد از تزریق: آیکون دکمه تم + اتریبیوت‌ها */
}
function toggleDrawer(force) {
  const app = document.getElementById("app"), sc = document.getElementById("scrim");
  const open = force !== undefined ? force : !app.classList.contains("drawer-open");
  app.classList.toggle("drawer-open", open); sc.classList.toggle("show", open);
}
function cycleTheme(panel) {
  const h = document.documentElement;
  Theme.set({ theme: h.dataset.theme === "dark" ? "light" : "dark" });
  Theme.apply(panel);
}
const page = (html) => document.getElementById("page").innerHTML = html;
const actions = (html) => document.getElementById("page-actions").innerHTML = html;

/* ── ابزارهای تعاملی ── */
function toast(msg) {
  let w = document.querySelector(".toast-wrap");
  if (!w) { w = document.createElement("div"); w.className = "toast-wrap"; document.body.appendChild(w); }
  const t = document.createElement("div"); t.className = "toast"; t.textContent = msg;
  w.appendChild(t); setTimeout(() => t.remove(), 2600);
}
function openModal(html, lg) {
  closeOver();
  const o = document.createElement("div"); o.className = "overlay show"; o.id = "ovl";
  o.innerHTML = `<div class="modal${lg ? " lg" : ""}">${html}</div>`;
  o.onclick = e => { if (e.target === o) closeOver(); };
  document.body.appendChild(o);
}
function openDrawer(html) {
  closeOver();
  const o = document.createElement("div"); o.className = "overlay show"; o.id = "ovl";
  o.innerHTML = `<div class="drawer show" style="position:static;width:min(560px,100%);height:90vh;border-radius:var(--radius-lg)">${html}</div>`;
  o.style.display = "flex"; o.onclick = e => { if (e.target === o) closeOver(); };
  document.body.appendChild(o);
}
function closeOver() { document.getElementById("ovl")?.remove(); }
document.addEventListener("keydown", e => { if (e.key === "Escape") { closeOver(); toggleDrawer(false); } });
function confirmDlg({ title, text, danger, ok = "تأیید", onOk }) {
  window._ok = onOk;
  openModal(`<div class="modal-head">${ic(danger ? "alert" : "info")} ${title}</div>
    <div class="modal-body"><p style="margin:0;font-size:13.5px;line-height:1.9">${text}</p></div>
    <div class="modal-foot"><button class="btn ${danger ? "btn-danger" : "btn-primary"}" onclick="closeOver();window._ok&&window._ok()">${ok}</button>
    <button class="btn btn-ghost" onclick="closeOver()">انصراف</button></div>`);
}
/* جدول ساده: جستجو + مرتب‌سازی نمایشی */
function bindTable(inputId, tableId) {
  const inp = document.getElementById(inputId), tb = document.getElementById(tableId);
  if (!inp || !tb) return;
  inp.addEventListener("input", () => {
    const q = inp.value.trim();
    tb.querySelectorAll("tbody tr").forEach(tr => tr.style.display = tr.innerText.includes(q) ? "" : "none");
  });
}
