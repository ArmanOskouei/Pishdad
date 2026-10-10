import { effectivePublicConfig } from "@/lib/site-config";
import { effectiveValue } from "@/lib/site-config";

/**
 * PERF-1 — پیکربندیِ زمانِ اجرا، بدون inline script.
 *
 * ## چرا Route Handler و نه درونِ layout
 *
 * `layout.tsx` پیش از این دو اسکریپت inline داشت: یکی پیکربندیِ عمومی و یکی
 * ضدِ FOUC. inline در HTML یعنی (الف) هر صفحه یکی می‌شود و کشِ لبه را
 * غیرقابلِ اشتراک می‌کند، و (ب) نیازِ CSP به nonce — و nonce در Next
 * «صفحه باید داینامیک باشد». یعنی ISR و nonce ذاتاً ناسازگارند.
 *
 * این Route Handler داینامیک است (خودش) ولی خروجی‌اش **کششدنی** است
 * (`max-age=60`). لایوت می‌تواند استاتیک/ISR شود و این مسیر همچنان
 * `config/runtime.json` را در زمانِ اجرا بخواند — که کل هدفِ نصب‌کنندهٔ وب
 * است (بدون بازسازی).
 *
 * ⚠️ فقط مقادیرِ **عمومی**: همان سه کلیدی که `effectivePublicConfig()`
 * allowlist کرده (هرگز revalidateSecret). `panelRoots` و `locales` هم عمومی
 * هستند و از قبل به مرورگر می‌رفتند.
 */

export const dynamic = "force-dynamic";

export async function GET(): Promise<Response> {
  const pub = effectivePublicConfig();
  const panelRoots = effectiveValue("PISHDAD_PANEL_ROOTS", "");
  const locales = effectiveValue("PISHDAD_SITE_LOCALES", "");

  const payload = {
    ...pub,
    panelRoots: (panelRoots || "admin")
      .split(",")
      .map((s) => s.trim())
      .filter(Boolean),
    locales: (locales || "fa,en")
      .split(",")
      .map((s) => s.trim())
      .filter(Boolean),
  };

  const body = `window.__CMS_CONFIG__=${JSON.stringify(payload).replace(/</g, "\\u003c")};`;

  return new Response(body, {
    headers: {
      "Content-Type": "application/javascript; charset=utf-8",
      "Cache-Control": "public, max-age=60, stale-while-revalidate=300",
    },
  });
}
