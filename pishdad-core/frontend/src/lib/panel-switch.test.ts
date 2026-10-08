import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

/**
 * سوییچِ پنل داده‌محور است، نه هاردکد.
 *
 * این تست‌ها عمداً **متنِ** `menu.ts` و `Topbar.tsx` و `Shell.tsx` را می‌خوانند
 * (همان ترفند `block-type.test.ts`): سوییچ نباید هیچ مسیرِ پنلِ بیرونی را در
 * کد داشته باشد. حذف یک افزونه باید بدون تغییر کد، سوییچ را ببرد — و این
 * فقط وقتی تضمین است که کدی برای بردن نمانده باشد.
 *
 * کامنت‌ها اول پاک می‌شوند تا ادعاها روی *کد* باشند: توضیحِ «چرا چیزی رفت»
 * در کامنت، هاردکد نیست و نباید تست را قرمز کند.
 */

const root = (rel: string): string =>
  readFileSync(fileURLToPath(new URL(rel, import.meta.url)), "utf8");

const stripComments = (source: string): string =>
  source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\/\/[^\n]*/g, "");

const menu = stripComments(root("./menu.ts"));
const topbar = stripComments(root("../components/layout/Topbar.tsx"));
const shell = stripComments(root("../components/layout/Shell.tsx"));

test("core menu has no sampleplug routes — removing the plugin removes the entries", () => {
  assert.ok(!menu.includes("/sampleplug"), "menu.ts نباید هیچ مسیر /sampleplug داشته باشد.");
});

test("core menu has no plugin-identity flag — only an ordering anchor", () => {
  assert.ok(!menu.includes("sampleplug:"), "نشانهٔ هویتی sampleplug نباید بماند.");
  assert.ok(menu.includes("pluginsAfter"), "لنگر عمومی جای‌گذاری باید بماند.");
});

test("Topbar renders the switch from props, not from hardcoded panel paths", () => {
  assert.ok(!topbar.includes("/sampleplug"), "Topbar نباید مسیر /sampleplug را بشناسد.");
  assert.ok(!topbar.includes("SAMPLEPLUGIN"), "Topbar نباید نام افزونه‌ای را بشناسد.");
  assert.match(topbar, /groups\?:\s*MenuGroup\[\]/, "سوییچ باید از groups بیاید.");
  assert.ok(topbar.includes("altPanels"), "سوییچ باید از آیتم‌های غیر-/admin منو ساخته شود.");
});

test("Shell feeds the live menu into Topbar", () => {
  // I1-b — `localized` همان `groups`ِ props است، فقط با `label`/`title` ترجمه‌شده
  // (`localizeMenuGroups`). نکتهٔ تست سرِ جای‌ماندن است: Topbar باید منوی
  // **زنده** را بگیرد، نه یک منوی هاردکد — و ترجمه هیچ‌کدام را ثابت نکرده.
  assert.ok(shell.includes("groups={localized}"), "Shell باید منوی زنده را به Topbar بدهد.");
  assert.match(shell, /localizeMenuGroups\(groups, t\)/, "ترجمه باید از همان prop بیاید، نه از یک منوی جدا.");
});
