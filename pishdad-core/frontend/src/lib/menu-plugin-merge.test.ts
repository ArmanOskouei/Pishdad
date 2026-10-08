import test from "node:test";
import assert from "node:assert/strict";

import {
  ADMIN_MENU,
  PLUGIN_MENU_GROUP_TITLE,
  mergePluginMenu,
  type MenuGroup,
  type PluginMenuDeclaration,
} from "./menu.ts";

/**
 * K6.1 — ادغام `admin.menu` روی منوی هسته.
 *
 * هر سه قیدی که قرارداد از قبل وعده داده بود و هیچ کد آن‌ها را اجبار
 * نمی‌کرد، اینجا یکی‌یکی نگه داشته می‌شود:
 *
 *  ۱) برخورد `key` **قطعی** حل شود (نه last-write-wins بی‌صدا)
 *  ۲) آیتمِ بدون پرمیشنِ مدیر جاری دیده نشود (fail-closed)
 *  ۳) `href` بیرون از `/admin/` حذف شود
 *
 * قاعدهٔ `/admin/` از `plugin-admin-path.ts` قرض گرفته شده، پس تست عمداً
 * چند ورودیِ همان الگوی بک‌اند (`PluginPackageContract.php:217`) را
 * تکرار می‌کند: اگر آن قاعده عوض شد، این تست هم لنگ بیفتد.
 */

const ALL_PERMS = [
  "plugin:acme:posts.view",
  "plugin:globex:orders.view",
  "plugin:initech:reports.view",
  "plugin:acme:public.view",
];

const blog: PluginMenuDeclaration = {
  slug: "acme",
  key: "blog",
  label: "نوشته‌ها",
  href: "/admin/blog",
  icon: "✎",
  order: 10,
  permission: "plugin:acme:posts.view",
};

/* ── پایه: بدون اعلان، منو باید دقیقاً همان منوی امروز بماند ── */

test("K6.1: بدون اعلان، خروجی عیناً همان منوی هسته است (نه گروه خالی)", () => {
  const out = mergePluginMenu(ADMIN_MENU, [], ALL_PERMS);
  assert.deepEqual(out.groups, ADMIN_MENU);
  assert.deepEqual(out.accepted, []);
  assert.deepEqual(out.rejected, []);
  // گروه «افزونه‌ها» نباید وقتی چیزی برای نمایش نیست ساخته شود.
  assert.equal(
    out.groups.some((g) => g.title === PLUGIN_MENU_GROUP_TITLE),
    false,
  );
});

test("K6.1: همهٔ ردها هم یعنی بازگشت به منوی هسته، بدون گروه اضافه", () => {
  const out = mergePluginMenu(
    ADMIN_MENU,
    [
      { slug: "acme", key: "x", label: "بیرون", href: "/sampleplug/x" },
      { slug: "acme", key: "y", label: "محرم", href: "/admin/y", permission: "plugin:acme:nope" },
    ],
    ALL_PERMS,
  );
  assert.deepEqual(out.groups, ADMIN_MENU);
  assert.equal(out.rejected.length, 2);
});

/* ── قاعدهٔ ۱: برخورد key ── */

test("K6.1/قاعدهٔ۱: برخورد key با هسته ⇒ هسته می‌برد و افزونه رد می‌شود", () => {
  const out = mergePluginMenu(
    ADMIN_MENU,
    [{ slug: "evil", key: "dashboard", label: "ربودن داشبورد", href: "/admin/dashboard-hijack" }],
    ALL_PERMS,
  );
  assert.deepEqual(out.accepted, []);
  assert.equal(out.rejected.length, 1);
  assert.equal(out.rejected[0].reason, "key");
  assert.equal(out.rejected[0].key, "dashboard");
  // آیتم هسته سر جایش مانده و دست‌نخورده است.
  const first = out.groups[0].items[0];
  assert.equal(first.key, "dashboard");
  assert.equal(first.href, "/admin/dashboard");
  assert.equal(first.label, "داشبورد");
});

