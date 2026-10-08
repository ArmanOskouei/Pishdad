/**
 * E8 — منطق «آنبوردینگ فقط یک‌بار» به‌صورت خالص و قابل تست.
 *
 * ریشهٔ باگ: کلیدِ قبلی `pishdad.onboarding.v1.seen` **سراسری** بود، نه
 * per-user. روی یک مرورگر مشترک، مدیرِ دوم آن راهنما را هرگز نمی‌دید چون
 * مدیرِ اول علامتش را زده بود. اینجا کلید به شناسهٔ کاربر گره می‌خورد.
 *
 * ماژول عمداً بدون React است: هم قابل تست با `node --test` می‌شود، هم
 * مصرف‌کننده (Onboarding.tsx) فقط تصمیمِ خالص را در `useEffect` اجرا می‌کند
 * (قاعدهٔ ضد React #301 — هیچ setState‌ای در render نیست).
 */

export const ONBOARDING_KEY_PREFIX = "pishdad.onboarding.v1.seen";

/** حداقلی از Storage که برای تست با یک دروغِ درون‌حافظه‌ای کافی است. */
export type StorageLike = Pick<Storage, "getItem" | "setItem">;

export type OnboardingGateInput = {
  /** آیا بررسیِ نشست هنوز تمام نشده؟ تا آن‌وقت هیچ‌چیز نباید باز شود. */
  loading: boolean;
  userId: number | string | null | undefined;
  /** آیا همین کاربر قبلاً راهنما را دیده/رد کرده؟ */
  seen: boolean;
};

/** کلید مخصوص هر کاربر — دو مدیر روی یک مرورگر، دو علامتِ جدا دارند. */
export function onboardingSeenKey(userId: number | string): string {
  return `${ONBOARDING_KEY_PREFIX}:${String(userId)}`;
}

function hasUser(userId: number | string | null | undefined): boolean {
  return userId !== null && userId !== undefined && userId !== "";
}

/**
 * تصمیم خالص «نمایش بده یا نه».
 *
 * - تا تمام‌شدنِ بررسیِ نشست (`loading`) چیزی نشان نمی‌دهیم، وگرنه اورلی
 *   روی حالتِ گذرای «کاربرِ ناشناخته» پرش می‌زند.
 * - بدون شناسهٔ کاربرِ معتبر هرگز نشان نمی‌دهیم: نه به مهمان، نه وقتی هنوز
 *   نتوانستیم کاربر را تشخیص دهیم (per-user بودنِ علامت معنایی ندارد).
 */
export function shouldShowOnboarding({ loading, userId, seen }: OnboardingGateInput): boolean {
  if (loading) return false;
  if (!hasUser(userId)) return false;
  return !seen;
}

/* ─────────────────── WF-H21 — چک‌لیست راه‌اندازی اولین ورود ─────────────────── */

/**
 * کلیدِ «پنهان‌کردنِ» چک‌لیست هم per-user است (هم‌قاعدهٔ خودِ اورلیِ E8):
 * روی مرورگر مشترک، پنهان‌کردنِ مدیرِ اول نباید راهنمای مدیرِ دوم را پنهان کند.
 */
export const ONBOARDING_CHECKLIST_KEY_PREFIX = "pishdad.onboarding.v1.checklist";

/**
 * وضعیتِ پنج گام. این ساختار **آینهٔ** پاسخِ سرور است
 * (`DashboardController::checklist`) — سرور آن را از تنظیمات/صفحاتِ واقعی
 * مشتق می‌کند، پس کلاینت هیچ حدسی نمی‌زند.
 */
export type OnboardingChecklist = {
  logo: boolean;
  page: boolean;
  seo: boolean;
  contact: boolean;
  published: boolean;
};

export type ChecklistStep = {
  key: keyof OnboardingChecklist;
  label: string;
  hint: string;
  href: string;
};

