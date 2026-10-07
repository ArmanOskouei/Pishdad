import { test } from "node:test";
import assert from "node:assert/strict";

import { filterMenuByPlugins } from "./menu-plugin-visibility.ts";
import { ADMIN_MENU, type MenuGroup, type MenuItem } from "./menu.ts";

/**
 * رگرسیونِ «آیتم پلاگین بعد از غیرفعال‌سازی در منو ماند».
 *
 * گزارشِ کاربر: یک آیتمِ افزونه‌ای که در `ADMIN_MENU` **هاردکد** شده بود، با
 * غیرفعال‌کردنِ افزونه‌اش از منو نرفت.
 *
 * ریشه: آن آیتم عضوِ هسته بود نه عضوِ افزونه — و هیچ کش یا پاک‌سازی‌ای نمی‌توانست
 * آن را بردارد. راه درست، برچسب‌زدنِ وابستگی با `requiresPlugin` و فیلتر کردن بر
 * اساس وضعیت واقعی.
 *
 * ⚠️ نهادِ نمونه دیگر «اشتراک من» نیست: آن صفحه و کلِ لایهٔ اشتراک با پروژه رفت.
 * fixtureهای زیر یک آیتمِ **افزونه‌ایِ فرضی** می‌سازند تا خودِ قانونِ فیلتر آزموده
 * شود، نه یک محصولِ مشخص — وگرنه هر تست با هر محصولِ حذف‌شده‌ای می‌افتاد.
 */

const groups = (...items: MenuItem[]): MenuGroup[] => [{ title: "افزونه‌ها", items }];

const pluginItem: MenuItem = {
  href: "/admin/sampleplug",
  label: "نمونه",
  icon: "◆",
  key: "sampleplug",
  requiresPlugin: "sampleplug",
};

const coreItem: MenuItem = {
  href: "/admin/pages",
  label: "صفحات",
  icon: "▤",
  key: "pages",
};

const keys = (gs: MenuGroup[]): string[] => gs.flatMap((g) => g.items.map((i) => i.key));

test("an item whose plugin is active stays", () => {
  const out = filterMenuByPlugins(groups(pluginItem), new Set(["sampleplug"]));
  assert.deepEqual(keys(out), ["sampleplug"]);
});

test("an item whose plugin is inactive disappears", () => {
  const out = filterMenuByPlugins(groups(pluginItem), new Set([]));
  assert.deepEqual(keys(out), []);
});

test("another plugin being active does not bring it back", () => {
  const out = filterMenuByPlugins(groups(pluginItem), new Set(["otherplug"]));
  assert.deepEqual(keys(out), []);
});

test("core items are never filtered", () => {
  const out = filterMenuByPlugins(groups(coreItem), new Set([]));
  assert.deepEqual(keys(out), ["pages"]);
});

test("an empty group is removed entirely", () => {
  // گروهی با تنها آیتمِ افزونه‌ای نباید عنوانِ خالی نگه دارد.
  const out = filterMenuByPlugins(groups(pluginItem), new Set([]));
  assert.equal(out.length, 0);
});

test("unknown state is fail-closed", () => {
  // `null` یعنی «نمی‌دانیم». نمایشِ لینکی که شاید ۴۰۴ بدهد بدتر از نبودنش است.
  const out = filterMenuByPlugins(groups(pluginItem, coreItem), null);
  assert.deepEqual(keys(out), ["pages"]);
});

test("no core menu item pretends to belong to a plugin", () => {
  // برخلاف آیتم‌هایی که واقعاً مالِ افزونه‌اند، بقیهٔ آیتم‌های هسته مالِ خودِ
  // هسته‌اند. برچسب‌زدنشان یعنی با افزونهٔ غیرفعال، منوی پنل خالی می‌شد.
  const owned = ADMIN_MENU
    .flatMap((g) => g.items)
    .filter((i) => i.requiresPlugin !== undefined)
    .map((i) => i.key);

  assert.deepEqual(owned, []);
});

test("every core menu item points inside the admin panel", () => {
  // نگهبانِ ساختاری: هر آیتمِ منو باید مسیری در خودِ پنل بدهد. آیتمی که به
  // جای دیگری اشاره کند یعنی یا اشتباه تایپ شده یا به صفحه‌ای می‌رود که در
  // این نصب وجود ندارد.
  const hrefs = ADMIN_MENU
    .flatMap((g) => g.items)
    .flatMap((i) => [i.href, ...(i.children ?? []).map((c) => c.href)]);

  const outside = hrefs.filter((h) => !h.startsWith("/admin/"));
  assert.deepEqual(outside, [], "مسیرِ بیرون از پنل در منو: " + outside.join("، "));
});