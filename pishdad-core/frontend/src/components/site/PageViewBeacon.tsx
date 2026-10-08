"use client";
import { useEffect } from "react";
import { usePathname } from "next/navigation";
import { publicConfig } from "@/lib/runtime-config";

const API_BASE = publicConfig().apiUrl;

/**
 * WF-H12 — چراغ سبک بازدیدِ سایت عمومی.
 *
 * هنگام mount مسیر جاری و ارجاع‌دهنده را یک‌بار POST می‌کند. بدون کوکی و
 * بدون PII: نه شناسه‌ای می‌سازد نه ذخیره می‌کند. ارجاع‌دهنده سمت سرور به
 * میزبان فروکاسته می‌شود.
 *
 * برای اینکه ناوبریِ رفت/برگشت و دو بار اجرای Strict Mode یک بازدید را دو
 * بار نشمارد، هر مسیر در `sessionStorage` علامت می‌خورد. کلید session-only
 * است، پس پایان نشست پاک می‌شود و کوکی‌ای در کار نیست.
 */
export function PageViewBeacon() {
  const pathname = usePathname();

  useEffect(() => {
    if (!pathname || pathname.startsWith("/admin")) return;

    const key = `cms-pv:${pathname}`;
    try {
      if (sessionStorage.getItem(key)) return;
      sessionStorage.setItem(key, "1");
    } catch {
      // حالت خصوصی/دسترسی‌نداشتن: صرفاً بدون dedup ادامه می‌دهیم.
    }

    const body = new URLSearchParams({
      path: pathname,
      referrer: typeof document !== "undefined" ? document.referrer : "",
    });
    const url = `${API_BASE}/v1/site/analytics/view`;

    // `sendBeacon` مرورگر فقط درخواست ساده می‌فرستد؛ فرم‌انکد (نه JSON) یعنی
    // preflight لازم نمی‌شود و CORS بازِ endpoint جواب می‌دهد.
    try {
      if (typeof navigator.sendBeacon === "function" && navigator.sendBeacon(url, body)) {
        return;
      }
    } catch {
      // عبور به fetch
    }

    void fetch(url, {
      method: "POST",
      body,
      keepalive: true,
    }).catch(() => {
      // تحلیل نباید هیچ‌وقت خطای کاربر بسازد.
    });
  }, [pathname]);

  return null;
}
