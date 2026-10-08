import test from "node:test";
import assert from "node:assert/strict";

import {
  BLOCK_PATTERNS,
  PATTERN_SCHEMA_KEY,
  declaredPattern,
  selectBlockPattern,
} from "./block-pattern.ts";

/**
 * K6.12 — انتخاب الگوی رندر.
 *
 * این تست روی منطقِ **خالص** اجرا می‌شود (بدون React)، چون هدف این است که
 * نگاشتِ بلوک→الگو واقعاً آزموده شود، نه صرفاً ادعا. رندرر فقط `switch`
 * روی همین برچسب می‌زند.
 */

test("۱۱ الگو دقیقاً همان مجموعهٔ قرارداد است", () => {
  assert.deepEqual(
    [...BLOCK_PATTERNS].sort(),
    ["hero", "image", "grid", "list", "gallery", "form", "rich-text", "embed", "faq", "quote", "cta"].sort(),
  );
  assert.equal(new Set(BLOCK_PATTERNS).size, 11, "الگوها باید بی‌تکرار باشند");
  assert.equal(Object.isFrozen(BLOCK_PATTERNS), true, "واژگان نباید از بیرون تغییر کند");
});

/* ───────────── ۱. نگاشتِ بلوک‌های هسته ───────────── */

test("بلوک‌های هسته به الگوی درست نگاشت می‌شوند", () => {
  const cases: Array<[string, string]> = [
    ["hero", "hero"],
    ["image", "image"],
    ["text", "rich-text"],
    ["gallery", "gallery"],
    ["quote", "quote"],
    ["video", "embed"],
    ["faq", "faq"],
    ["cta", "cta"],
    ["contact-form", "form"],
    ["contact", "form"],
  ];
  for (const [type, pattern] of cases) {
    assert.equal(selectBlockPattern(type), pattern, `${type} ⇒ ${pattern}`);
  }
});

test("هر الگو از راه نام هم‌نام خودش قابل انتخاب است", () => {
  for (const pattern of BLOCK_PATTERNS) {
    assert.equal(selectBlockPattern(pattern), pattern, pattern);
  }
});

/* ───────────── ۲. x-pattern صریح ───────────── */

test("x-pattern صریح بر نگاشت نوع مقدم است", () => {
  const schema = { properties: { [PATTERN_SCHEMA_KEY]: { default: "list" } } };
  assert.equal(selectBlockPattern("price_table", schema), "list");
  // حتی روی نوع هسته هم اگر افزونه صریح بگوید، حرفِ schema برنده است.
  assert.equal(selectBlockPattern("hero", schema), "list");
});

test("enum تک‌عضوی هم به‌عنوان x-pattern پذیرفته می‌شود", () => {
  const schema = { properties: { [PATTERN_SCHEMA_KEY]: { enum: ["grid"] } } };
  assert.equal(declaredPattern(schema), "grid");
});

test("enum چندعضوی یا مقدار نامعتبر، x-pattern نیست", () => {
  assert.equal(declaredPattern({ properties: { [PATTERN_SCHEMA_KEY]: { enum: ["grid", "list"] } } }), null);
  assert.equal(declaredPattern({ properties: { [PATTERN_SCHEMA_KEY]: { default: "not_a_pattern" } } }), null);
  assert.equal(declaredPattern({ properties: { [PATTERN_SCHEMA_KEY]: { default: 7 } } }), null);
});

/* ───────────── ۳. ناشناس/خراب ⇒ default ───────────── */

test("نوع ناشناس بدون schema به default می‌رود", () => {
  for (const type of ["price_table", "acme_hero_x", "تبلیغ", "order"]) {
    assert.equal(selectBlockPattern(type), "default", type);
  }
});

test("default خودش یک الگوی رندر معتبر است ولی در فهرست ۱۱گانه نیست", () => {
  // `default` کلیدواژهٔ «متنِ ساده» است، نه یکی از ۱۱ الگوی اعلامی. این تمایز
  // مهم است: قرارداد فقط ۱۱ الگو دارد؛ fallback داخلی است.
  assert.equal((BLOCK_PATTERNS as readonly string[]).includes("default"), false);
});

test("ورودیِ غیررشته یا خراب هرگز throw نمی‌کند", () => {
  for (const type of [undefined, null, 42, true, {}, [], Symbol("x"), ""]) {
    assert.equal(selectBlockPattern(type as unknown as string), "default", String(type));
  }
  // schema خراب
  assert.equal(selectBlockPattern("hero", { properties: null }), "hero");
  assert.equal(selectBlockPattern("hero", {} as never), "hero");
});

test("کلیدهای prototype هرگز از نگاشت بیرون نمی‌زنند", () => {
  for (const type of ["constructor", "toString", "valueOf", "__proto__", "hasOwnProperty"]) {
    assert.equal(selectBlockPattern(type), "default", type);
  }
});
