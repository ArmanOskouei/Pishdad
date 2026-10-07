import { test } from "node:test";
import assert from "node:assert/strict";

import { filterMenuByPlugins } from "./menu-plugin-visibility.ts";
import { ADMIN_MENU, ADMIN_MENU_LOCKED, type MenuGroup, type MenuItem } from "./menu.ts";

/**
 * رگرسیونِ «آیتم پلاگین بعد از غیرفعال‌سازی در منو ماند».
 *
 * گزارشِ کاربر: پلاگین `subscription` را غیرفعال کرد، ولی «اشتراک من» در
 * بخش مالیِ منو ماند.
 *
 * ریشه: آن آیتم در `ADMIN_MENU` **هاردکد** بود، پس عضوِ هسته بود نه عضوِ
 * افزونه — و هیچ کش یا پاک‌سازی‌ای نمی‌توانست آن را بردارد. راه درست،
 * برچسب‌زدنِ وابستگی با `requiresPlugin` و فیلتر کردن بر اساس وضعیت واقعی.
 */

const groups = (...items: MenuItem[]): MenuGroup[] => [{ title: "مالی", items }];

const subItem: MenuItem = {
  href: "/admin/subscription",
  label: "اشتراک من",
  icon: "◈",
  key: "subscription",
  requiresPlugin: "subscription",
};

const coreItem: MenuItem = {
  href: "/admin/pages",
  label: "صفحات",
  icon: "▤",
  key: "pages",
};

const keys = (gs: MenuGroup[]): string[] => gs.flatMap((g) => g.items.map((i) => i.key));

test("an item whose plugin is active stays", () => {
  const out = filterMenuByPlugins(groups(subItem), new Set(["subscription"]));
  assert.deepEqual(keys(out), ["subscription"]);
});

test("an item whose plugin is inactive disappears", () => {
  const out = filterMenuByPlugins(groups(subItem), new Set([]));
  assert.deepEqual(keys(out), []);
});

test("another plugin being active does not bring it back", () => {
  const out = filterMenuByPlugins(groups(subItem), new Set(["sampleplug"]));
  assert.deepEqual(keys(out), []);
});

test("core items are never filtered", () => {
  const out = filterMenuByPlugins(groups(coreItem), new Set([]));
  assert.deepEqual(keys(out), ["pages"]);
});

test("an empty group is removed entirely", () => {
  // گروه «مالی» با تنها آیتمِ اشتراک نباید عنوانِ خالی نگه دارد.
  const out = filterMenuByPlugins(groups(subItem), new Set([]));
  assert.equal(out.length, 0);
});

test("unknown state is fail-closed", () => {
  // `null` یعنی «نمی‌دانیم». نمایشِ لینکی که شاید ۴۰۴ بدهد بدتر از نبودنش است.
  const out = filterMenuByPlugins(groups(subItem, coreItem), null);
  assert.deepEqual(keys(out), ["pages"]);
});

test("the real admin menu marks the subscription item as plugin-owned", () => {
  // نگهبانِ خودِ داده: اگر کسی `requiresPlugin` را از آیتم اشتراک برداشت،
  // این تست می‌افتند و باگ برمی‌گردد.
  const item = ADMIN_MENU.flatMap((g) => g.items).find((i) => i.key === "subscription");

  assert.ok(item, "آیتم اشتراک باید در منوی هسته باشد");
  assert.equal(
    item.requiresPlugin,
    "subscription",
    "آیتم اشتراک باید به افزونهٔ subscription نسبت داده شود",
  );
});

test("no other core menu item pretends to belong to a plugin", () => {
  // بقیهٔ آیتم‌های هسته واقعاً مالِ هسته‌اند. برچسب‌زدنشان یعنی با افزونهٔ
  // غیرفعال، منوی پنل خالی می‌شد.
  const owned = ADMIN_MENU
    .flatMap((g) => g.items)
    .filter((i) => i.requiresPlugin !== undefined)
    .map((i) => i.key);

  assert.deepEqual(owned, ["subscription"]);
});

test("the suspension menu keeps the payment page even if it is filtered elsewhere", () => {
  // حالتِ تعلیق یعنی اشتراک وجود دارد و فقط معلق است. اگر این آیتم هم
  // `requiresPlugin` داشت، کاربر در قفل گیر می‌افتاد: نه پرداخت، نه توضیح.
  const locked = ADMIN_MENU_LOCKED.flatMap((g) => g.items);
  assert.equal(locked.length, 1);
  assert.equal(locked[0].requiresPlugin, undefined);
});
