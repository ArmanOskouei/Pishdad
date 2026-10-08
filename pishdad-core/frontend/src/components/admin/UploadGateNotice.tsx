import { useCallback, useState } from "react";

import { gateNotice, type DevModeAvailability } from "@/lib/upload-gate";

/**
 * K4.8 — دلیلِ قفل بودن نصب بدون امضا.
 *
 * ## چرا این یک کامپوننت جداست و نه چند خط داخل `PluginsClient`
 *
 * دکمهٔ نصب امروز می‌گوید «نصب پلاگین (ZIP امضاشده)» و همین. اگر کاربر بستهٔ
 * بدون امضا داشته باشد، تنها چیزی که می‌بیند این است که آپلود رد شد — بدون آنکه
 * بفهمد **راهی برای کار کردن با بستهٔ خودش وجود دارد** یا ندارد.
 *
 * حالت توسعه‌دهنده دقیقاً همان راه است، ولی تا وقتی وضعیتش به این صفحه نرسد، این
 * یک راز است. کامپوننت مستقل یعنی این منطق قابل‌تست و قابل‌اتصال است بدون آنکه
 * به کامپوننتی دست بزنم که تسک دیگری مال آن است.
 *
 * ## قاعدهٔ صداقت
 *
 * اگر حالت توسعه‌دهنده باز نیست، این کامپوننت **نمی‌گوید** چطور می‌شود دور زد.
 * فقط می‌گوید مسیر رسمی چیست. راهنمای دور زدن دروازهٔ امضا، بدافزار است اگر در
 * دست کاربر بی‌دانا باشد.
 */

/** آنچه واقعاً سرور می‌داند. */
export type { DevModeAvailability } from "@/lib/upload-gate";

export const DEV_MODE_UNAVAILABLE: DevModeAvailability = {
  enabled: null,
  expiresAt: null,
  canUnlock: false,
};

export type UploadGateNoticeProps = {
  state: DevModeAvailability;
  /** لینک به جایی که کاربر حالت توسعه‌دهنده را باز می‌کند. */
  unlockHref?: string;
  /** وقتی سرور گفت بستهٔ بدون امضا مجاز است (نمایش‌دادنی برای تست dev). */
  unsignedAllowed?: boolean;
};

export function UploadGateNotice({ state, unlockHref = "/admin/settings", unsignedAllowed }: UploadGateNoticeProps) {
  const notice = gateNotice(state, unsignedAllowed === true);
  if (notice === "none") return null;

  if (notice === "open") {
    return (
      <div role="status" className="alert alert-blue">
        حالت توسعه‌دهنده باز است — بستهٔ بدون امضا می‌تواند نصب شود. این حالت
        {state.expiresAt ? " به‌صورت خودکار منقضی می‌شود" : ""} و در ردپا ثبت می‌گردد.
      </div>
    );
  }

  return (
    <div role="note" className="alert alert-amber">
      بستهٔ بدون امضا فقط در حالت توسعه‌دهنده نصب می‌شود. برای باز کردنش به{" "}
      <a href={unlockHref}>تنظیمات</a> بروید — به پنج کلیک و سپس رمز عبور خودتان
      نیاز دارید.
    </div>
  );
}

/**
 * حالت بارگذاری وضعیت، جدا از کامپوننت.
 *
 * جدا بودنش یعنی هیچ `fetch`ای داخل رندر نیست — قانونی که این پروژه یک بار بابت
 * نقضش (حلقهٔ رندر و خطای #301) تاوان پرداخت.
 */
export function useDevModeAvailability(
  fetchState: () => Promise<Partial<DevModeAvailability>>,
  initial: DevModeAvailability = DEV_MODE_UNAVAILABLE,
) {
  const [state, setState] = useState<DevModeAvailability>(initial);
  const [error, setError] = useState<string | null>(null);

  const reload = useCallback(async () => {
    try {
      const next = await fetchState();
      setState((prev) => ({ ...prev, ...next }));
    } catch (e) {
      setError(e instanceof Error ? e.message : "خواندن وضعیت ناموفق بود.");
    }
  }, [fetchState]);

  return { state, error, reload };
}
