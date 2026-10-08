import { test } from "node:test";
import assert from "node:assert/strict";

import {
  COOKIE_CONSENT_KEY,
  acceptCookieConsent,
  hasCookieConsent,
  privacyPathFor,
} from "./cookie-consent.ts";

/**
 * WF-M20 — نگهبانِ منطقِ بنرِ رضایت کوکی (بدون React).
 *
 * هدف: ثابت شود رضایت واقعاً ذخیره/خوانده می‌شود، خطای دسترسی بنر را نمی‌شکند،
 * و لینکِ سیاست حریم خصوصی زبان را درست نگه می‌دارد.
 */

function fakeStorage(): Map<string, string> & {
  getItem: (k: string) => string | null;
  setItem: (k: string, v: string) => void;
} {
  const m = new Map<string, string>();
  return Object.assign(m, {
    getItem: (k: string) => m.get(k) ?? null,
    setItem: (k: string, v: string) => void m.set(k, v),
  });
}

test("پیش از پذیرش، رضایت ثبت نشده است", () => {
  const s = fakeStorage();
  assert.equal(hasCookieConsent(s), false);
});

test("پذیرش، رضایت را ذخیره می‌کند و بارِ بعد خوانده می‌شود", () => {
  const s = fakeStorage();
  acceptCookieConsent(s);
  assert.equal(s.get(COOKIE_CONSENT_KEY), "accepted");
  assert.equal(hasCookieConsent(s), true);
});

test("خطای دسترسی به storage باعث پرت‌شدن نمی‌شود", () => {
  const throwing = {
    getItem() {
      throw new Error("private mode");
    },
    setItem() {
      throw new Error("private mode");
    },
  };
  assert.equal(hasCookieConsent(throwing), false);
  assert.doesNotThrow(() => acceptCookieConsent(throwing));
});

test("نبودِ storage به‌معنای «رضایت نداده» است", () => {
  assert.equal(hasCookieConsent(null), false);
  assert.equal(hasCookieConsent(undefined), false);
  assert.doesNotThrow(() => acceptCookieConsent(null));
});

test("لینک حریم خصوصی پیشوندِ زبان را حفظ می‌کند", () => {
  assert.equal(privacyPathFor("/"), "/privacy");
  assert.equal(privacyPathFor("/about"), "/privacy");
  assert.equal(privacyPathFor("/fa/about"), "/fa/privacy");
  assert.equal(privacyPathFor("/en/"), "/en/privacy");
  assert.equal(privacyPathFor(""), "/privacy");
});
