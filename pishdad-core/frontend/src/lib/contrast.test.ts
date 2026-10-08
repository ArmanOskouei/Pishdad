import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

import {
  AA_LARGE,
  AA_NORMAL,
  classifyContrast,
  contrastCheck,
  contrastRatio,
  formatRatio,
  parseColor,
  relativeLuminance,
} from "./contrast.ts";

const near = (actual: number, expected: number, tolerance = 0.01) =>
  assert.ok(
    Math.abs(actual - expected) <= tolerance,
    `انتظار ${expected} ± ${tolerance} بود، ${actual} آمد.`,
  );

/* ───────────────────────── ۱. تجزیهٔ رنگ ───────────────────────── */

test("hex سه‌رقمی و شش‌رقمی هر دو خوانده می‌شوند", () => {
  assert.deepEqual(parseColor("#fff"), { r: 255, g: 255, b: 255 });
  assert.deepEqual(parseColor("#000000"), { r: 0, g: 0, b: 0 });
  assert.deepEqual(parseColor("#6366F1"), { r: 99, g: 102, b: 241 });
  assert.deepEqual(parseColor("#abc"), { r: 170, g: 187, b: 204 });
});

test("آلفا در hex و در rgba نادیده گرفته می‌شود", () => {
  assert.deepEqual(parseColor("#ffffff00"), { r: 255, g: 255, b: 255 });
  assert.deepEqual(parseColor("#fff0"), { r: 255, g: 255, b: 255 });
  assert.deepEqual(parseColor("rgba(99, 102, 241, 0.5)"), { r: 99, g: 102, b: 241 });
  assert.deepEqual(parseColor("rgb(99 102 241 / 50%)"), { r: 99, g: 102, b: 241 });
});

test("rgb با فاصله و درصد هم پشتیبانی می‌شود", () => {
  assert.deepEqual(parseColor("rgb(0, 0, 0)"), { r: 0, g: 0, b: 0 });
  assert.deepEqual(parseColor("rgb(100%, 0%, 0%)"), { r: 255, g: 0, b: 0 });
  assert.deepEqual(parseColor("  #FFF  "), { r: 255, g: 255, b: 255 });
});

test("ورودیِ نامعتبر null می‌دهد، نه رنگِ سرانگشتی", () => {
  for (const bad of [
    "",
    "   ",
    "transparent",
    "currentColor",
    "color-mix(in srgb, var(--x) 16%, transparent)",
    "#12",
    "#gggggg",
    "rgb(1,2)",
    "rgb(a, b, c)",
    "hsl(220 90% 60%)",
  ]) {
    assert.equal(parseColor(bad), null, `«${bad}» باید null می‌داد.`);
  }
});

test("کانال‌های خارج از بازه به ۰..۲۵۵ محدود می‌شوند", () => {
  assert.deepEqual(parseColor("rgb(300, -5, 128)"), { r: 255, g: 0, b: 128 });
});

/* ─────────────────── ۲. درخشندگیِ نسبی ─────────────────── */

test("درخشندگیِ سفید ۱ و سیاه ۰ است", () => {
  near(relativeLuminance("#ffffff"), 1);
  near(relativeLuminance("#000000"), 0);
});

test("درخشندگیِ نامعتبر NaN است", () => {
  assert.ok(Number.isNaN(relativeLuminance("nope")));
});

/* ─────────────────── ۳. نسبتِ کنتراست ─────────────────── */

test("سفید/سیاه دقیقاً ۲۱ و رنگِ یکسان دقیقاً ۱ است", () => {
  near(contrastRatio("#ffffff", "#000000"), 21);
  near(contrastRatio("#000000", "#ffffff"), 21);
  near(contrastRatio("#123456", "#123456"), 1);
});

test("نسبت متقارن است — ترتیب مهم نیست", () => {
  near(contrastRatio("#777777", "#ffffff"), contrastRatio("#ffffff", "#777777"));
});

test("جفت‌های شناخته‌شده روی مرزِ AA درست سنجیده می‌شوند", () => {
  // مرجعِ کلاسیک: #767676 روی سفید دقیقاً AA را رد می‌کند (۴.۵۴).
  near(contrastRatio("#767676", "#ffffff"), 4.54, 0.02);
  // #777777 کمی پایین‌تر است و باید AA عادی را بیفتد ولی متن بزرگ را پاس کند.
  near(contrastRatio("#777777", "#ffffff"), 4.48, 0.02);
  // قرمز روی سفید ~۴.۰ — متن عادی نه، متن بزرگ بله.
  near(contrastRatio("#ff0000", "#ffffff"), 4.0, 0.05);
});

