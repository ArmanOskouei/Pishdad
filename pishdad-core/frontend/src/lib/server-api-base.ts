import { effectiveValue } from "./site-config.ts";

/**
 * SEC-6 — پایهٔ API سمتِ سرور، خوانده‌شده در **زمانِ درخواست**.
 *
 * ## چرا تابع و نه ثابتِ ماژول
 *
 * نصب‌کنندهٔ وب `config/runtime.json` را **بعد از** بالا آمدنِ سرور می‌نویسد.
 * هر مقداری که در اسکوپِ ماژول خوانده شود یک بار در زمانِ بوت قفل می‌شود و
 * تغییرِ فایل نباید بی‌اثر بماند. پیش از این، چند Route Handler و
 * `lib/server-api.ts` ثابتِ ماژول داشتند و نیمهٔ پنل نصب‌کننده را کاملاً
 * نادیده می‌گرفت.
 *
 * `readSiteConfig()` پشتِ این هزینه را با کشِ mtime می‌گیرد، پس صدا زدنِ
 * مکررش یک stat است نه یک شبکه.
 */
export function serverApiBase(): string {
  return (
    effectiveValue("INTERNAL_API_URL", "") ||
    effectiveValue("PISHDAD_PUBLIC_API_URL", "") ||
    (process.env.INTERNAL_API_URL ?? "").trim() ||
    (process.env.NEXT_PUBLIC_API_URL ?? "").trim() ||
    "http://localhost:8080/api"
  );
}
