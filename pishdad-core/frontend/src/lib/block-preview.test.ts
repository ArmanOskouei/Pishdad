import test from "node:test";
import assert from "node:assert/strict";

import { mediaIdsOf, withResolvedMedia } from "./block-preview.ts";

/**
 * K6.13 — آماده‌سازی دادهٔ بلوک برای پیش‌نمایش. این تابع پلِ بین `mediaCache`
 * ادیتور و کلیدهایی است که `BlockRenderer` می‌خواند؛ اگر اشتباه باشد، پیش‌نمایش
 * تصویری نشان نمی‌دهد در حالی که داده سالم است.
 */

test("mediaIdsOf سه شکل رسانه را جمع می‌کند", () => {
  assert.deepEqual(mediaIdsOf({ media_id: 5 }), [5]);
  assert.deepEqual(mediaIdsOf({ image_id: 7 }), [7]);
  assert.deepEqual(mediaIdsOf({ media_ids: [1, 2, 3] }), [1, 2, 3]);
  assert.deepEqual(mediaIdsOf({ media_id: 5, media_ids: [6] }), [5, 6]);
});

test("mediaIdsOf مقادیر نامعتبر و تکراری‌نما را رد می‌کند", () => {
  assert.deepEqual(mediaIdsOf({}), []);
  assert.deepEqual(mediaIdsOf({ media_id: 0 }), []);
  assert.deepEqual(mediaIdsOf({ media_id: -3 }), []);
  assert.deepEqual(mediaIdsOf({ media_id: "5" }), []);
  assert.deepEqual(mediaIdsOf({ media_id: NaN }), []);
  assert.deepEqual(mediaIdsOf({ media_ids: [0, -1, "x", null, 2] }), [2]);
});

test("withResolvedMedia تصویر تک را به url نگاشت می‌کند", () => {
  const out = withResolvedMedia({ media_id: 5, alt: "a" }, { 5: "https://x/y.png" });
  assert.equal(out.url, "https://x/y.png");
  assert.equal(out.alt, "a", "کلیدهای اصلی باید دست‌نخورده بمانند");
  assert.equal(out.media_id, 5);
});

test("withResolvedMedia هرگز url موجود را بازنویسی نمی‌کند", () => {
  const out = withResolvedMedia({ media_id: 5, url: "https://keep/me.png" }, { 5: "https://x/y.png" });
  assert.equal(out.url, "https://keep/me.png");
});

test("withResolvedMedia گالری را به url_0..N نگاشت می‌کند", () => {
  const out = withResolvedMedia(
    { media_ids: [10, 20] },
    { 10: "https://x/a.png", 20: "https://x/b.png" },
  );
  assert.equal(out.url_0, "https://x/a.png");
  assert.equal(out.url_1, "https://x/b.png");
});

test("withResolvedMedia نگاشتِ نیافته را رد می‌کند", () => {
  const out = withResolvedMedia({ media_ids: [10, 20] }, { 10: "https://x/a.png" });
  assert.equal(out.url_0, "https://x/a.png");
  assert.equal("url_1" in out, false, "id بدون نگاشت نباید url جعلی بگیرد");
});

test("withResolvedMedia داده بدون رسانه را دست‌نخورده برمی‌گرداند", () => {
  const data = { title: "سلام", align: "center" };
  const out = withResolvedMedia(data, { 5: "https://x/y.png" });
  assert.deepEqual(out, data);
  assert.notEqual(out, data, "باید کپی باشد نه همان شیء (state React)");
});