test("K6.1/قاعدهٔ۱: برخورد key بین دو افزونه — برنده به ترتیب فید وابسته نیست", () => {
  // دو افزونه، `key` یکسان، `order` یکسان ⇒ فقط `slug` تصمیم می‌گیرد.
  const globex: PluginMenuDeclaration = {
    slug: "globex",
    key: "reports",
    label: "گزارش‌ها",
    href: "/admin/globex-reports",
    order: 20,
  };
  const initech: PluginMenuDeclaration = {
    slug: "initech",
    key: "reports",
    label: "گزارش‌های دیگر",
    href: "/admin/initech-reports",
    order: 20,
  };

  const a = mergePluginMenu(ADMIN_MENU, [globex, initech], ALL_PERMS);
  const b = mergePluginMenu(ADMIN_MENU, [initech, globex], ALL_PERMS);

  // چون slug مقایسهٔ رشته‌ای دارد، «globex» از «initech» کوچک‌تر است.
  assert.deepEqual(
    a.accepted.map((i) => i.href),
    ["/admin/globex-reports"],
  );
  // اینجاست که «قطعی» معنا پیدا می‌کند: همان ورودی، هر ترتیبی ⇒ همان خروجی.
  assert.deepEqual(a.accepted, b.accepted);
  assert.deepEqual(a.rejected, b.rejected);
  assert.equal(a.rejected[0].reason, "key");
  assert.equal(a.rejected[0].slug, "initech");
});

test("K6.1/قاعدهٔ۱: `order` صریح، ترتیب برخورد و ترتیب نمایش را با هم تعیین می‌کند", () => {
  const first: PluginMenuDeclaration = {
    slug: "zzz-last-slug",
    key: "reports",
    label: "الف",
    href: "/admin/zzz-reports",
    order: 5,
  };
  const second: PluginMenuDeclaration = {
    slug: "aaa-first-slug",
    key: "reports",
    label: "ب",
    href: "/admin/aaa-reports",
    order: 50,
  };
  // با وجود اینکه slug دوم از اولی «کوچک‌تر» است، order پایین‌تر اول می‌آید
  // ⇒ همان که می‌برد، همان است که کاربر اول می‌بیند.
  const out = mergePluginMenu(ADMIN_MENU, [second, first], ALL_PERMS);
  assert.deepEqual(
    out.accepted.map((i) => i.href),
    ["/admin/zzz-reports"],
  );
  assert.equal(out.rejected[0].slug, "aaa-first-slug");
});

test("K6.1/قاعدهٔ۱: اعلان تکراریِ یک افزونه هم برخورد است و بی‌صدا نمی‌ماند", () => {
  const out = mergePluginMenu(
    ADMIN_MENU,
    [
      { slug: "acme", key: "blog", label: "اول", href: "/admin/blog" },
      { slug: "acme", key: "blog", label: "دوم", href: "/admin/blog-2" },
    ],
    ALL_PERMS,
  );
  assert.equal(out.accepted.length, 1);
  assert.equal(out.accepted[0].label, "اول");
  assert.equal(out.rejected[0].reason, "key");
});

/* ── قاعدهٔ ۲: پرمیشن ── */

test("K6.1/قاعدهٔ۲: آیتمی که پرمیشنش داده نشده دیده نمی‌شود", () => {
  const out = mergePluginMenu(ADMIN_MENU, [blog], ["plugin:globex:orders.view"]);
  assert.deepEqual(out.accepted, []);
  assert.equal(out.rejected[0].reason, "permission");
  assert.equal(out.rejected[0].key, "blog");
  assert.deepEqual(out.groups, ADMIN_MENU);
});

test("K6.1/قاعدهٔ۲: پرمیشن داده‌شده ⇒ آیتم دیده می‌شود", () => {
  const out = mergePluginMenu(ADMIN_MENU, [blog], ["plugin:acme:posts.view"]);
  assert.equal(out.accepted.length, 1);
  assert.equal(out.accepted[0].href, "/admin/blog");
  assert.equal(out.accepted[0].icon, "✎");
  assert.deepEqual(out.rejected, []);
});

test("K6.1/قاعدهٔ۲: فهرست پرمیشن خالی = هیچ، نه «همه» (fail-closed)", () => {
  const out = mergePluginMenu(ADMIN_MENU, [blog], []);
  assert.deepEqual(out.accepted, []);
  assert.equal(out.rejected[0].reason, "permission");
});

test("K6.1/قاعدهٔ۲: `permission` غایب یعنی «عمومی» — بدون نیاز به پرمیشن", () => {
  const out = mergePluginMenu(ADMIN_MENU, [{ slug: "acme", key: "pub", label: "عمومی", href: "/admin/pub" }], []);
  assert.equal(out.accepted.length, 1);
  assert.deepEqual(out.rejected, []);
});

/* ── قاعدهٔ ۳: قید /admin/ ── */