/** ترتیبِ گام‌ها = ترتیبِ منطقیِ راه‌اندازی؛ تنها منبع حقیقتِ UI. */
export const CHECKLIST_STEPS: readonly ChecklistStep[] = Object.freeze([
  {
    key: "logo",
    label: "لوگوی سایت را بگذارید",
    hint: "در تنظیمات سایت یک تصویر به‌عنوان لوگو انتخاب کنید.",
    href: "/admin/settings",
  },
  {
    key: "page",
    label: "اولین صفحه را بسازید",
    hint: "یک صفحه بسازید و محتوایش را بنویسید.",
    href: "/admin/pages",
  },
  {
    key: "seo",
    label: "سئو را تنظیم کنید",
    hint: "توضیح سایت یا عنوان/توضیح متای یک صفحه را پر کنید.",
    href: "/admin/settings",
  },
  {
    key: "contact",
    label: "فرم تماس را اضافه کنید",
    hint: "بلوک «فرم تماس» را به یکی از صفحه‌ها بیفزایید.",
    href: "/admin/pages",
  },
  {
    key: "published",
    label: "اولین صفحه را منتشر کنید",
    hint: "صفحه را منتشر کنید تا سایت زنده شود.",
    href: "/admin/pages",
  },
]);

export type ChecklistProgress = { completed: number; total: number; percent: number };

/** پیشرفتِ خالص — بدون احتیاج به DOM؛ قابل تست با `node --test`. */
export function checklistProgress(done: OnboardingChecklist): ChecklistProgress {
  const total = CHECKLIST_STEPS.length;
  let completed = 0;
  for (const step of CHECKLIST_STEPS) {
    if (done[step.key]) completed += 1;
  }
  return { completed, total, percent: total === 0 ? 0 : Math.round((completed / total) * 100) };
}

/** همهٔ گام‌ها انجام شده؟ ⇒ چک‌لیست خودکار پنهان می‌شود. */
export function isChecklistComplete(done: OnboardingChecklist): boolean {
  return checklistProgress(done).completed === CHECKLIST_STEPS.length;
}

export function checklistDismissKey(userId: number | string): string {
  return `${ONBOARDING_CHECKLIST_KEY_PREFIX}:${String(userId)}`;
}

/** خواندنِ امنِ علامتِ پنهان‌کردنِ همین کاربر. */
export function readChecklistDismissed(
  storage: StorageLike | null | undefined,
  userId: number | string | null | undefined,
): boolean {
  if (!storage || !hasUser(userId)) return false;
  try {
    return storage.getItem(checklistDismissKey(userId as number | string)) === "1";
  } catch {
    return false;
  }
}

/** نوشتنِ امنِ علامتِ پنهان‌کردن. خطای Storage بی‌صدا نادیده می‌رود. */
export function writeChecklistDismissed(
  storage: StorageLike | null | undefined,
  userId: number | string | null | undefined,
): boolean {
  if (!storage || !hasUser(userId)) return false;
  try {
    storage.setItem(checklistDismissKey(userId as number | string), "1");
    return true;
  } catch {
    return false;
  }
}

export type ChecklistGateInput = {
  /** تا تمام‌شدنِ بررسیِ نشست چیزی نشان نمی‌دهیم. */
  loading: boolean;
  userId: number | string | null | undefined;
  /** آیا همهٔ گام‌ها کامل شده؟ */
  complete: boolean;
  /** آیا همین کاربر چک‌لیست را دستی پنهان کرده؟ */
  dismissed: boolean;
};

/**
 * تصمیم خالصِ «چک‌لیست را نشان بده یا نه».
 *
 * - تکمیلِ همهٔ گام‌ها ⇒ خودکار پنهان (بدون نیاز به اقدامِ کاربر).
 * - پنهان‌کردنِ دستی per-user است و باید یادش بماند.
 */
export function shouldShowChecklist({
  loading,
  userId,
  complete,
  dismissed,
}: ChecklistGateInput): boolean {
  if (loading) return false;
  if (!hasUser(userId)) return false;
  if (complete) return false;
  return !dismissed;
}

/** خواندنِ امنِ علامتِ همین کاربر (Storageِ در‌دسترس‌نبود → «دیده‌نشده»). */
export function readOnboardingSeen(
  storage: StorageLike | null | undefined,
  userId: number | string | null | undefined,
): boolean {
  if (!storage || !hasUser(userId)) return false;
  try {
    return storage.getItem(onboardingSeenKey(userId as number | string)) === "1";
  } catch {
    return false;
  }
}

/** نوشتنِ امنِ علامت. `true` یعنی ثبت شد؛ خطای Storage بی‌صدا نادیده می‌رود. */
export function writeOnboardingSeen(
  storage: StorageLike | null | undefined,
  userId: number | string | null | undefined,
): boolean {
  if (!storage || !hasUser(userId)) return false;
  try {
    storage.setItem(onboardingSeenKey(userId as number | string), "1");
    return true;
  } catch {
    return false;
  }
}
