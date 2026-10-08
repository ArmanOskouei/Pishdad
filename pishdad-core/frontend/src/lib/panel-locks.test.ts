import { test } from "node:test";
import assert from "node:assert/strict";

import { summarizeLocks } from "./panel-locks.ts";

/** K7.16 — قفل‌ها از داده، نه از حدس. */

test("healthy plugins mean no lock", () => {
  const out = summarizeLocks([{ active: true, review_status: "approved", signature_valid: true }]);
  assert.equal(out.hasLock, false);
  assert.equal(out.pendingReview, 0);
  assert.equal(out.unsignedActive, 0);
});

test("pending reviews and bad signatures count up", () => {
  const out = summarizeLocks([
    { active: false, review_status: "pending", signature_valid: true },
    { active: true, review_status: "approved", signature_valid: false },
    { active: false, review_status: "rejected", signature_valid: false },
  ]);
  assert.equal(out.pendingReview, 1);
  assert.equal(out.unsignedActive, 1);
  assert.equal(out.hasLock, true);
});

test("dev_mode is unknown — there is no endpoint, so we must not claim off", () => {
  assert.equal(summarizeLocks([]).devMode, "unknown");
});

test("garbage in yields no lock, never a crash", () => {
  assert.equal(summarizeLocks(null).hasLock, false);
  assert.equal(summarizeLocks(undefined).hasLock, false);
  assert.equal(summarizeLocks("junk").hasLock, false);
  assert.equal(summarizeLocks([null, "x"]).hasLock, false);
  assert.equal(summarizeLocks({ status: "active" }).hasLock, false);
});