test("K6.1/قاعدهٔ۳: href بیرون از /admin/ حذف می‌شود", () => {
  for (const bad of [
    "http://evil.com",         // مطلق ⇒ open redirect
    "//evil.com",              // protocol-relative
    "javascript:alert(1)",     // XSS
    "/sampleplug/orders",         // ریشهٔ مرکزی
    "/orders",                 // بدون ریشه
    "/admin/../x",             // traversal — از پیشوند بیرون می‌زند
    "/adminx",                 // پیشوند دیگر
  ]) {
    const out = mergePluginMenu(
      ADMIN_MENU,
      [{ slug: "evil", key: "bad", label: "بد", href: bad }],
      ALL_PERMS,
    );
    assert.deepEqual(out.accepted, [], `باید رد شود: ${bad}`);
    assert.equal(out.rejected[0].reason, "href", `باید دلیلش href باشد: ${bad}`);
    assert.deepEqual(out.groups, ADMIN_MENU, `منو نباید تغییر کند: ${bad}`);
  }
});

test("K6.1/قاعدهٔ۳: دروازهٔ href همان قاعدهٔ K6.6 است، نه نسخهٔ تازه", () => {
  // همان ورودی‌های تست K6.6؛ اگر این‌ها رد نشوند، یعنی اینجا قاعدهٔ سومی
  // نوشته شده و دو لایه از هم جدا افتاده‌اند.
  const out = mergePluginMenu(
    ADMIN_MENU,
    [
      { slug: "acme", key: "ok", label: "خوب", href: "/admin/shop/orders" },
      { slug: "acme", key: "bad", label: "بد", href: "https://evil.test" },
    ],
    ALL_PERMS,
  );
  assert.deepEqual(
    out.accepted.map((i) => i.href),
    ["/admin/shop/orders"],
  );
  assert.equal(out.rejected[0].reason, "href");
});

/* ── برخورد href (قاعدهٔ افزوده) ── */

test("K6.1: افزونه نمی‌تواند `href` صفحهٔ موجود را دوباره تعریف کند", () => {
  const out = mergePluginMenu(
    ADMIN_MENU,
    [{ slug: "evil", key: "shadow", label: "سایهٔ صفحات", href: "/admin/pages" }],
    ALL_PERMS,
  );
  assert.deepEqual(out.accepted, []);
  assert.equal(out.rejected[0].reason, "href_duplicate");
});

/* ── شکل دادهٔ نامعتبر (ورودی از شبکه است) ── */

test("K6.1: اعلان ناقص یا نامعتبر رد می‌شود و رندر را نمی‌شکند", () => {
  // دادهٔ این دروازه از شبکه می‌آید، پس تست عمداً چیزی می‌دهد که TypeScript
  // اجازهٔ پاس‌دادنش را نمی‌دهد — همان چیزی که سرور واقعاً می‌فرستد.
  const raw: unknown[] = [
    null,
    "blog",
    ["blog"],
    { slug: "acme", key: "a", href: "/admin/a" },                                   // بدون label
    { slug: "acme", key: "a", label: "", href: "/admin/a" },                         // label خالی
    { key: "a", label: "بدون slug", href: "/admin/a" },                             // بدون slug
    { slug: "acme", key: "a", label: "order غیرعدد", href: "/admin/a", order: "3" },
    { slug: "acme", key: "a", label: "order اعشاری", href: "/admin/a", order: 1.5 },
    { slug: "acme", key: "a", label: "icon نامعتبر", href: "/admin/a", icon: 5 },
    { slug: "acme", key: "a", label: "permission خالی", href: "/admin/a", permission: "  " },
  ];
  const out = mergePluginMenu(ADMIN_MENU, raw as PluginMenuDeclaration[], ALL_PERMS);
  assert.deepEqual(out.accepted, []);
  assert.equal(out.rejected.length, raw.length);
  for (const r of out.rejected) assert.equal(r.reason, "shape");
  assert.deepEqual(out.groups, ADMIN_MENU);
});

test("K6.1: ورودی غیرآرایه مثل null هم منو را سالم نگه می‌دارد", () => {
  for (const junk of [null, undefined, "nope", 42]) {
    const out = mergePluginMenu(
      ADMIN_MENU,
      junk as unknown as PluginMenuDeclaration[],
      ALL_PERMS,
    );
    assert.deepEqual(out.groups, ADMIN_MENU);
    assert.deepEqual(out.accepted, []);
    assert.deepEqual(out.rejected, []);
  }
});

