import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

/**
 * K6.13 — نگهبانِ «پیش‌نمایش زندهٔ بلوک از همان رندرر».
 *
 * شکافِ اصلی این بود که `PageEditor` یک نسخهٔ **دوم** از رندر بلوک‌ها داشت
 * (if-chain دستی) و `BlockRenderer` را هرگز صدا نمی‌زد. یعنی ادیتور و سایت
 * عمومی می‌توانستند از هم واگرا شوند و بلوکِ افزونه در ادیتور دیده نمی‌شد.
 * این تست قفل می‌کند که یکی‌سازی باقی بماند — چون تکرار دوباره بسیار آسان است.
 */

const ROOT = fileURLToPath(new URL("../", import.meta.url));
const read = (p: string) => readFileSync(ROOT + p, "utf8");

const EDITOR = read("app/(client)/admin/pages/[id]/edit/PageEditor.tsx");
const RENDERER = read("components/site/BlockRenderer.tsx");
const DECLARED = read("components/site/DeclaredBlock.tsx");

test("PageEditor از BlockRenderer استفاده می‌کند، نه نسخهٔ دوم", () => {
  assert.match(EDITOR, /import\s+\{\s*BlockRenderer\s*\}\s+from\s+"@\/components\/site\/BlockRenderer"/);
  assert.match(EDITOR, /<BlockRenderer/, "پیش‌نمایش باید همان رندرر را رندر کند.");
});

test("پیش‌نمایش با diagnostics روشن است", () => {
  // پارامتری که مخصوص همین کار نوشته شده بود و بی‌استفاده مانده بود.
  assert.match(EDITOR, /diagnostics/, "diagnostics باید پاس شود.");
});

test("پیش‌نمایش schema را از رجیستری پاس می‌دهد (مسیر schema-only)", () => {
  // بدون schema، `x-pattern` افزونه هرگز خوانده نمی‌شود و بلوک اعلانی به
  // نگاشتِ نوع برمی‌گردد — یعنی نیمی از قابلیت K6.12 در ادیتور غایب می‌ماند.
  assert.match(EDITOR, /schemas=/, "پیش‌نمایش باید schemas را پاس کند.");
});

test("منطقِ نگاشت در lib است، نه داخل کامپوننت", () => {
  // دلیل: کامپوننت در `node --test` ترجمه نمی‌شود؛ اگر نگاشت این‌جا باشد،
  // آزمون واقعی نمی‌شود و دوباره شاهدِ ادعا می‌شویم نه اثبات.
  assert.match(RENDERER, /from\s+"@\/lib\/block-pattern"/);
});

test("DeclaredBlock هر ۱۱ الگو را می‌پوشاند و switch هندسی ندارد", () => {
  // if-chain عمدی است تا نگهبانِ «برچسب‌های case = واژگان هسته» در
  // `block-type.test.ts` الگوهای عمومی را بلوکِ هسته نبیند.
  assert.equal(/\bswitch\s*\(/.test(DECLARED), false, "DeclaredBlock نباید switch داشته باشد.");
  assert.match(DECLARED, /"default"/, "الگوی fallback باید صریح باشد.");
  for (const p of ["hero", "image", "grid", "list", "gallery", "form", "rich-text", "embed", "faq", "quote", "cta"]) {
    assert.match(DECLARED, new RegExp(`"${p}"`), `الگوی ${p} باید هندل شود.`);
  }
});

test("DeclaredBlock هیچ‌جا کد افزونه اجرا نمی‌کند (بدون eval/Function/import دینامیک)", () => {
  assert.equal(/\beval\s*\(/.test(DECLARED), false, "eval ممنوع.");
  assert.equal(/new\s+Function\s*\(/.test(DECLARED), false, "Function سازنده ممنوع.");
  assert.equal(/await\s+import\s*\(/.test(DECLARED), false, "import دینامیک ممنوع.");
});
