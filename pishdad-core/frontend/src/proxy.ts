import { NextRequest, NextResponse, type NextFetchEvent } from "next/server";
import { isLoginPath, LOGIN_PATH } from "@/lib/login-path";
import { inspectSessionToken } from "@/lib/session-cookie";
import {
  DEFAULT_PUBLIC_LOCALE,
  isPublicSitePath,
  parseLocalesHeader,
  resolveLocaleRoute,
} from "@/lib/public-routing";
import { effectiveValue, isInstalled } from "./lib/site-config.ts";

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
  // PERF-1/SEC-2 -- CSP by page SURFACE.
  //
  // A nonce in Next means "this page must be dynamically rendered", and
  // dynamic rendering means no ISR -- i.e. the public site would never be
  // cached. So:
  //
  //   - panel: nonce + strict-dynamic (the panel is never static; no
  //     conflict). The nonce is also set on the REQUEST header because Next
  //     reads it from there -- otherwise Next's own scripts and
  //     siteModeBootstrapJs render without a nonce and CSP blocks them.
  //   - public site: no nonce, `script-src 'self' 'unsafe-inline'`. The only
  //     inline content there is what we render and sanitize server-side
  //     (`safeHtml`) -- a conscious trade-off, recorded in docs/TASKS.md.
  const isPanel = panelRoots().has(firstSegment(req.nextUrl.pathname));
  const nonce = isPanel ? Buffer.from(crypto.randomUUID()).toString("base64") : "";
  const surface = isPanel ? "panel" : "site";

  const requestHeaders = new Headers(req.headers);
  if (isPanel) {
    requestHeaders.set("x-nonce", nonce);
    requestHeaders.set("Content-Security-Policy", cspFor("panel", nonce));
  }
  const request = new NextRequest(req, { headers: requestHeaders });

  const response = await handleProxy(request, event);

  // img/connect stay open (http:/https:) because the media/API base is
  // configurable at runtime via config/runtime.json, not at build time.
  response.headers.set("Content-Security-Policy", cspFor(surface, nonce));
  response.headers.set("X-Content-Type-Options", "nosniff");
  response.headers.set("Referrer-Policy", "strict-origin-when-cross-origin");
  response.headers.set("X-Frame-Options", "DENY");
  response.headers.set("Permissions-Policy", "geolocation=(), camera=(), microphone=()");

  return response;
}

/** CSP policy, extracted so it is read (and tested) in one place. */
function cspFor(surface: "panel" | "site", nonce: string): string {
  const scriptSrc =
    surface === "panel"
      ? `script-src 'self' 'nonce-${nonce}' 'strict-dynamic'`
      : "script-src 'self' 'unsafe-inline'";

  return [
    "default-src 'self'",
    scriptSrc,
    "style-src 'self' 'unsafe-inline'",
    "img-src 'self' data: blob: http: https:",
    "font-src 'self' data:",
    "connect-src 'self' http: https:",
    "frame-src 'self'",
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
    "frame-ancestors 'none'",
  ].join("; ");
}

