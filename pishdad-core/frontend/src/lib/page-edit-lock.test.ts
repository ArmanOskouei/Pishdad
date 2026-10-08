import test from "node:test";
import assert from "node:assert/strict";

import {
  autosaveGate,
  createDebouncer,
  formatSavedClock,
  type TimerApi,
} from "./page-edit-lock.ts";

/** تایمر جعلی: زمان‌بندی‌ها را نگه می‌دارد تا آزمون دستی شلیک/باطل کند. */
function fakeTimers() {
  let next = 1;
  const pending = new Map<number, () => void>();
  const api: TimerApi = {
    set(fn) {
      const id = next++;
      pending.set(id, fn);
      return id;
    },
    clear(handle) {
      pending.delete(handle as number);
    },
  };
  return {
    api,
    pendingCount: () => pending.size,
    fireAll: () => {
      const fns = [...pending.values()];
      pending.clear();
      for (const fn of fns) fn();
    },
  };
}

test("debouncer resets the previous schedule on each call", () => {
  const timers = fakeTimers();
  let calls = 0;
  const d = createDebouncer(() => { calls += 1; }, 1000, timers.api);

  d.schedule();
  assert.equal(timers.pendingCount(), 1);
  d.schedule();
  assert.equal(timers.pendingCount(), 1, "زمان‌بندیِ قبلی باید باطل شود");
  assert.equal(calls, 0, "پیش از انقضا نباید اجرا شود");

  timers.fireAll();
  assert.equal(calls, 1);
  assert.equal(d.pending(), false);
});

test("debouncer cancel prevents the pending call", () => {
  const timers = fakeTimers();
  let calls = 0;
  const d = createDebouncer(() => { calls += 1; }, 1000, timers.api);

  d.schedule();
  d.cancel();
  assert.equal(d.pending(), false);
  assert.equal(timers.pendingCount(), 0);

  timers.fireAll();
  assert.equal(calls, 0);
});

test("debouncer can be re-armed after firing", () => {
  const timers = fakeTimers();
  let calls = 0;
  const d = createDebouncer(() => { calls += 1; }, 1000, timers.api);

  d.schedule();
  timers.fireAll();
  d.schedule();
  timers.fireAll();
  assert.equal(calls, 2);
});

test("formatSavedClock renders local HH:MM in Persian digits", () => {
  assert.equal(formatSavedClock(new Date(2026, 9, 5, 14, 5)), "۱۴:۰۵");
  assert.equal(formatSavedClock(new Date(2026, 9, 5, 9, 0)), "۰۹:۰۰");
  assert.equal(formatSavedClock(new Date(2026, 9, 5, 23, 59)), "۲۳:۵۹");
});

test("autosave only runs when dirty and unobstructed", () => {
  const base = { dirty: true, saving: false, publishing: false, hasConflict: false, hasLockHolder: false };
  assert.equal(autosaveGate(base), true);

  assert.equal(autosaveGate({ ...base, dirty: false }), false);
  assert.equal(autosaveGate({ ...base, saving: true }), false);
  assert.equal(autosaveGate({ ...base, publishing: true }), false);
  assert.equal(autosaveGate({ ...base, hasConflict: true }), false);
  assert.equal(autosaveGate({ ...base, hasLockHolder: true }), false);
});
