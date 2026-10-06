import test from "node:test";
import assert from "node:assert/strict";

import {
  serpCharState,
  serpLength,
  serpUrlParts,
  truncateForSerp,
  SERP_DESC_MAX,
  SERP_DESC_MIN,
  SERP_TITLE_MAX,
  SERP_TITLE_MIN,
} from "./seo-preview.ts";

/**
 * WF-M1 — پیش‌نمایش SERP هیچ تستی نداشت. مرزهای شمارش و برش دقیقاً همان
 * چیزی‌اند که رنگِ شمارنده و متن نمایشی را تعیین می‌کنند؛ خطای آن به کاربر
 * سیگنال غلط می‌دهد.
 */

test("serpLength نویسه‌های فارسی را درست می‌شمارد", () => {
  assert.equal(serpLength("سلام"), 4);
  assert.equal(serpLength(""), 0);
});

test("serpLength ایموجی (فراسوی BMP) را یک کاراکتر می‌شمارد", () => {
  assert.equal(serpLength("a🙂b"), 3);
  assert.equal("a🙂b".length, 4);
});

test("serpCharState مرزهای بازهٔ عنوان را محترم می‌شمارد", () => {
  assert.equal(serpCharState(SERP_TITLE_MIN - 1, SERP_TITLE_MIN, SERP_TITLE_MAX), "short");
  assert.equal(serpCharState(SERP_TITLE_MIN, SERP_TITLE_MIN, SERP_TITLE_MAX), "good");
  assert.equal(serpCharState(SERP_TITLE_MAX, SERP_TITLE_MIN, SERP_TITLE_MAX), "good");
  assert.equal(serpCharState(SERP_TITLE_MAX + 1, SERP_TITLE_MIN, SERP_TITLE_MAX), "long");
});

test("serpCharState مرزهای بازهٔ توضیح را محترم می‌شمارد", () => {
  assert.equal(serpCharState(SERP_DESC_MIN - 1, SERP_DESC_MIN, SERP_DESC_MAX), "short");
  assert.equal(serpCharState(SERP_DESC_MIN, SERP_DESC_MIN, SERP_DESC_MAX), "good");
  assert.equal(serpCharState(SERP_DESC_MAX, SERP_DESC_MIN, SERP_DESC_MAX), "good");
  assert.equal(serpCharState(SERP_DESC_MAX + 1, SERP_DESC_MIN, SERP_DESC_MAX), "long");
});

test("serpCharState برای خالی/مقدار نامعتبر «کم» می‌دهد", () => {
  assert.equal(serpCharState(0, SERP_TITLE_MIN, SERP_TITLE_MAX), "short");
  assert.equal(serpCharState(Number.NaN, SERP_TITLE_MIN, SERP_TITLE_MAX), "short");
  assert.equal(serpCharState(-5, SERP_TITLE_MIN, SERP_TITLE_MAX), "short");
});

test("truncateForSerp متنِ کوتاه‌تر یا برابر سقف را دست‌نخورده می‌گذارد", () => {
  assert.equal(truncateForSerp("کوتاه", 60), "کوتاه");
  assert.equal(truncateForSerp("12345", 5), "12345");
});

test("truncateForSerp متنِ بلندتر را می‌برد و «…» می‌گذارد", () => {
  assert.equal(truncateForSerp("abcdefghij", 4), "abcd…");
});

test("truncateForSerp فاصلهٔ انتهاییِ برش را حذف می‌کند", () => {
  assert.equal(truncateForSerp("hello world", 6), "hello…");
});

test("truncateForSerp سقفِ نامعتبر/صفر را خالی می‌کند و ورودی بد را می‌پذیرد", () => {
  assert.equal(truncateForSerp("متن", 0), "");
  assert.equal(truncateForSerp("متن", -1), "");
  assert.equal(truncateForSerp("", 10), "");
});

test("truncateForSerp بر پایهٔ code point می‌برد، نه واحد UTF-16", () => {
  assert.equal(truncateForSerp("🙂🙂🙂", 2), "🙂🙂…");
});

test("serpUrlParts کنونیکالِ مطلق را به میزبان + مسیر تفکیک می‌کند", () => {
  assert.deepEqual(serpUrlParts("https://blog.example.com/posts/hello", "x"), {
    host: "blog.example.com",
    path: "/posts/hello",
  });
});

test("serpUrlParts برای ریشهٔ کنونیکال مسیر خالی می‌دهد", () => {
  assert.deepEqual(serpUrlParts("https://example.com/", "x"), {
    host: "example.com",
    path: "",
  });
});

test("serpUrlParts کنونیکالِ مسیر-نسبی را با میزبان پیش‌فرض نگه می‌دارد", () => {
  assert.deepEqual(serpUrlParts("/about-us", "whatever"), {
    host: "example.com",
    path: "/about-us",
  });
});

test("serpUrlParts بدون کنونیکال از slug می‌سازد و اسلش‌های اضافی را می‌برد", () => {
  assert.deepEqual(serpUrlParts("", "/contact/"), { host: "example.com", path: "/contact" });
  assert.deepEqual(serpUrlParts("", "  services  "), {
    host: "example.com",
    path: "/services",
  });
  assert.deepEqual(serpUrlParts("", ""), { host: "example.com", path: "" });
});

test("serpUrlParts میزبان پیش‌فرض قابل‌تنظیم است", () => {
  assert.deepEqual(serpUrlParts("", "shop", "mysite.ir"), {
    host: "mysite.ir",
    path: "/shop",
  });
});
