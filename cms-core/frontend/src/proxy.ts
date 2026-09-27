import { NextRequest, NextResponse } from "next/server";
import { inspectSessionToken } from "@/lib/session-cookie";

/**
 * محافظت روت‌های admin/central (F1) — سخت‌شده در K6.6.
 *
 * پیش از این، شرط فقط `if (!token)` بود، یعنی صرفاً **وجود** کوکی سنجیده
 * می‌شد: غیبت و رشتهٔ خالی رد می‌شدند ولی `"x"` یا `"null"` یا یک کوکی
 * دست‌کاری‌شده عبور می‌کرد. حالا `inspectSessionToken` شکل مقدار را می‌سنجد.
 *
 * ⛔ این احراز هویت نیست؛ اصالت توکن را بک‌اند با `Authorization: Bearer`
 * راستی‌آزمایی می‌کند. این لایه فقط دروازهٔ اولیهٔ پوستهٔ پنل است.
 */
export function proxy(req: NextRequest) {
  const decision = inspectSessionToken(req.cookies.get("auth_token")?.value);
  if (!decision.ok) {
    const url = req.nextUrl.clone();
    url.pathname = "/login";
    return NextResponse.redirect(url);
  }
  return NextResponse.next();
}

/**
 * matcher **باید** رشتهٔ استاتیک باشد: Next این فیلد را در زمان کامپایل
 * ایستا پارس می‌کند و template literal با یک ثابت (`${PLUGIN_ADMIN_ROOT}/:path*`)
 * را رد می‌کند. برای همین ریشه اینجا تکرار شده و **هم‌بودنِ** آن با
 * `PLUGIN_ADMIN_ROOT` در تست قفل شده: `plugin-admin-path.test.ts`
 * («ریشهٔ پروکسی و ریشهٔ سازندهٔ مسیر یکی است»). یکی را عوض کنی و تست
 * بیفتد ⇒ نمی‌شود صفحه‌ای ساخت که پروکسی محافظش نیست.
 *
 * `:path*` صفر-یا-بیشتر است، پس خودِ `/admin` هم پوشش دارد.
 */
export const config = { matcher: ["/admin/:path*", "/central/:path*"] };
