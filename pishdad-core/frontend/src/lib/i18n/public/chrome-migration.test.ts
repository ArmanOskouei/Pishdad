import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

/**
 * ECO2 — نگهبانِ «هیچ متنِ فارسیِ هاردکد در کرومِ سایتِ عمومی نمانده».
 *
 * همان ایدهٔ `../chrome-migration.test.ts` برای پنل: این یکی از آن دسته‌ای
 * نیست که «کار می‌کند ولی برمی‌گردد» — نیمه برمی‌گردد. کسی یک رشتهٔ فارسی را
 * در JSX می‌گذارد، فارسی‌ها درست می‌بینند و هیچ تستی قرمز نمی‌شود، تا وقتی
 * بازدیدکنندهٔ انگلیسی صفحهٔ انگلیسی را با یک سطر فارسی می‌بیند.
 *
 * کامنت‌های `/* … *​/` و `//` اول حذف می‌شوند؛ وگرنه نگهبان روی فارسیِ خودِ
 * مستنداتِ کد قرمز می‌شد (یعنی به‌جای کد، توضیحِ کد را نقد می‌کرد).
 */

const HERE = dirname(fileURLToPath(import.meta.url));
const read = (p: string) => readFileSync(resolve(HERE, p), "utf8");

const codeLines = (src: string): string[] =>
  src
    .replace(/\/\*[\s\S]*?\*\//g, "")
    .replace(/^\s*\/\/.*$/gm, "")
    .split("\n");

/**
 * کرومِ عمومی — فایل‌هایی که متنِ نمایشیِ فارسی‌شان باید از فرهنگِ لغت بیاید.
 * `PublicPage.tsx` بیرون است چون رشتهٔ اولیهٔ آن به `t()` منتقل شده و متنِ
 * فارسیِ باقی‌مانده عمداً صفر است.
 */
const PUBLIC_FILES = [
  "../../../components/site/Chrome.tsx",
  "../../../components/site/SiteNav.tsx",
  "../../../components/site/SiteSearchBox.tsx",
  "../../../components/site/SiteSearchResults.tsx",
];

test("هیچ متنِ نمایشیِ فارسیِ هاردکد در کرومِ عمومی نمانده", () => {
  const offenders: string[] = [];
  for (const f of PUBLIC_FILES) {
    codeLines(read(f)).forEach((line, i) => {
      if (!/[\u0600-\u06FF]/.test(line)) return;
      offenders.push(`${f}:${i + 1}  ${line.trim()}`);
    });
  }
  assert.deepEqual(
    offenders,
    [],
    "این‌ها متنِ فارسیِ هاردکد در کرومِ سایت‌اند — باید از `publicT` بیایند:\n  " + offenders.join("\n  "),
  );
});

test("اعلان‌های بلوک/ویجتِ ناشناس نسخهٔ زبان‌دار دارند", () => {
  // `unknownBlockNoticeFa`/`unknownWidgetNoticeFa` عمداً می‌مانند (پیش‌فرضِ
  // فارسی)، ولی رندررِ عمومی باید نسخهٔ زبان‌دار را صدا بزند.
  const block = read("../../../components/site/BlockRenderer.tsx");
  assert.match(block, /unknownBlockNotice\(locale, type\)/, "BlockRenderer باید زبان را پاس بدهد.");
  assert.doesNotMatch(
    block,
    /unknownBlockNoticeFa\(type\)/,
    "BlockRenderer نباید نسخهٔ فارسیِ ثابت را رندر کند.",
  );

  const chrome = read("../../../components/site/Chrome.tsx");
  assert.match(chrome, /unknownWidgetNotice\(locale, w\.type\)/, "ChromeWidget باید زبان را پاس بدهد.");
  assert.doesNotMatch(
    chrome,
    /unknownWidgetNoticeFa\(w\.type\)/,
    "ChromeWidget نباید نسخهٔ فارسیِ ثابت را رندر کند.",
  );
});
