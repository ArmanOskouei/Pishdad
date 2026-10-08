import { test } from "node:test";
import assert from "node:assert/strict";

import { nextOtpFocusIndex, OTP_LENGTH } from "./otp-focus.ts";

/**
 * رگرسیونِ باگِ «کادر بعدی خودکار نمی‌رفت».
 *
 * گزارش کاربر: «هر رقم را که تایپ می‌کنی باید کادر بعدی را دستی انتخاب کنم
 * یا TAB بزنم». علتش `start + digits.length - 1` بود که روی همان کادرِ
 * نوشته‌شده می‌ماند.
 */

test("one digit in the first box advances to box two", () => {
  assert.equal(nextOtpFocusIndex(0, 1), 1);

  // نسخهٔ شکسته `start + digits.length - 1` اینجا ۰ می‌داد، یعنی روی همان
  // کادرِ اول می‌ماند و کاربر باید دستی کلیک/TAB کند.
  const broken = (s: number, d: number) => s + d - 1;
  assert.equal(broken(0, 1), 0);
  assert.notEqual(broken(0, 1), nextOtpFocusIndex(0, 1));
});

test("typing through the boxes walks 0 → 1 → 2 → 3 → 4 → 5", () => {
  const seen: number[] = [];
  for (let box = 0; box < OTP_LENGTH; box += 1) {
    const next = nextOtpFocusIndex(box, 1);
    seen.push(next);
  }
  assert.deepEqual(seen, [1, 2, 3, 4, 5, 5]);
});

test("the last box stays put instead of escaping the array", () => {
  assert.equal(nextOtpFocusIndex(OTP_LENGTH - 1, 1), OTP_LENGTH - 1);
  assert.ok(nextOtpFocusIndex(OTP_LENGTH - 1, 1) <= OTP_LENGTH - 1);
});

test("a pasted full code parks focus on the final box", () => {
  assert.equal(nextOtpFocusIndex(0, OTP_LENGTH), OTP_LENGTH - 1);
});

test("a pasted partial code advances past the pasted digits", () => {
  // «۱۲۳» در کادر صفر ⇒ فوکوس روی کادر ۳ (اولین خالیِ بعد از ۱۲۳).
  assert.equal(nextOtpFocusIndex(0, 3), 3);
  // همان «۱۲۳» در کادر دوم ⇒ کادر ۵.
  assert.equal(nextOtpFocusIndex(1, 3), 4);
});

test("an empty write still moves one box forward", () => {
  assert.equal(nextOtpFocusIndex(2, 0), 3);
});
