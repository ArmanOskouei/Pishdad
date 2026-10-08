import test from "node:test";
import assert from "node:assert/strict";

import { crumbsForPath, findCrumbHit } from "./crumbs.ts";
import type { MenuGroup } from "./menu.ts";

/**
 * K6.9 — breadcrumb پنل، که تا امروز هیچ تستی نداشت.
 *
 * این مسیر تمام راه ناوبری پنل ادمین را می‌سازد. اگر خراب شود، کاربر در هر صفحه
 * گم می‌شود و هیچ پیام خطایی هم نمی‌بیند — فقط برچسب غلط.
 */

const GROUPS: MenuGroup[] = [
  {
    title: "محتوا",
    items: [
      { href: "/admin/pages", label: "صفحات", icon: "file", key: "pages" },
      { href: "/admin/plugins", label: "افزونه‌ها", icon: "plug", key: "plugins" },
    ],
  },
  {
    title: "سیستم",
    items: [
      { href: "/admin/settings", label: "تنظیمات", icon: "gear", key: "settings" },
      { href: "/admin/settings/site", label: "تنظیمات سایت", icon: "gear", key: "settings.site" },
    ],
  },
];

const ROOT = ["پنل"];

test("مسیر دقیق، برچسب همان آیتم را می‌گیرد", () => {
  assert.deepEqual(crumbsForPath("/admin/pages", GROUPS, ROOT), ["پنل", "محتوا", "صفحات"]);
});

test("زیرمسیره، برچسب عمیق‌ترین آیتم منطبق را می‌گیرد", () => {
  // `/admin/settings/site` هم زیرمجموعهٔ `/admin/settings` است و هم خودش آیتم است.
  // اگر قاعدهٔ «بلندترین href» نبود، برچسب «تنظیمات» می‌آمد نه «تنظیمات سایت».
  assert.deepEqual(crumbsForPath("/admin/settings/site", GROUPS, ROOT), [
    "پنل",
    "سیستم",
    "تنظیمات سایت",
  ]);
});

test("مسیری که آیتمی ندارد فقط ریشه را برمی‌گرداند", () => {
  assert.deepEqual(crumbsForPath("/admin/nope", GROUPS, ROOT), ["پنل"]);
});

test("تطبیق روی مرز مسیر انجام می‌شود، نه پیشوند خام", () => {
  // `/admin/plug` نباید زیرمجموعهٔ `/admin/plugins` شمرده شود. اگر پیشوند خام
  // مقایسه می‌شد، crumb غلط می‌ساخت و آیتمی را نشان می‌داد که در آن صفحه نیست.
  assert.equal(findCrumbHit("/admin/plug", GROUPS), null);
  assert.deepEqual(crumbsForPath("/admin/plug", GROUPS, ROOT), ["پنل"]);
});

test("تکرار مجاور حذف می‌شود", () => {
  // گروهی با همان عنوان ریشه نباید breadcrumb را دوبار تکرار کند.
  const groups: MenuGroup[] = [
    { title: "پنل", items: [{ href: "/admin/x", label: "ایکس", icon: "i", key: "x" }] },
  ];
  assert.deepEqual(crumbsForPath("/admin/x", groups, ["پنل"]), ["پنل", "ایکس"]);
});

test("برچسب از منو می‌آید، نه از URL", () => {
  // اگر برچسب از URL ساخته می‌شد، کاربر `page_registry` را می‌دید به‌جای
  // «فهرست صفحات».
  const groups: MenuGroup[] = [
    {
      title: "محتوا",
      items: [{ href: "/admin/page_registry", label: "فهرست صفحات", icon: "i", key: "pr" }],
    },
  ];
  assert.deepEqual(crumbsForPath("/admin/page_registry", groups, ROOT), [
    "پنل",
    "محتوا",
    "فهرست صفحات",
  ]);
});

test("آیتم بدون گروه، crumb دو بخشی می‌سازد", () => {
  const groups: MenuGroup[] = [
    { items: [{ href: "/admin/solo", label: "تنها", icon: "i", key: "solo" }] },
  ];
  assert.deepEqual(crumbsForPath("/admin/solo", groups, ROOT), ["پنل", "تنها"]);
});

test("منوی خالی فقط ریشه می‌دهد و خطا نمی‌دهد", () => {
  assert.deepEqual(crumbsForPath("/admin/anything", [], ROOT), ["پنل"]);
});

test("ریشهٔ خالی هم قابل‌استفاده است", () => {
  const groups: MenuGroup[] = [
    { title: "محتوا", items: [{ href: "/admin/pages", label: "صفحات", icon: "i", key: "p" }] },
  ];
  assert.deepEqual(crumbsForPath("/admin/pages", groups, []), ["محتوا", "صفحات"]);
});

test("اولین برنده در برخورد، گزارش‌نشده بازنویسی نمی‌شود", () => {
  // دو گروه همان مسیر را می‌دهند. رجیستری منو سمت سرور تکراری‌ها را
  // نمی‌پذیرد، ولی اگر روزی پذیرفت، crumb نباید بی‌صدا عوض شود.
  const groups: MenuGroup[] = [
    { title: "الف", items: [{ href: "/admin/dup", label: "الف", icon: "i", key: "a" }] },
    { title: "ب", items: [{ href: "/admin/dup", label: "ب", icon: "i", key: "b" }] },
  ];
  assert.equal(findCrumbHit("/admin/dup", groups)?.label, "الف", "اولین آیتم منطبق باید برنده باشد.");
});
