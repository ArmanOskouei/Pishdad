import { test } from "node:test";
import assert from "node:assert/strict";

import { deriveSubscriptionView } from "./dashboard-subscription.ts";

/**
 * رگرسیونِ کرشِ «Cannot read properties of null (reading 'status')».
 *
 * ورود به پنل با superadmin@local این کرش را می‌داد، چون سوپرادمین اشتراک
 * ندارد و بک‌اند `subscription: null` می‌فرستد.
 */

const sub = (over: Partial<Parameters<typeof deriveSubscriptionView>[0] & object> = {}) => ({
  status: "active",
  tier: "pro",
  due_at: "2026-10-20",
  days_remaining: 15,
  ...over,
});

test("null subscription is a normal state, not a crash", () => {
  const view = deriveSubscriptionView(null);
  assert.equal(view.present, false);
  assert.equal(view.suspended, false);
  assert.equal(view.periodPct, 0);
});

test("undefined subscription is treated like null", () => {
  assert.equal(deriveSubscriptionView(undefined).present, false);
});

test("a present subscription still renders", () => {
  const view = deriveSubscriptionView(sub());
  assert.equal(view.present, true);
  assert.equal(view.suspended, false);
});

test("suspended surfaces from the payload", () => {
  assert.equal(deriveSubscriptionView(sub({ status: "suspended" })).suspended, true);
});

test("period percent runs from the real days remaining", () => {
  assert.equal(deriveSubscriptionView(sub({ days_remaining: 30 })).periodPct, 0);
  assert.equal(deriveSubscriptionView(sub({ days_remaining: 15 })).periodPct, 50);
  assert.equal(deriveSubscriptionView(sub({ days_remaining: 0 })).periodPct, 100);
});

test("period percent stays clamped outside the 0..100 range", () => {
  // سررسید گذشته یا دادهٔ کهنه نباید اسلایدر را خراب کند.
  assert.equal(deriveSubscriptionView(sub({ days_remaining: 45 })).periodPct, 0);
  assert.equal(deriveSubscriptionView(sub({ days_remaining: -5 })).periodPct, 100);
});