async function handleProxy(req: NextRequest, event: NextFetchEvent) {
  // ⚠️ مسیرهای سیستمی **پیش از هر چیز** کنار می‌روند.
  //
  // `/setup` نباید به زبان بازنویسی شود: اگر بشود، به `/{locale}/setup` می‌رود و
  // روتِ واقعیِ `app/setup` هرگز اجرا نمی‌شود ⇒ نصب‌کننده ۴۰۴ می‌شد. همچنین
  // نباید قاعدهٔ ریدایرکتِ سایت (که ممکن است روی همان مسیر باشد) آن را بدزدد.
  if (SYSTEM_PATHS.has(firstSegment(req.nextUrl.pathname))) return NextResponse.next();

  // 🚪 اجرای نخست ⇒ نصب‌کننده. شرط‌های احتیاطی‌اش بالا توضیح داده شده‌اند.
  if (needsSetup() && !isNonPageRequest(req.nextUrl.pathname)) {
    const url = req.nextUrl.clone();
    url.pathname = SETUP_PATH;
    url.search = "";
    return NextResponse.redirect(url);
  }

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
  const raw = effectiveValue("PISHDAD_PANEL_ROOTS", process.env.NEXT_PUBLIC_PANEL_ROOTS);
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
 * مسیرهایی که به هیچ زبانی تعلّق ندارند و باید دست‌نخورده به روتِ خودشان برسند.
 *
 * `setup` تنها عضوش است: نصب‌کنندهٔ وبِ فرانت. بقیهٔ مسیرهای غیرِسایتی
 * (`/api`, `/_next`, `/robots.txt`, …) را خودِ `isPublicSitePath` کنار می‌گذارد.
 */
// SEC-9 — `preview` هم مسیرِ سیستمی شد. پیش‌تر تنها `setup` اینجا بود، پس
// `/preview` و `/preview/[theme]` از دروازهٔ پنل رد می‌شدند و **کاملاً عمومی**
// بودند: کرومِ پنل برای هر بازدیدندهٔ بی‌احراز لو می‌رفت و `force-dynamic` روی
// یک مسیرِ داغِ عمومی هزینه داشت. حالا مثل `/setup` کنار می‌رود و صفحهٔ
// خودش تصمیم می‌گیرد (نشست را چک می‌کند).
const SYSTEM_PATHS = new Set(["setup", "preview"]);

/** مسیرِ نصب‌کننده. */
const SETUP_PATH = "/setup";

/**
 * 🚪 دروازهٔ **اجرای نخست**: این نصب هرگز تنظیم نشده است؟
 *
 * اگر نه فایلِ نصب (`/setup` را کسی پر نکرده) باشد و نه اپراتور مقدارِ محیطی
 * داده باشد، فرانت هیچ نشانیِ بک‌اندی ندارد. در آن حالت هر صفحهٔ عمومی به
 * `/setup` می‌رود تا کاربر **همان اولین بار** نشانی را بدهد، به‌جای اینکه یک
 * سایتِ نیمه‌کاره ببیند و نداند چه چیزی کم است.
 *
 * ⚠️ دو شرطِ احتیاطی، چون این دروازه در مسیرِ **هر** درخواست است:
 *
 *   • اگر اپراتور `PISHDAD_PUBLIC_API_URL` را از محیط داده باشد، هرگز فعال نمی‌شود
 *     ⇒ استقرارهای متغیر-محور به نصب‌کننده پرت نمی‌شوند.
 *   • مقدارِ زمانِ بیلد تنها وقتی «تنظیم‌شده» شمرده می‌شود که با پیش‌فرضِ
 *     Dockerfile فرق داشته باشد. آن پیش‌فرض همیشه حاضر است، پس معیارِ
 *     «تنظیم‌شده» نیست — وگرنه دروازه هرگز روشن نمی‌شد.
 */
function needsSetup(): boolean {
  if (isInstalled()) return false;
  if (effectiveValue("PISHDAD_PUBLIC_API_URL", "").trim() !== "") return false;

  const baked = (process.env.NEXT_PUBLIC_API_URL ?? "").trim();
  return baked === "" || baked === "http://localhost:8080/api";
}

/**
 * درخواست‌هایی که «صفحه» نیستند و نباید به نصب‌کننده ریدایرکت شوند.
 *
 * اگر این‌ها هم ریدایرکت می‌شدند، خودِ صفحهٔ نصب هم نمی‌توانست دارایی‌هایش را
 * بار کند (`/_next/static/...`) و پروکسیِ API و کشِ Next می‌شکستند.
 */
function isNonPageRequest(pathname: string): boolean {
  return (
    pathname.startsWith("/api") ||
    pathname.startsWith("/_next") ||
    pathname.startsWith("/fonts/") ||
    pathname === "/favicon.ico" ||
    pathname === "/robots.txt" ||
    pathname === "/sitemap.xml" ||
    pathname === "/manifest.webmanifest" ||
    /\.[a-z0-9]{2,5}$/i.test(pathname)
  );
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
  // ترتیب: مقدارِ زندهٔ عمومی → مقدارِ زندهٔ داخلی → مقدارِ زمانِ بیلد → پیش‌فرض.
  return (
    effectiveValue("PISHDAD_PUBLIC_API_URL", "") ||
    effectiveValue("INTERNAL_API_URL", "") ||
    effectiveValue("PISHDAD_INTERNAL_API_URL", "") ||
    (process.env.NEXT_PUBLIC_API_URL ?? "").trim() ||
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
/**
 * E45 + PERF-1 - loop guard without mutating the request.
 *
 * Next re-invokes the proxy on an internally rewritten path. The old marker
 * (x-pishdad-locale-rewrite) was set on the request, and that mutation made
 * every public route dynamic in Next 16 (no-store answers). Now the guard
 * reads the internal x-middleware-rewrite request header that Next itself
 * sets on the second pass: there we serve as-is instead of redirecting back
 * (which would loop with the primary-prefix strip rule). Spoofing it is
 * harmless: at most a cosmetic redirect is skipped; the panel gate runs
 * before this function.
 */
/** PERF-1 — مسیرهای مستقل از زبان؛ هرگز بازنویسیِ locale نمی‌خورند. */
const STATIC_SITE_PATHS = new Set([
  "/robots.txt",
  "/sitemap.xml",
  "/llms.txt",
  "/manifest.webmanifest",
  "/favicon.ico",
]);

function siteLocale(req: NextRequest): NextResponse {
  const pathname = req.nextUrl.pathname;
  if (!isPublicSitePath(pathname)) return NextResponse.next();
  if (STATIC_SITE_PATHS.has(pathname)) return NextResponse.next();

  // Second pass of an internal rewrite: serve as-is. Redirecting back here
  // would loop with the primary-prefix strip rule (the E45 loop).
  if (req.headers.get("x-middleware-rewrite") !== null) {
    return NextResponse.next();
  }

  const locales = parseLocalesHeader(effectiveValue("PISHDAD_SITE_LOCALES", process.env.NEXT_PUBLIC_SITE_LOCALES));
  const primary =
    parseLocalesHeader(effectiveValue("PISHDAD_SITE_PRIMARY_LOCALE", process.env.NEXT_PUBLIC_SITE_PRIMARY_LOCALE))[0] ??
    DEFAULT_PUBLIC_LOCALE;
  const effective = locales.length > 0 ? locales : [primary];
  const decision = resolveLocaleRoute(pathname, effective, primary);

  if (decision.action === "redirect") {
    const url = req.nextUrl.clone();
    url.pathname = decision.path ?? "/";
    return NextResponse.redirect(url, 308);
  }

  if (decision.action === "rewrite") {
    const url = req.nextUrl.clone();
    url.pathname = decision.path ?? pathname;
    return NextResponse.rewrite(url);
  }

  return NextResponse.next();
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