/* ── جای‌گذاری گروه ── */

/**
 * L-B12: لنگرِ جای‌گذاری دیگر گروه `مرکزی` نیست، چون هسته نباید بداند چه
 * افزونه‌ای وجود دارد. نشانهٔ عمومی `pluginsAfter` جای آن را گرفت — و تست هم
 * دادهٔ **خودش** را می‌دهد، نه اینکه به وجود یک گروه خاص در `ADMIN_MENU`
 * تکیه کند. اگر روزی افزونهٔ دیگری لنگر شد، این تست بی‌دلیل نمی‌شکند.
 */
const withAnchor: MenuGroup[] = [
  { items: [{ href: "/admin/a", label: "الف", icon: "▪", key: "a" }] },
  { title: "لنگر", pluginsAfter: true, items: [{ href: "/admin/b", label: "ب", icon: "▪", key: "b" }] },
];

test("K6.1: گروه افزونه‌ها قبل از گروه لنگر می‌نشیند", () => {
  const out = mergePluginMenu(withAnchor, [blog], ALL_PERMS);
  const titles = out.groups.map((g) => g.title);
  const pluginAt = titles.indexOf(PLUGIN_MENU_GROUP_TITLE);
  const anchorAt = out.groups.findIndex((g) => g.pluginsAfter === true);
  assert.ok(pluginAt !== -1, "گروه افزونه‌ها باید ساخته شود");
  assert.ok(anchorAt !== -1, "گروه لنگر باید بماند");
  assert.ok(pluginAt < anchorAt, "گروه افزونه‌ها باید قبل از لنگر بنشیند");
});

test("K6.1: بدون لنگر، گروه افزونه‌ها ته لیست می‌نشیند", () => {
  const noAnchor: MenuGroup[] = [
    { items: [{ href: "/admin/a", label: "الف", icon: "▪", key: "a" }] },
  ];
  const out = mergePluginMenu(noAnchor, [blog], ALL_PERMS);
  assert.equal(out.groups.at(-1)?.title, PLUGIN_MENU_GROUP_TITLE);
});

test("K6.1: آیتم بی‌آیکون آیکون پیش‌فرض می‌گیرد (MenuItem.icon اجباری است)", () => {
  const out = mergePluginMenu(ADMIN_MENU, [{ slug: "acme", key: "noicon", label: "بدون آیکون", href: "/admin/noicon" }], ALL_PERMS);
  assert.equal(out.accepted[0].icon.length > 0, true);
});

test("K6.1: منوی هسته هرگز تغییر نمی‌کند — فقط یک گروه اضافه می‌شود", () => {
  const before = ADMIN_MENU.map((g) => g.items);
  const out = mergePluginMenu(ADMIN_MENU, [blog], ALL_PERMS);

  // تنها تفاوت باید «یک گروه اضافه» باشد؛ هر آیتم هسته باید بی‌هیچ تغییری
  // سر جایش مانده باشد. چون `ADMIN_MENU` دیگر لنگر ندارد، گروه تازه ته می‌نشیند.
  assert.equal(out.groups.length, ADMIN_MENU.length + 1);
  assert.deepEqual(out.groups.slice(0, ADMIN_MENU.length).map((g) => g.items), before);
  assert.deepEqual(out.groups.at(-1), {
    title: PLUGIN_MENU_GROUP_TITLE,
    // I1-b — عنوانِ گروه، برخلاف برچسبِ آیتم‌ها، متعلق به هسته است (نه به
    // افزونه‌نویس) پس کلیدِ ترجمه دارد. آیتم‌ها `labelKey` ندارند و نباید داشته
    // باشند: برچسب، زبانِ خودِ افزونه است.
    titleKey: "menu.group.plugins",
    items: out.accepted,
  });
  // و خودِ ثابت دست‌نخورده است (نه فقط مقدارش) — و گروه افزونه از قبل در آن
  // نبوده. (L-B12 عدد سخت‌کدِ ۶ را برداشت: گروه `مرکزی` حذف شد، پس شمارشِ
  // دقیق یک چیز وابسته به شکلِ امروزِ منو بود که با هر تغییرِ بی‌ربط می‌شکست.)
  assert.equal(ADMIN_MENU.some((g) => g.title === PLUGIN_MENU_GROUP_TITLE), false);
  assert.ok(ADMIN_MENU.length > 0, "منوی هسته نباید خالی باشد");
});
