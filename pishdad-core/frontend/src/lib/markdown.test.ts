import { strict as assert } from "node:assert";
import { readFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { test } from "node:test";
import { fileURLToPath } from "node:url";

import { markdownToHtml, splitCells } from "./markdown.ts";

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(HERE, "..", "..", "..", "..");
const PUBLIC = join(REPO, "pishdad-core", "frontend", "public");

/**
 * E65 — راهنماهای توسعه‌دهنده (قالب/افزونه) از Markdown به HTML تبدیل می‌شوند و
 * همان HTML مستقیم در صفحه می‌نشیند. پس مبدل، مسیرِ **دیده‌شدنِ** محتواست:
 * اگر جایی کم بیاورد، کاربر یک راهنمای به‌هم‌ریخته می‌بیند و هیچ تستِ دیگری آن
 * را نمی‌گیرد (کامپوننت JSX دارد و در `node --test` قابلِ import نیست).
 */

test("سرتیتر، لیست، نقل‌قول، جدول و بلوک کد به HTML تبدیل می‌شوند", () => {
  const html = markdownToHtml(
    [
      "# عنوان",
      "",
      "- یک",
      "- دو",
      "",
      "1. اول",
      "2. دوم",
      "",
      "> نقل",
      "",
      "| a | b |",
      "|---|---|",
      "| 1 | 2 |",
      "",
      "---",
      "",
      "```",
      "const x = 1;",
      "```",
    ].join("\n"),
  );

  assert.match(html, /<h1>عنوان<\/h1>/);
  assert.match(html, /<ul>[\s\S]*<li>یک<\/li>[\s\S]*<\/ul>/);
  assert.match(html, /<ol>[\s\S]*<li>اول<\/li>[\s\S]*<\/ol>/);
  assert.match(html, /<blockquote>نقل<\/blockquote>/);
  assert.match(html, /<table><thead><tr><th>a<\/th><th>b<\/th><\/tr><\/thead><tbody><tr><td>1<\/td><td>2<\/td><\/tr><\/tbody><\/table>/);
  assert.match(html, /<hr\/>/);
  assert.match(html, /<pre><code>const x = 1;<\/code><\/pre>/);
  assert.equal(html.includes("```"), false, "حصارِ بلوک کد نباید در خروجی بماند");
});

/** Markdown دادهٔ خام نیست، ولی اگر روزی بشود باید بی‌خطر باشد (defense in depth). */
test("HTML درونِ Markdown escape می‌شود (بدون XSS)", () => {
  const html = markdownToHtml("# <script>alert(1)</script>");
  assert.equal(html.includes("<script>"), false);
  assert.match(html, /&lt;script&gt;/);
});

test("پایپِ escape‌شده سلول را نمی‌شکند", () => {
  assert.deepEqual(splitCells("| a\\|b | c |"), ["a|b", "c"]);
});

/**
 * سندِ واقعی: راهنمای افزونه ~۴۸KB است و چند بلوک تودرتوی جدول/لیست/کد دارد.
 * اینجا همان سندِ **سروشده** (نه سندِ docs) تبدیل می‌شود — همان چیزی که
 * مرورگر می‌گیرد.
 */
for (const file of ["plugin-guide.fa.md", "plugin-guide.en.md"]) {
  test(`سندِ واقعی ${file} کامل و متوازن رندر می‌شود`, () => {
    const md = readFileSync(join(PUBLIC, file), "utf8");
    const html = markdownToHtml(md);

    assert.ok(html.length > md.length / 2, "خروجی بی‌دلیل کوتاه است");
    assert.equal(html.includes("```"), false, "حصارِ بلوک کد در خروجی مانده");
    assert.match(html, /<h1>/);
    assert.match(html, /<h2>/);
    assert.ok((html.match(/<table>/g) ?? []).length >= 5, "جدول‌های سند رندر نشده‌اند");
    assert.ok((html.match(/<pre><code>/g) ?? []).length >= 5, "بلوک‌های کدِ سند رندر نشده‌اند");

    // توازنِ تگ‌ها: هیچ تگی باز نماند (وگرنه چیدمانِ راهنما می‌شکند).
    for (const tag of ["table", "thead", "tbody", "tr", "ul", "ol", "li", "pre", "code", "blockquote"]) {
      const open = (html.match(new RegExp(`<${tag}[ >]`, "g")) ?? []).length;
      const close = (html.match(new RegExp(`</${tag}>`, "g")) ?? []).length;
      assert.equal(open, close, `تگِ <${tag}> نامتوازن است (${open}/${close})`);
    }
  });
}
