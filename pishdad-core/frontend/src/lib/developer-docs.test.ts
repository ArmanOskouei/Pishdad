import test from "node:test";
import assert from "node:assert/strict";

import { pick, prettyJson } from "./developer-docs.ts";

/**
 * ECO3 — انتخاب زبان محتوای قرارداد.
 *
 * چرا این تست: قرارداد برای هر متن دو نسخه دارد (`label_fa`/`label_en`).
 * اگر `pick` در زبان انگلیسی به فارسی برنگردد وقتی انگلیسی نیست، UI یک
 * **سطر خالی** نشان می‌دهد — همان چیزی که در راهنما یعنی «ناپدید شدن».
 */

test("زبان انگلیسی نسخهٔ انگلیسی را ترجیح می‌دهد", () => {
  assert.equal(pick("en", "فارسی", "English"), "English");
  assert.equal(pick("fa", "فارسی", "English"), "فارسی");
});

test("نبودِ نسخهٔ زبانِ جاری به زبان دیگر می‌افتد، نه به رشتهٔ خالی", () => {
  assert.equal(pick("en", "فارسی", undefined), "فارسی");
  assert.equal(pick("en", "فارسی", "   "), "فارسی");
  assert.equal(pick("fa", undefined, "English"), "English");
});

test("هر دو خالی/ناموجود ⇒ رشتهٔ خالی، نه undefined", () => {
  assert.equal(pick("en", undefined, undefined), "");
  assert.equal(pick("fa", undefined, undefined), "");
});

test("prettyJson آرایه/آبجکت را با تورفتگی برمی‌گرداند", () => {
  assert.equal(prettyJson({ a: 1 }), '{\n  "a": 1\n}');
  assert.equal(prettyJson(null), "null");
});
