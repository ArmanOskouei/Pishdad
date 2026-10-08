import { test } from "node:test";
import assert from "node:assert/strict";

import {
  ACCESSIBILITY_SLUG,
  accessibilityContact,
  accessibilityParagraphs,
  accessibilityPathFor,
  DEFAULT_ACCESSIBILITY_STATEMENT_EN,
  DEFAULT_ACCESSIBILITY_STATEMENT_FA,
  toStatementParagraphs,
} from "./accessibility-statement.ts";

/**
 * WF-M17 — نگهبانِ منطقِ خالصِ صفحهٔ بیانیهٔ دسترس‌پذیری (بدون React).
 *
 * هدف: ثابت شود تنظیمِ خالی به پیش‌فرضِ همان زبان می‌افتد (نه پیامِ خالی)، متن
 * به پاراگراف شکسته می‌شود، لینک پیشوندِ زبان را حفظ می‌کند، و راه تماسِ
 * نامعتبر/خطرناک هرگز وارد `href` نمی‌شود.
 */

test("تنظیمِ ذخیره‌شده پاراگراف‌به‌پاراگراف رندر می‌شود", () => {
  assert.deepEqual(toStatementParagraphs("بند اول.\n\nبند دوم."), ["بند اول.", "بند دوم."]);
  assert.deepEqual(toStatementParagraphs("  \n\n  بند تنها  "), ["بند تنها"]);
  assert.deepEqual(toStatementParagraphs("   "), []);
  // بدون خط خالی، هر خط یک پاراگراف است.
  assert.deepEqual(toStatementParagraphs("یک\nدو"), ["یک", "دو"]);
});

test("تنظیمِ خالی ⇒ پیش‌فرضِ همان زبان", () => {
  assert.deepEqual(
    accessibilityParagraphs(null, "fa"),
    toStatementParagraphs(DEFAULT_ACCESSIBILITY_STATEMENT_FA),
  );
  assert.deepEqual(
    accessibilityParagraphs("   ", "en"),
    toStatementParagraphs(DEFAULT_ACCESSIBILITY_STATEMENT_EN),
  );
});

test("پیش‌فرض هدفِ انطباق و راه تماس را نام می‌برد", () => {
  for (const text of [DEFAULT_ACCESSIBILITY_STATEMENT_FA, DEFAULT_ACCESSIBILITY_STATEMENT_EN]) {
    assert.match(text, /WCAG 2\.2/);
    assert.match(text, /\bAA\b/);
  }
  assert.match(DEFAULT_ACCESSIBILITY_STATEMENT_FA, /تماس/);
  assert.match(DEFAULT_ACCESSIBILITY_STATEMENT_EN, /contact/i);
});

test("لینک بیانیه پیشوندِ زبان را حفظ می‌کند", () => {
  assert.equal(accessibilityPathFor("/"), "/accessibility");
  assert.equal(accessibilityPathFor("/about"), "/accessibility");
  assert.equal(accessibilityPathFor("/fa/about"), "/fa/accessibility");
  assert.equal(accessibilityPathFor("/en/"), "/en/accessibility");
  assert.equal(accessibilityPathFor(""), "/accessibility");
  assert.equal(ACCESSIBILITY_SLUG, "accessibility");
});

test("راه تماسِ معتبر به لینکِ امن تبدیل می‌شود", () => {
  const c = accessibilityContact({ phone: "0912 345 6789", email: "info@example.com" });
  assert.equal(c.tel, "tel:09123456789");
  assert.equal(c.mailto, "mailto:info@example.com");
});

test("راه تماسِ نامعتبر یا خطرناک اصلاً لینک نمی‌شود", () => {
  assert.deepEqual(accessibilityContact(null), {});
  assert.deepEqual(accessibilityContact({}), {});
  // مقادیر ناامن (اسکریپت/گیومه/حرف) باید کاملاً دور ریخته شوند.
  assert.deepEqual(accessibilityContact({ email: "javascript:alert(1)", phone: "<script>" }), {});
  assert.deepEqual(accessibilityContact({ email: 'a"b@example.com', phone: "09123456789 javascript" }), {});
  assert.equal(accessibilityContact({ email: "info@example" }).mailto, undefined);
  assert.equal(accessibilityContact({ email: "infoexample.com" }).mailto, undefined);
  assert.equal(accessibilityContact({ phone: "09123" }).tel, undefined);
});