import { test } from "node:test";
import assert from "node:assert/strict";

import {
  ONBOARDING_KEY_PREFIX,
  onboardingSeenKey,
  readOnboardingSeen,
  shouldShowOnboarding,
  writeOnboardingSeen,
  type StorageLike,
} from "./onboarding.ts";

/**
 * رگرسیونِ باگِ «آنبوردینگ فقط برای کاربرِ اولِ مرورگر می‌آمد».
 *
 * کلیدِ قبلی سراسری بود (`pishdad.onboarding.v1.seen`) و به کاربر گره
 * نخورده بود؛ پس مدیرِ دوم روی همان مرورگر هرگز راهنما را نمی‌دید.
 */

/** Storageِ درون‌حافظه‌ای جایگزین localStorage در تست. */
function memoryStorage(initial: Record<string, string> = {}): StorageLike {
  const map = new Map(Object.entries(initial));
  return {
    getItem: (key) => map.get(key) ?? null,
    setItem: (key, value) => {
      map.set(key, value);
    },
  };
}

test("the seen key is namespaced per user", () => {
  assert.notEqual(onboardingSeenKey(1), onboardingSeenKey(2));
  assert.ok(onboardingSeenKey(7).startsWith(`${ONBOARDING_KEY_PREFIX}:`));
  assert.equal(onboardingSeenKey(7), `${ONBOARDING_KEY_PREFIX}:7`);
});

test("nothing is shown while the session is still loading", () => {
  assert.equal(shouldShowOnboarding({ loading: true, userId: 1, seen: false }), false);
  // حتی اگر کاربرِ محتملی هم هست، تا تمام‌شدن بررسی رندر نمی‌کنیم.
  assert.equal(shouldShowOnboarding({ loading: true, userId: null, seen: false }), false);
});

test("nothing is shown without a resolved user", () => {
  assert.equal(shouldShowOnboarding({ loading: false, userId: null, seen: false }), false);
  assert.equal(shouldShowOnboarding({ loading: false, userId: undefined, seen: false }), false);
  assert.equal(shouldShowOnboarding({ loading: false, userId: "", seen: false }), false);
});

test("a logged-in user who has not seen it gets the overlay", () => {
  assert.equal(shouldShowOnboarding({ loading: false, userId: 42, seen: false }), true);
});

test("a logged-in user who already dismissed it does not get it again", () => {
  assert.equal(shouldShowOnboarding({ loading: false, userId: 42, seen: true }), false);
});

test("seen flag round-trips through storage", () => {
  const storage = memoryStorage();
  assert.equal(readOnboardingSeen(storage, 5), false);
  assert.equal(writeOnboardingSeen(storage, 5), true);
  assert.equal(readOnboardingSeen(storage, 5), true);
});

test("dismissing for one user does not hide it from another (the reported bug)", () => {
  const storage = memoryStorage();

  // مدیرِ اول راهنما را رد می‌کند.
  writeOnboardingSeen(storage, 1);

  assert.equal(readOnboardingSeen(storage, 1), true);
  // مدیرِ دوم روی همان مرورگر باید دوباره ببیند — نسخهٔ شکسته اینجا true می‌داد.
  assert.equal(readOnboardingSeen(storage, 2), false);
  assert.equal(shouldShowOnboarding({ loading: false, userId: 2, seen: readOnboardingSeen(storage, 2) }), true);
});

test("a hostile storage that throws never crashes the decision", () => {
  const throwing: StorageLike = {
    getItem: () => {
      throw new Error("denied");
    },
    setItem: () => {
      throw new Error("denied");
    },
  };

  assert.equal(readOnboardingSeen(throwing, 1), false);
  assert.equal(writeOnboardingSeen(throwing, 1), false);
  // خطای Storage نباید از نمایشِ اولیه جلوگیری کند.
  assert.equal(shouldShowOnboarding({ loading: false, userId: 1, seen: readOnboardingSeen(throwing, 1) }), true);
});

test("missing storage is treated as unseen and writing is a no-op", () => {
  assert.equal(readOnboardingSeen(null, 1), false);
  assert.equal(writeOnboardingSeen(undefined, 1), false);
});

test("a known storage value of '1' is the only thing that counts as seen", () => {
  assert.equal(readOnboardingSeen(memoryStorage({ [onboardingSeenKey(3)]: "0" }), 3), false);
  assert.equal(readOnboardingSeen(memoryStorage({ [onboardingSeenKey(3)]: "1" }), 3), true);
});
