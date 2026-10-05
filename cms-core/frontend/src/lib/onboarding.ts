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
