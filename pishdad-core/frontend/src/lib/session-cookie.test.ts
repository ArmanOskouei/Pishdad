import test from "node:test";
import assert from "node:assert/strict";

import {
  MAX_SESSION_TOKEN_LENGTH,
  MIN_SESSION_TOKEN_LENGTH,
  inspectSessionToken,
} from "./session-cookie.ts";

/**
 * K6.6 — پروکسی دیگر فقط «وجود» کوکی را نمی‌سنجد.
 *
 * ⛔ این تست‌ها **اعتبار** توکن را ثابت نمی‌کنند و نباید چنین برداشتی شود:
 * اصالت توکن را بک‌اند با `Authorization: Bearer` راستی‌آزمایی می‌کند. آنچه
 * اینجا ثابت می‌شود فقط این است که مقدار غایب/خالی/placeholder/بدشکل دیگر
 * از دروازهٔ پوستهٔ پنل رد می‌شود — چیزی که قبلاً با یک `if (!token)` که
 * فقط غیبت و رشتهٔ خالی را می‌گرفت، رد نمی‌شد.
 */

test("توکن معتبر پذیرفته می‌شود", () => {
  const real = "12|abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJ";
  const decision = inspectSessionToken(real);
  assert.equal(decision.ok, true);
  assert.equal(decision.ok && decision.token, real);
});

test("JWT پذیرفته می‌شود", () => {
  const jwt = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjF9.4y3q2Z-signature";
  assert.equal(inspectSessionToken(jwt).ok, true);
});

test("غیبت و غیررشته رد می‌شود", () => {
  for (const bad of [undefined, null, 42, true, {}, ["x"]]) {
    assert.equal(inspectSessionToken(bad).ok, false, `باید رد شود: ${String(bad)}`);
  }
});

test("رشتهٔ خالی و فقط-فاصله رد می‌شود", () => {
  for (const bad of ["", " ", "   ", "\t", "\n"]) {
    assert.equal(inspectSessionToken(bad).ok, false, `باید رد شود: ${JSON.stringify(bad)}`);
  }
});

test("placeholderهای ناشی از شکست JSON رد می‌شود", () => {
  // اینها همان مقادیری هستند که از `json.token` غایب یا نامعتبر تولید
  // می‌شوند (app/api/auth/login/route.ts:29 فقط `if (token)` را چک می‌کند).
  for (const bad of ["null", "undefined", "none", "nil", "NaN", "false", "true",
                     "NULL", "Undefined", "True"]) {
    assert.equal(inspectSessionToken(bad).ok, false, `باید رد شود: ${bad}`);
  }
});

test("مقدار بی‌معنای کوتاه رد می‌شود", () => {
  for (const bad of ["x", "abc", "a", "1234567"]) {
    assert.equal(inspectSessionToken(bad).ok, false, `باید رد شود: ${bad}`);
  }
  assert.equal(MIN_SESSION_TOKEN_LENGTH, 8);
  assert.equal(inspectSessionToken("a".repeat(MIN_SESSION_TOKEN_LENGTH)).ok, true);
});

test("کاراکتر کنترلی و فاصلهٔ داخلی رد می‌شود (تزریق هدر Authorization)", () => {
  for (const bad of ["abcdefgh ij", "abcdefgh\nij", "abcdefgh\r\nX: 1", "abcdefgh\0ij"]) {
    assert.equal(inspectSessionToken(bad).ok, false, `باید رد شود: ${JSON.stringify(bad)}`);
  }
});

test("فاصلهٔ ابتدا/انتها رد می‌شود تا Bearer دو فاصله ندهد", () => {
  for (const bad of [" abcdefghij", "abcdefghij ", " abcdefghij "]) {
    assert.equal(inspectSessionToken(bad).ok, false, `باید رد شود: ${JSON.stringify(bad)}`);
  }
});

test("مقدار بسیار بلند (دادهٔ دلخواه، نه توکن) رد می‌شود", () => {
  const huge = "a".repeat(MAX_SESSION_TOKEN_LENGTH + 1);
  assert.equal(inspectSessionToken(huge).ok, false);
  assert.equal(inspectSessionToken("a".repeat(MAX_SESSION_TOKEN_LENGTH)).ok, true);
});

test("پیام رد برای پوستهٔ پنل قابل نمایش است", () => {
  const decision = inspectSessionToken(undefined);
  assert.equal(decision.ok, false);
  assert.ok(!decision.ok && decision.reason.length > 0, "باید دلیل داشته باشد");
});
