import { NextRequest, NextResponse, type NextFetchEvent } from "next/server";
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
export async function proxy(req: NextRequest, event: NextFetchEvent) {
  // WF-C2 — ریدایرکت‌های سایت پیش از هر چیز (پیش از بازنویسیِ زبان).
  //
  // مسیرِ ورودی باید **پیش از** `siteLocale` تطبیق داده شود، وگرنه مسیرِ
  // بدون‌پیشوند (`/old`) اول به `/{primary}/old` بازنویسی می‌شود و ریدایرکت
  // هرگز شلیک نمی‌شود.
  if (req.method === "GET" && !panelRoots().has(firstSegment(req.nextUrl.pathname))) {
    const hit = await matchRedirect(req, event);
    if (hit) return hit;
  }

  // ریشه‌های محافظت‌شده از پیکربندی می‌آیند، نه از کدِ هسته.
  //
  // اگر این فهرست در کد هاردکد می‌شد، هستهٔ منتشرشده نامِ پنل‌های دیگر را داخل
  // خود داشت و افزودنِ پنلِ بعدی یعنی ویرایش هسته. حالا
  // `NEXT_PUBLIC_PANEL_ROOTS` تعیین می‌کند کدام ریشه‌ها پنل‌اند: در build عمومی
  // فقط `/admin`، و هر پنلِ دیگری خودش را اعلام می‌کند.
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

/* ── WF-C2 — ریدایرکت‌های سایت (۳۰۱/۳۰۲) ─────────────────────────────────
 * فهرستِ فعال از بک‌اند با TTL کوتاه کش می‌شود تا هر درخواست یک fetch نگیرد.
 * در نبودِ بک‌اند کاملاً fail-open است: سایت هرگز به‌خاطر ریدایرکت‌ها نمی‌شکند.
 */
type RedirectRule = { from_path: string; to_path: string; status_code: number };

const REDIRECT_TTL_MS = 30_000;
let redirectCache: { at: number; rules: RedirectRule[] } | null = null;
let redirectInflight: Promise<RedirectRule[]> | null = null;

function apiBase(): string {
  return (
    process.env.NEXT_PUBLIC_API_URL ??
    process.env.INTERNAL_API_URL ??
    "http://localhost:8080/api"
  ).replace(/\/+$/, "");
}

function normalizeRedirectPath(path: string): string {
  const withSlash = "/" + path.replace(/^\/+/, "");
  return withSlash.length > 1 ? withSlash.replace(/\/+$/, "") : withSlash;
}

async function redirectRules(): Promise<RedirectRule[]> {
  if (redirectCache && Date.now() - redirectCache.at < REDIRECT_TTL_MS) {
    return redirectCache.rules;
  }
  if (redirectInflight) return redirectInflight;

  redirectInflight = (async () => {
    try {
      const res = await fetch(`${apiBase()}/v1/site/redirects`, { cache: "no-store" });
      if (!res.ok) throw new Error(String(res.status));
      const json = (await res.json()) as { data?: RedirectRule[] };
      const rules = Array.isArray(json.data) ? json.data : [];
      redirectCache = { at: Date.now(), rules };
      return rules;
    } catch {
      redirectCache = { at: Date.now(), rules: redirectCache?.rules ?? [] };
      return redirectCache.rules;
    } finally {
      redirectInflight = null;
    }
  })();

  return redirectInflight;
}

async function matchRedirect(req: NextRequest, event: NextFetchEvent): Promise<NextResponse | null> {
  const pathname = req.nextUrl.pathname;
  if (pathname.startsWith("/api") || pathname.startsWith("/_next")) return null;

  const rules = await redirectRules();
  if (rules.length === 0) return null;

  const wanted = normalizeRedirectPath(pathname);
  const rule = rules.find((r) => normalizeRedirectPath(r.from_path) === wanted);
  if (!rule) return null;

  const status = rule.status_code === 302 ? 302 : 301;
  const dest = /^https?:\/\//i.test(rule.to_path)
    ? rule.to_path
    : new URL(rule.to_path.startsWith("/") ? rule.to_path : `/${rule.to_path}`, req.nextUrl.origin);

  event.waitUntil(
    fetch(`${apiBase()}/v1/site/redirects/hit`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ path: wanted }),
    }).catch(() => undefined),
  );

  return NextResponse.redirect(dest, status);
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
