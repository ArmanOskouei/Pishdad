import { NextRequest, NextResponse } from "next/server";
import { isLoginPath, LOGIN_PATH } from "@/lib/login-path";
import { inspectSessionToken } from "@/lib/session-cookie";
import {
  DEFAULT_PUBLIC_LOCALE,
  isPublicSitePath,
  localesForHeader,
  parseLocalesHeader,
  resolveLocaleRoute,
  SITE_LOCALE_HEADER,
  SITE_LOCALES_HEADER,
  SITE_PRIMARY_LOCALE_HEADER,
} from "@/lib/public-routing";

/**
 * محافظت روت‌های پنل (F1) — سخت‌شده در K6.6.
 *
 * پیش از این، شرط فقط `if (!token)` بود، یعنی صرفاً **وجود** کوکی سنجیده
 * می‌شد: غیبت و رشتهٔ خالی رد می‌شدند ولی `"x"` یا `"null"` یا یک کوکی
 * دستکاری‌شده عبور می‌کرد. حالا `inspectSessionToken` شکل مقدار را می‌سنجد.
 *
 * ⛔ این احراز هویت نیست؛ اصالت توکن را بک‌اند با `Authorization: Bearer`
 * راستی‌آزمایی می‌کند. این لایه فقط دروازهٔ اولیهٔ پوستهٔ پنل است.
 */
export function proxy(req: NextRequest) {
  // L-B12 — ریشه‌های محافظت‌شده از پیکربندی می‌آیند، نه از کدِ هسته.
  //
  // قبلاً matcher دقیقاً `/admin` و `/central` بود، یعنی هستهٔ منتشرشده نامِ
  // افزونهٔ خصوصی را داخل خود داشت و افزودن پنلِ افزونهٔ بعدی یعنی ویرایش
  // هسته. حالا `NEXT_PUBLIC_PANEL_ROOTS` تعیین می‌کند کدام ریشه‌ها پنل‌اند:
  // در build عمومی فقط `/admin`، و هر پنلِ افزونه‌ای خودش را اعلام می‌کند.
  if (!panelRoots().has(firstSegment(req.nextUrl.pathname))) {
    return siteLocale(req);
  }

  const decision = inspectSessionToken(req.cookies.get("auth_token")?.value);
  if (!decision.ok) {
    // ⭐ خودِ صفحهٔ ورود **نباید** به خودش ریدایرکت شود.
    //
    // لاگین داخل `/admin` است (قرارداد: آدرسش `/admin/login`)، و پروکسی هر
    // مسیر زیر ریشهٔ پنل را می‌بندد. پس بدون این استثنا:
    //     /admin/dashboard → /admin/login → /admin/login → … → بی‌نهایت
    // یعنی هیچ‌کس هرگز وارد پنل نمی‌شد.
    if (isLoginPath(req.nextUrl.pathname)) {
      return NextResponse.next();
    }

    const url = req.nextUrl.clone();
    url.pathname = LOGIN_PATH;

    return NextResponse.redirect(url);
  }
  return NextResponse.next();
}

/**
 * ریشه‌هایی که پوستهٔ پنل‌اند و پروکسی باید جلویشان را بگیرد.
 *
 * `admin` همیشه هست — پنل مشتری بخشی از محصول است. بقیه از محیط می‌آید.
 * اعتبارسنجی عمداً سخت‌گیرانه است: یک رشتهٔ بد می‌توانست با `/` یا `..`
 * الگوی matcher را دور بزند، پس هر چیزی که شبیه یک slug معتبر نباشد
 * نادیده گرفته می‌شود (fail-closed).
 */
function panelRoots(): Set<string> {
  const raw = process.env.NEXT_PUBLIC_PANEL_ROOTS ?? "";
  const extra = raw
    .split(",")
    .map((s) => s.trim().replace(/^\/+|\/+$/g, ""))
    .filter((s) => /^[a-z0-9][a-z0-9._-]*$/.test(s));
  return new Set(["admin", ...extra]);
}

function firstSegment(pathname: string): string {
  return pathname.split("/").filter(Boolean)[0] ?? "";
}

/**
 * ECO2 — زبانِ سایتِ عمومی از URL.
 *
 * مدل a: زبان پایه در ریشه، زبان دوم زیر `/{locale}`. فهرست و زبان اصلی نصب از
 * env می‌آید (تا میدل‌ور به بک‌اند وابسته نشود و هر درخواست یک fetch نگیرد).
 * مقدار به‌صورت هدر به مسیر می‌رود تا روت‌ها فقط بخوانند (بدون setState/رندر
 * مجدد — هم‌خطِ قانون ضد React #301).
 *
 * ⭐ مسئله‌ای که این تابع می‌بست: در درختِ مسیرها، `[locale]` بر `[...path]`
 * اولویت دارد، پس `/about` به‌جای صفحهٔ عمیق به `[locale]/page.tsx` با
 * `locale="about"` می‌خورد و ۴۰۴ می‌شد. حالا مسیرِ بدونِ پیشوند به‌صورت
 * **داخلی** به `/{primary}/...` بازنویسی می‌شود تا `[locale]` تنها رندرکنندهٔ
 * عمومی باشد.
 */
function siteLocale(req: NextRequest): NextResponse {
  const pathname = req.nextUrl.pathname;
  if (!isPublicSitePath(pathname)) return NextResponse.next();

  const locales = parseLocalesHeader(process.env.NEXT_PUBLIC_SITE_LOCALES ?? "");
  const primary =
    parseLocalesHeader(process.env.NEXT_PUBLIC_SITE_PRIMARY_LOCALE ?? "")[0] ?? DEFAULT_PUBLIC_LOCALE;
  const effective = locales.length > 0 ? locales : [primary];
  const decision = resolveLocaleRoute(pathname, effective, primary);

  if (decision.action === "redirect") {
    const url = req.nextUrl.clone();
    url.pathname = decision.path ?? "/";
    return NextResponse.redirect(url, 308);
  }

  const headers = new Headers(req.headers);
  headers.set(SITE_LOCALE_HEADER, decision.locale);
  headers.set(SITE_LOCALES_HEADER, localesForHeader(effective));
  headers.set(SITE_PRIMARY_LOCALE_HEADER, primary);

  if (decision.action === "rewrite") {
    const url = req.nextUrl.clone();
    url.pathname = decision.path ?? pathname;
    return NextResponse.rewrite(url, { request: { headers } });
  }

  return NextResponse.next({ request: { headers } });
}

/**
 * matcher **باید** رشتهٔ ایستا باشد: Next این فلد را هنگام کامپایل پارس
 * می‌کند و template literal را رد می‌کند.
 *
 * ولی از آن‌جا که فهرست ریشه‌ها دیگر ایستا نیست، الگو **همه‌چیز** را می‌گیرد و
 * تصمیم به تابع بالا منتقل می‌شود. بهایش یک شرط ارزان روی هر درخواست است، در
 * ازای اینکه افزودن یک پنلِ افزونه‌ای دیگر **ویرایش کد هسته** نخواهد بود.
 */
export const config = { matcher: ["/:path*"] };
