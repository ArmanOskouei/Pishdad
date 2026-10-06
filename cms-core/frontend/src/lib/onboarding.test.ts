import { test } from "node:test";
import assert from "node:assert/strict";

import {
  ONBOARDING_KEY_PREFIX,
  ONBOARDING_CHECKLIST_KEY_PREFIX,
  CHECKLIST_STEPS,
  checklistDismissKey,
  checklistProgress,
  isChecklistComplete,
  onboardingSeenKey,
  readChecklistDismissed,
  readOnboardingSeen,
  shouldShowChecklist,
  shouldShowOnboarding,
  writeChecklistDismissed,
  writeOnboardingSeen,
  type OnboardingChecklist,
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

/* ─────────────── WF-H21 — چک‌لیست راه‌اندازی اولین ورود ─────────────── */

/** همهٔ گام‌ها انجام‌شده؛ تست‌ها روی نمونهٔ ناتمام تغییرش می‌دهند. */
const DONE: OnboardingChecklist = { logo: true, page: true, seo: true, contact: true, published: true };
const NONE: OnboardingChecklist = { logo: false, page: false, seo: false, contact: false, published: false };

test("چک‌لیست دقیقاً پنج گام دارد و کلیدها یکتا و هم‌شکلِ قرارداد سرورند", () => {
  assert.equal(CHECKLIST_STEPS.length, 5);
  const keys = CHECKLIST_STEPS.map((s) => s.key);
  assert.deepEqual([...keys].sort(), Object.keys(DONE).sort());
  assert.equal(new Set(keys).size, keys.length, "کلید تکرارشده یعنی یک گام دوبار شمرده می‌شود");
  for (const step of CHECKLIST_STEPS) {
    assert.ok(step.label.length > 0, "هر گام باید برچسب فارسی داشته باشد");
    assert.ok(step.href.startsWith("/admin/"), "هر گام باید به صفحهٔ پنل لینک بدهد");
  }
});

test("پیشرفت از روی وضعیت واقعی محاسبه می‌شود", () => {
  assert.deepEqual(checklistProgress(NONE), { completed: 0, total: 5, percent: 0 });
  assert.deepEqual(checklistProgress(DONE), { completed: 5, total: 5, percent: 100 });
  assert.deepEqual(
    checklistProgress({ ...NONE, logo: true, page: true, published: true }),
    { completed: 3, total: 5, percent: 60 },
  );
});

test("کامل‌بودن یعنی هر پنج گام انجام شده باشد", () => {
  assert.equal(isChecklistComplete(NONE), false);
  assert.equal(isChecklistComplete({ ...DONE, published: false }), false);
  assert.equal(isChecklistComplete(DONE), true);
});

test("علامتِ پنهان‌کردنِ چک‌لیست per-user است", () => {
  assert.notEqual(checklistDismissKey(1), checklistDismissKey(2));
  assert.ok(checklistDismissKey(9).startsWith(`${ONBOARDING_CHECKLIST_KEY_PREFIX}:`));

  const storage = memoryStorage();
  assert.equal(readChecklistDismissed(storage, 1), false);
  assert.equal(writeChecklistDismissed(storage, 1), true);
  assert.equal(readChecklistDismissed(storage, 1), true);
  // مدیرِ دوم روی همان مرورگر نباید پنهان‌شده ببیند.
  assert.equal(readChecklistDismissed(storage, 2), false);
});

test("چک‌لیست تا پایان بررسی نشست نشان داده نمی‌شود", () => {
  assert.equal(shouldShowChecklist({ loading: true, userId: 1, complete: false, dismissed: false }), false);
});

test("چک‌لیست برای مهمان/کاربر نامشخص نشان داده نمی‌شود", () => {
  assert.equal(shouldShowChecklist({ loading: false, userId: null, complete: false, dismissed: false }), false);
  assert.equal(shouldShowChecklist({ loading: false, userId: "", complete: false, dismissed: false }), false);
});

test("چک‌لیست با تکمیل همهٔ گام‌ها خودکار پنهان می‌شود", () => {
  assert.equal(shouldShowChecklist({ loading: false, userId: 7, complete: true, dismissed: false }), false);
});

test("پنهان‌کردنِ دستی چک‌لیست را نگه می‌دارد", () => {
  assert.equal(shouldShowChecklist({ loading: false, userId: 7, complete: false, dismissed: true }), false);
  assert.equal(shouldShowChecklist({ loading: false, userId: 7, complete: false, dismissed: false }), true);
});

test("Storageِ خراب، چک‌لیست را از بین نمی‌برد", () => {
  const throwing: StorageLike = {
    getItem: () => {
      throw new Error("denied");
    },
    setItem: () => {
      throw new Error("denied");
    },
  };
  assert.equal(readChecklistDismissed(throwing, 1), false);
  assert.equal(writeChecklistDismissed(throwing, 1), false);
  assert.equal(shouldShowChecklist({ loading: false, userId: 1, complete: false, dismissed: readChecklistDismissed(throwing, 1) }), true);
});
