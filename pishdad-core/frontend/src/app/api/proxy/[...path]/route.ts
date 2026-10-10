import { NextRequest, NextResponse } from "next/server";
import { checkPath } from "@/lib/proxy-allowlist";
import { serverApiBase } from "@/lib/server-api-base";
import { clientIpHeaders } from "@/lib/client-ip";

/** پروکسی احرازهویت‌شده: /api/proxy/v1/admin/... → بک‌اند با Bearer از کوکی httpOnly. */

/** SEC-5 — سقفِ بدنه: multipart/zip ۲۵MB، JSON ۲MB (هم‌راستا با سقف‌های nginx). */
const MAX_MULTIPART_BYTES = 25 * 1024 * 1024;
const MAX_JSON_BYTES = 2 * 1024 * 1024;

async function proxy(req: NextRequest, path: string[]) {
  const token = req.cookies.get("auth_token")?.value;
  if (!token) return NextResponse.json({ message: "نشست منقضی شد — دوباره وارد شوید." }, { status: 401 });

  // مسیر کاربر هرگز مستقیم به `UPSTREAM` نمی‌رود؛ بدون این allowlist هر کسی که
  // لاگین کرده بود می‌توانست `internal/heartbeat` (بدون auth) را صدا بزند.
  const decision = checkPath(path);
  if (!decision.ok) return NextResponse.json({ message: decision.reason }, { status: 403 });

  const target = `${serverApiBase()}/${decision.path}${req.nextUrl.search}`;
  const contentType = req.headers.get("content-type") ?? "";

  // SEC-3/CSRF — لایهٔ دفاع برای متدهای نوشتن: `Origin` (یا `Sec-Fetch-Site`)
  // باید با origin خودِ سرور یکی باشد. کوکی `SameSite=Lax` است و مرورگر آن
  // را به POST بین‌سایتی نمی‌چسباند، ولی وابستگی به رفتار مرورگر به‌تنهایی
  // کافی نیست (اشیای آینده، `SameSite=None`ِ اشتباهی). این چک مستقل از
  // مرورگر است و درخواستِ تغییردهندهٔ بین‌سایتی را ۴۰۳ می‌کند.
  if (req.method !== "GET" && req.method !== "HEAD") {
    const origin = req.headers.get("origin");
    const fetchSite = req.headers.get("sec-fetch-site");
    const sameOrigin =
      fetchSite === "same-origin" ||
      fetchSite === "none" ||
      (origin !== null && origin === req.nextUrl.origin);
    if (!sameOrigin) {
      return NextResponse.json({ message: "درخواست از مبدأ نامعتبر." }, { status: 403 });
    }
  }

  // SEC-5 — سقفِ اندازهٔ بدنه. پیش از این هر دو مسیر (`arrayBuffer` برای
  // multipart و `text` برای JSON) بدنه را بی‌سقف در حافظه می‌خواندند؛ یک
  // کاربرِ احراز‌هویت‌شده می‌توانست پروسهٔ standalone را OOM کند. سقف‌ها
  // عیناً با بک‌اندِnginx هم‌راستااند (۲۰M برای رسانه/zip، ۲M برای JSON).
  const declaredLength = Number(req.headers.get("content-length") ?? "0");
  const limit = contentType.includes("multipart/form-data") ? MAX_MULTIPART_BYTES : MAX_JSON_BYTES;
  if (Number.isFinite(declaredLength) && declaredLength > limit) {
    return NextResponse.json({ message: "بدنهٔ درخواست بیش از حد بزرگ است." }, { status: 413 });
  }

  // SEC-3/S-04 — انتقالِ نشانیِ واقعیِ کاربر: دیگر هدرِ مرورگر عیناً کپی
  // نمی‌شود. `clientIpHeaders` در نبودِ پروکزیِ معتبر هیچ هدر IP نفرستد و
  // بک‌اند آدرسِ همتای اتصال (غیرقابلِ جعل) را می‌بیند. تا TODAY، جعلِ
  // `X-Forwarded-For` هم `admin.ip` را دور می‌زد و هم `throttle` ورود را.
  const proxyHeaders: Record<string, string> = { ...clientIpHeaders(req) };
  const userAgent = req.headers.get("user-agent");
  if (userAgent) proxyHeaders["User-Agent"] = userAgent;

  // آپلود ZIP (قالب/پلاگین): بدنه multipart باید دست‌نخورده با همان boundary فوروارد شود.
  if (contentType.includes("multipart/form-data")) {
    const buf = await req.arrayBuffer().catch(() => null);
    const up = await fetch(target, {
      method: req.method,
      headers: { "Content-Type": contentType, Accept: "application/json", Authorization: `Bearer ${token}`, ...proxyHeaders },
      body: buf ? Buffer.from(buf) : undefined,
      cache: "no-store",
    });
    const json = await up.json().catch(() => ({}));
    return NextResponse.json(json, { status: up.status });
  }

  const body = req.method === "GET" || req.method === "HEAD" ? undefined : await req.text().catch(() => undefined);
  const up = await fetch(target, {
    method: req.method,
    headers: { "Content-Type": "application/json", Accept: "application/json", Authorization: `Bearer ${token}`, ...proxyHeaders },
    body,
    cache: "no-store",
  });
  const json = await up.json().catch(() => ({}));
  return NextResponse.json(json, { status: up.status });
}

export async function GET(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  return proxy(req, (await ctx.params).path);
}
export async function POST(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  return proxy(req, (await ctx.params).path);
}
export async function PUT(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  return proxy(req, (await ctx.params).path);
}
export async function PATCH(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  return proxy(req, (await ctx.params).path);
}
export async function DELETE(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  return proxy(req, (await ctx.params).path);
}
