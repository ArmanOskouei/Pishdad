import { test } from "node:test";
import assert from "node:assert/strict";

import { summarizeLocks } from "./panel-locks.ts";

/** K7.16 — قفل‌ها از داده، نه از حدس. */

test("an active subscription with healthy plugins has no lock", () => {
  const out = summarizeLocks(
    { status: "active" },
    [{ active: true, review_status: "approved", signature_valid: true }],
  );
  assert.equal(out.hasLock, false);
  assert.equal(out.suspended, false);
});

test("suspended / past_due surface from the subscription payload", () => {
  assert.equal(summarizeLocks({ status: "suspended" }, []).suspended, true);
  assert.equal(summarizeLocks({ status: "suspended" }, []).hasLock, true);
  assert.equal(summarizeLocks({ status: "past_due" }, []).pastDue, true);
  assert.equal(summarizeLocks({ status: "past_due" }, []).hasLock, true);
});

test("pending reviews and bad signatures count up", () => {
  const out = summarizeLocks({ status: "active" }, [
    { active: false, review_status: "pending", signature_valid: true },
    { active: true, review_status: "approved", signature_valid: false },
    { active: false, review_status: "rejected", signature_valid: false },
  ]);
  assert.equal(out.pendingReview, 1);
  assert.equal(out.unsignedActive, 1);
  assert.equal(out.hasLock, true);
});

test("dev_mode is unknown — there is no endpoint, so we must not claim off", () => {
  assert.equal(summarizeLocks({ status: "active" }, []).devMode, "unknown");
});

test("garbage in yields no lock, never a crash", () => {
  assert.equal(summarizeLocks(null, null).hasLock, false);
  assert.equal(summarizeLocks({}, "junk" as unknown as []).hasLock, false);
  assert.equal(summarizeLocks({ status: 42 }, [null, "x"]).hasLock, false);
});
