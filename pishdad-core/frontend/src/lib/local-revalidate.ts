/**
 * باطل‌سازی محلیِ کش سایت از پنل (بدون انتظار برای webhook بک‌اند).
 *
 * چرا لازم است: ذخیرهٔ هدر/فوتر و تنظیمات باید **همان لحظه** روی سایت دیده شود،
 * دقیقاً مثلِ «فعال‌سازی قالب». بک‌اند هم webhook امضاشده می‌فرستد، ولی این
 * مسیرِ کلاینت (نشست معتبر + allowlist) یک لایهٔ مطمئنِ دوم است: اگر بک‌اند به
 * میزبان فرانت دسترسی نداشت، تغییر بی‌صدا کهنه نمی‌ماند.
 *
 * تگ‌ها باید در allowlistِ `lib/revalidate.ts` باشند (`pages`، `site-chrome`، …).
 */
export async function localRevalidate(tags: string[]): Promise<void> {
  try {
    await fetch("/api/revalidate", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ tags, local: true }),
    });
  } catch {
    /* باطل‌سازی نباید ذخیره را بشکند. */
  }
}
