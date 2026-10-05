import test from "node:test";
import assert from "node:assert/strict";

import { ADMIN_MENU, BOTTOM_NAV_OPTIONS, localizeMenuGroups, mergePluginMenu } from "./menu.ts";
import { filterMenuByPlugins } from "./menu-plugin-visibility.ts";
import { findCrumbHit } from "./crumbs.ts";

/**
 * ECO3 — زیرمنوی «مستندات توسعه‌دهندگان».
 *
 * منو یک `const` تودرتو شد تا صفحهٔ مستندات یک لینک واقعی داشته باشد، نه
 * فقط یک مسیر قابل‌تایپ. این تست چهار قید را نگه می‌دارد: وجود آیتم،
 * ترجمه‌شدن فرزند، دیده‌شدنش در crumb، و اضافه‌شدنش به گزینه‌های BottomNav.
 */

const DEVELOPERS = "/admin/plugins/developers";

function pluginsItem() {
  for (const group of ADMIN_MENU) {
    const item = group.items.find((i) => i.href === "/admin/plugins");
    if (item) return item;
  }
  return undefined;
}

test("آیتم «مستندات توسعه‌دهندگان» زیر پلاگین‌ها هست", () => {
  const item = pluginsItem();
  assert.ok(item, "آیتم پلاگین‌ها پیدا نشد.");
  const child = item!.children?.find((c) => c.href === DEVELOPERS);
  assert.ok(child, "زیرمنوی مستندات توسعه‌دهندگان نیست.");
  assert.equal(child!.labelKey, "devdocs.title");
});

test("بومی‌سازی، برچسبِ فرزند را ترجمه می‌کند", () => {
  const t = (key: string) => (key === "devdocs.title" ? "DEV DOCS" : key);
  const localized = localizeMenuGroups(ADMIN_MENU, t as never);
  const child = localized
    .flatMap((g) => g.items)
    .flatMap((i) => i.children ?? [])
    .find((c) => c.href === DEVELOPERS);
  assert.equal(child?.label, "DEV DOCS");
});

test("crumb مسیر مستندات، عمیق‌ترین آیتم را می‌گیرد", () => {
  const hit = findCrumbHit(DEVELOPERS, ADMIN_MENU);
  assert.equal(hit?.href, DEVELOPERS);
});

test("گزینهٔ زیرمنو در BottomNav هست", () => {
  assert.ok(
    BOTTOM_NAV_OPTIONS.some((i) => i.href === DEVELOPERS),
    "زیرمنو در گزینه‌های نوار پایین نیامده.",
  );
});

test("ادغام افزونه، زیرمنوی هسته را دست‌نخورده می‌گذارد", () => {
  const merged = mergePluginMenu(ADMIN_MENU, [], []);
  const item = merged.groups.flatMap((g) => g.items).find((i) => i.href === "/admin/plugins");
  assert.ok(item?.children?.some((c) => c.href === DEVELOPERS));
});

test("فیلتر افزونه، فرزندانی که نیاز به افزونه ندارند را نگه می‌دارد", () => {
  const filtered = filterMenuByPlugins(ADMIN_MENU, new Set());
  const item = filtered.flatMap((g) => g.items).find((i) => i.href === "/admin/plugins");
  assert.ok(item?.children?.some((c) => c.href === DEVELOPERS));
});
