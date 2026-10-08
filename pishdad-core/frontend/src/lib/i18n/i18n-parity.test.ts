import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

/**
 * F4.4 — نگهبانِ تقارنِ فرهنگِ لغت.
 *
 * ## چرا این تست به‌جای `tsc`
 *
 * `en.ts` با `Record<MessageKey, string>` تایپ شده، پس کلیدِ جاافتاده در
 * `tsc` قرمز می‌شود. ولی `npm test` بدون typecheck اجرا می‌شود و در CI ممکن
 * است جدا بیفتد. پس یک نگهبانِ متنی هم هست که فقط به فایل نگاه می‌کند.
 *
 * ## چرا regex و نه import
 *
 * `npm test` با `node --test` فایل‌های TS را بدون resolver اجرا می‌کند؛
 * specifierِ نسبیِ بدون پسوند resolve نمی‌شود (همان محدودیتی که `menu.ts` را
 * بی‌import نگه داشته). پس اینجا فقط **متن** خوانده می‌شود.
 *
 * ⚠️ اگر روزی نحوِ فایل‌ها عوض شد و این تست الگو پیدا نکرد، **تست باید قرمز
 * شود نه سبز** — به همین دلیل `keysOf` صریحاً خطا می‌دهد وقتی هیچ کلیدی پیدا
 * نکند، نه اینکه آرایهٔ خالی برگرداند.
 */

const HERE = dirname(fileURLToPath(import.meta.url));
const read = (p: string) => readFileSync(resolve(HERE, p), "utf8");

/** کلیدهای `"...":` در سطحِ بلوکِ اصلیِ شیء (نه درون `values`). */
function keysOf(src: string): string[] {
  const out: string[] = [];
  // کلیدهای چندقسطی با نقل‌قول:  `"a.b.c": "…",`
  for (const m of src.matchAll(/^\s{2}"([a-z][\w.]*)":/gm)) out.push(m[1]!);
  assert.ok(out.length > 0, "هیچ کلیدی پیدا نشد — نحوِ فایل فرهنگِ لغت عوض شده؟ این تست باید صدا بزند نه اینکه سبزِ ساکت بماند.");
  return out;
}

const faKeys = keysOf(read("./fa.ts"));
const enKeys = keysOf(read("./en.ts"));

test("هر کلیدِ فارسی ترجمهٔ انگلیسی دارد", () => {
  const missing = faKeys.filter((k) => !enKeys.includes(k));
  assert.deepEqual(missing, [], `کلیدهای بی‌ترجمه (ترجمه‌شان اضافه کن یا از fa حذف کن): ${missing.join("، ")}`);
});

test("ترجمهٔ انگلیسی کلیدِ یتیم ندارد", () => {
  // کلیدِ یتیم یعنی کلیدی که `fa` ندارد ⇒ `tsc` با `Record<MessageKey>` قرمز
  // می‌شود، ولی اگر تایپ‌ها دور زده شوند، این تست آخرین خط دفاع است.
  const orphans = enKeys.filter((k) => !faKeys.includes(k));
  assert.deepEqual(orphans, [], `کلیدهای یتیم در en.ts: ${orphans.join("، ")}`);
});

test("ترجمهٔ انگلیسی خالی نیست", () => {
  const src = read("./en.ts");
  const empty = enKeys.filter((k) => {
    const m = new RegExp(`^\\s{2}"${k.replace(/\./g, "\\.")}":\\s*"([^"]*)"`, "m").exec(src);
    return !m || m[1]!.trim() === "";
  });
  assert.deepEqual(empty, [], `مقدارِ خالی یعنی ترجمه‌نشده: ${empty.join("، ")}`);
});

test("جهت و زبان یک منبعِ حقیقت دارند", () => {
  const src = read("./index.tsx");
  assert.match(
    src,
    /langFromDir[\s\S]{0,200}?dir === "ltr" \? "en" : "fa"/,
    "تبدیلِ جهت→زبان باید صریح باشد.",
  );
  assert.match(
    src,
    /dirFromLang[\s\S]{0,200}?=== "en" \? "ltr" : "rtl"/,
    "تبدیلِ زبان→جهت باید صریح باشد — انگلیسی یعنی حتماً ltr.",
  );
});

test("کلیدِ ناشناخته به رشتهٔ خالی برنمی‌گردد", () => {
  // `undefined` در React یعنی «هیچ رندر نکن» و کل عنصر ناپدید می‌شود؛ یعنی یک
  // ترجمهٔ جاافتاده به‌جای متن، **سطر را حذف** می‌کرد.
  const src = read("./index.tsx");
  assert.match(src, /\?\? fa\[key\] \?\? key/, "افتادنِ کلید باید به خودِ کلید برسد، نه undefined.");
});

test("لایهٔ زبان در روت نصب شده و `html.lang` را می‌نویسد", () => {
  const root = read("../../app/layout.tsx");
  assert.match(root, /<LanguageProvider>/, "LanguageProvider باید در روت باشد.");
  assert.match(root, /h\.lang=h\.dir==="ltr"/, "اسکریپتِ ضد FOUC هم باید lang را بگذارد، وگرنه صفحه یک لحظه با lang=fa رندر می‌شود.");

  const theme = read("../theme.tsx");
  assert.match(theme, /h\.lang = t\.dir === "ltr" \? "en" : "fa";/, "applyTheme باید lang را کنارِ dir بنویسد — نه یک effect جدا.");
});
