"use client";
import { useEffect } from "react";
import { usePathname } from "next/navigation";
import { publicConfig } from "@/lib/runtime-config";

const API_BASE = publicConfig().apiUrl;

/**
 * WF-M14 — چراغِ سبک Web Vitals (LCP/INP/CLS) برای سایت عمومی.
 *
 * بدون وابستگی خارجی (`web-vitals` اضافه نشده): مستقیم از `PerformanceObserver`
 * استفاده می‌کنیم. مقدارها هنگام ترک صفحه (hide/pagehide) یک‌بار با
 * `sendBeacon` فرستاده می‌شوند؛ اگر `sendBeacon` نبود `fetch` با `keepalive`.
 *
 * حریمِ خصوصی: هیچ شناسهٔ پایدار، کوکی یا PII فرستاده/ذخیره نمی‌شود. فقط
 * مسیر + سه عددِ تجمعی. بازدیدهای `/admin` نادیده گرفته می‌شوند.
 *
 * LCP: آخرین ورودیِ `largest-contentful-paint` (renderTime/loadTime در اولویت).
 * CLS: جمعِ shiftهای بدون ورودی کاربر در پنجره‌های نشست (۱ثانیه فاصله/۵ثانیه سقف).
 * INP: صدک ۹۸ تأخیر تعامل‌ها (آستانهٔ رخداد ۴۰ms).
 */

/** صدک به روش نزدیک‌ترین رتبه؛ فهرست خالی ⇒ null. */
function percentile(values: readonly number[], p: number): number | null {
  if (values.length === 0) return null;
  const sorted = [...values].sort((a, b) => a - b);
  const index = Math.min(sorted.length - 1, Math.max(0, Math.ceil(sorted.length * p) - 1));
  return sorted[index];
}

type LcpEntry = PerformanceEntry & { renderTime?: number; loadTime?: number };
type ShiftEntry = PerformanceEntry & { value: number; hadRecentInput: boolean };
type EventEntry = PerformanceEntry & { interactionId?: number };

export function WebVitalsBeacon() {
  const pathname = usePathname();

  useEffect(() => {
    if (!pathname || pathname.startsWith("/admin")) return;
    if (typeof window === "undefined" || typeof PerformanceObserver === "undefined") return;

    let lcp = 0;
    let cls = 0;
    const inpDurations: number[] = [];

    let sessionValue = 0;
    let sessionEntries: number[] = [];

    let sent = false;
    const observers: PerformanceObserver[] = [];

    const observe = (
      type: string,
      callback: (entries: PerformanceEntryList) => void,
      options: PerformanceObserverInit = {},
    ) => {
      try {
        const observer = new PerformanceObserver((list) => callback(list.getEntries()));
        observer.observe({ type, buffered: true, ...options });
        observers.push(observer);
      } catch {
        // نوعِ ورودی در این مرورگر پشتیبانی نمی‌شود — معیارهای دیگر ادامه می‌دهند.
      }
    };

    observe("largest-contentful-paint", (entries) => {
      const entry = entries[entries.length - 1] as LcpEntry | undefined;
      if (!entry) return;
      const render = entry.renderTime && entry.renderTime > 0 ? entry.renderTime : 0;
      const load = entry.loadTime && entry.loadTime > 0 ? entry.loadTime : 0;
      lcp = render || load || entry.startTime;
    });

    observe("layout-shift", (entries) => {
      for (const raw of entries) {
        const entry = raw as ShiftEntry;
        if (entry.hadRecentInput) continue;
        const first = sessionEntries[0];
        const last = sessionEntries[sessionEntries.length - 1];
        if (sessionEntries.length > 0 && (entry.startTime - last > 1000 || entry.startTime - first > 5000)) {
          sessionValue = 0;
          sessionEntries = [];
        }
        sessionValue += entry.value;
        sessionEntries.push(entry.startTime);
        if (sessionValue > cls) cls = sessionValue;
      }
    });

    observe(
      "event",
      (entries) => {
        for (const entry of entries as EventEntry[]) {
          if (entry.duration > 0) inpDurations.push(entry.duration);
        }
      },
      { durationThreshold: 40 } as PerformanceObserverInit,
    );

    const send = () => {
      if (sent) return;

      const inp = percentile(inpDurations, 0.98);
      // اگر هیچ معیاری مشاهده نشده، رکوردِ صفرِ بی‌معنا نفرست (مثلاً پاک‌سازیِ
      // زودهنگامِ Strict Mode). LCP هر صفحهٔ رندرشده‌ای > ۰ است پس سیگنالِ معتبری است.
      if (lcp <= 0 && inp === null && cls <= 0) return;
      sent = true;

      const body = new URLSearchParams({ path: pathname });
      if (lcp > 0) body.set("lcp", String(Math.round(lcp)));
      if (inp !== null) body.set("inp", String(Math.round(inp)));
      body.set("cls", String(Math.round(cls * 1000) / 1000));

      const url = `${API_BASE}/v1/site/analytics/vitals`;
      try {
        if (typeof navigator.sendBeacon === "function" && navigator.sendBeacon(url, body)) return;
      } catch {
        // عبور به fetch
      }
      void fetch(url, { method: "POST", body, keepalive: true }).catch(() => {
        // تحلیل نباید هیچ‌وقت خطای کاربر بسازد.
      });
    };

    const onVisibility = () => {
      if (document.visibilityState === "hidden") send();
    };

    document.addEventListener("visibilitychange", onVisibility);
    window.addEventListener("pagehide", send);

    return () => {
      // ترکِ مسیر با ناوبری کلاینتی: معیارهای همین مسیر را بفرست.
      send();
      document.removeEventListener("visibilitychange", onVisibility);
      window.removeEventListener("pagehide", send);
      observers.forEach((observer) => observer.disconnect());
    };
  }, [pathname]);

  return null;
}
