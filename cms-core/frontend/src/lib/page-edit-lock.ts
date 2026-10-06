/**
 * WF-H3 — کمک‌تابع‌های خالصِ ذخیرهٔ خودکار و قفلِ نرمِ ویرایش.
 *
 * عمداً از React جدا نگه داشته شده‌اند تا با `node --test` قابل آزمون باشند؛
 * منطقِ زمان‌بندی با تزریقِ `TimerApi` آزمون‌پذیر شده (بدون نیاز به تایمر واقعی).
 */

const FA_DIGITS = ["۰", "۱", "۲", "۳", "۴", "۵", "۶", "۷", "۸", "۹"];

/** فاصلهٔ heartbeat قفل (ms) — هماهنگ با TTL ‏۹۰ ثانیه‌ایِ بک‌اند. */
export const LOCK_HEARTBEAT_MS = 25_000;

/** تأخیرِ ذخیرهٔ خودکار پس از آخرین تغییر (debounce). */
export const AUTOSAVE_MS = 30_000;

export type EditLockHolder = {
  user_id: number;
  user_name: string;
  heartbeat_at?: string | null;
};

export type TimerApi = {
  set: (fn: () => void, ms: number) => unknown;
  clear: (handle: unknown) => void;
};

export type Debouncer = {
  schedule: () => void;
  cancel: () => void;
  pending: () => boolean;
};

/** debounce ساده: هر فراخوانیِ تازه، زمان‌بندیِ قبلی را باطل می‌کند. */
export function createDebouncer(fn: () => void, ms: number, api: TimerApi): Debouncer {
  let handle: unknown = null;

  return {
    schedule() {
      if (handle !== null) api.clear(handle);
      handle = api.set(() => {
        handle = null;
        fn();
      }, ms);
    },
    cancel() {
      if (handle !== null) {
        api.clear(handle);
        handle = null;
      }
    },
    pending() {
      return handle !== null;
    },
  };
}

/** ساعت محلی به شکل HH:MM با ارقام فارسی، برای نشانِ «ذخیره شد در …». */
export function formatSavedClock(when: Date): string {
  const pad = (x: number) => String(x).padStart(2, "0");
  const hhmm = `${pad(when.getHours())}:${pad(when.getMinutes())}`;

  return hhmm.replace(/[0-9]/g, (d) => FA_DIGITS[Number(d)]);
}

export type AutosaveGate = {
  dirty: boolean;
  saving: boolean;
  publishing: boolean;
  hasConflict: boolean;
  hasLockHolder: boolean;
};

/** تنها شرطِ اجازهٔ زمان‌بندیِ ذخیرهٔ خودکار. */
export function autosaveGate(s: AutosaveGate): boolean {
  return s.dirty && !s.saving && !s.publishing && !s.hasConflict && !s.hasLockHolder;
}