test("ورودیِ نامعتبر نسبتِ NaN می‌دهد", () => {
  assert.ok(Number.isNaN(contrastRatio("color-mix(...)", "#fff")));
});

/* ─────────────────── ۴. دسته‌بندی ─────────────────── */

test("آستانه‌های متن عادی: ۷ برای AAA و ۴.۵ برای AA", () => {
  assert.equal(classifyContrast(21), "passAAA");
  assert.equal(classifyContrast(7), "passAAA");
  assert.equal(classifyContrast(6.99), "passAA");
  assert.equal(classifyContrast(AA_NORMAL), "passAA");
  assert.equal(classifyContrast(4.49), "fail");
  assert.equal(classifyContrast(1), "fail");
});

test("آستانه‌های متن بزرگ: ۴.۵ برای AAA و ۳ برای AA", () => {
  assert.equal(classifyContrast(4.5, true), "passAAA");
  assert.equal(classifyContrast(4.49, true), "passAA");
  assert.equal(classifyContrast(AA_LARGE, true), "passAA");
  assert.equal(classifyContrast(2.99, true), "fail");
});

test("نسبتِ نامعتبر fail است، نه استثنا", () => {
  assert.equal(classifyContrast(NaN), "fail");
  assert.equal(classifyContrast(Infinity), "fail");
});

/* ─────────────────── ۵. نتیجهٔ آمادهٔ UI ─────────────────── */

test("contrastCheck بولین‌های AA/AAA را درست پر می‌کند", () => {
  const blackOnWhite = contrastCheck("#000000", "#ffffff");
  near(blackOnWhite.ratio, 21);
  assert.equal(blackOnWhite.level, "passAAA");
  assert.equal(blackOnWhite.passAA, true);
  assert.equal(blackOnWhite.passAAA, true);

  const whiteOnRed = contrastCheck("#ffffff", "#ff0000", { large: true });
  assert.equal(whiteOnRed.level, "passAA");
  assert.equal(whiteOnRed.passAA, true);
  assert.equal(whiteOnRed.passAAA, false);
});

test("contrastCheck روی رنگ یکسان fail می‌دهد", () => {
  const same = contrastCheck("#abcdef", "#abcdef");
  near(same.ratio, 1);
  assert.equal(same.level, "fail");
  assert.equal(same.passAA, false);
});

test("formatRatio نامعتبر را با خطِ تیره نشان می‌دهد", () => {
  assert.equal(formatRatio(21), "21.00");
  assert.equal(formatRatio(4.5421), "4.54");
  assert.equal(formatRatio(NaN), "—");
});

/* ─────────── ۶. قفلِ اتصال: ویرایشگر واقعاً از این ماژول استفاده کند ─────────── */

const EDITOR_PATH = fileURLToPath(
  new URL("../app/(client)/admin/themes/site-theme/SiteThemeEditor.tsx", import.meta.url),
);
const editorSource = readFileSync(EDITOR_PATH, "utf8");

test("ویرایشگرِ قالب سایت سنجشِ کنتراست را به‌کار می‌گیرد", () => {
  assert.match(editorSource, /from "@\/lib\/contrast"/, "ویرایشگر باید از `lib/contrast` ایمپورت کند.");
  assert.match(editorSource, /contrastCheck\(/, "ویرایشگر باید `contrastCheck` را صدا بزند.");
  assert.match(editorSource, /CONTRAST_PAIRS/, "جفت‌های پُرمصرف باید صریح تعریف شده باشند.");
});

test("سنجش کنتراست ذخیره را مسدود نمی‌کند — فقط هشدار است", () => {
  assert.match(
    editorSource,
    /disabled=\{!dirty \|\| busy\}/,
    "دکمهٔ ذخیره باید همان شرطِ قبلی را نگه دارد؛ کنتراست نباید به `disabled` اضافه شود.",
  );
  assert.doesNotMatch(
    editorSource,
    /disabled=\{[^}]*contrast/i,
    "هیچ دکمه‌ای نباید بر پایهٔ نتیجهٔ کنتراست غیرفعال شود.",
  );
});
