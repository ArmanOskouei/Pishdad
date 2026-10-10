import { NextRequest, NextResponse } from "next/server";
import { checkPath } from "@/lib/proxy-allowlist";
import { serverApiBase } from "@/lib/server-api-base";

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

  // SEC-5 — سقفِ اندازهٔ بدنه. پیش از این هر دو مسیر (`arrayBuffer` برای
  // multipart و `text` برای JSON) بدنه را بی‌سقف در حافظه می‌خواندند؛ یک
  // کاربرِ احراز‌هویت‌شده می‌توانست پروسهٔ standalone را OOM کند. سقف‌ها
  // عیناً با بک‌اندِnginx هم‌راستااند (۲۰M برای رسانه/zip، ۲M برای JSON).
  const declaredLength = Number(req.headers.get("content-length") ?? "0");
  const limit = contentType.includes("multipart/form-data") ? MAX_MULTIPART_BYTES : MAX_JSON_BYTES;
  if (Number.isFinite(declaredLength) && declaredLength > limit) {
    return NextResponse.json({ message: "بدنهٔ درخواست بیش از حد بزرگ است." }, { status: 413 });
  }

  // WF-M7 — انتقالِ نشانیِ واقعیِ کاربر (مثل روتِ login) تا فهرستِ IP مجاز و
  // سقفِ تلاش per-IP روی IP پروکسی اعمال نشود. بدون این، بک‌اند فقط IP نودِ
  // فرانت را می‌بیند و allowlist عملاً بی‌اثر می‌شود.
  const forwardedFor = req.headers.get("x-forwarded-for") ?? req.headers.get("x-real-ip");
  const proxyHeaders: Record<string, string> = {};
  if (forwardedFor) {
    proxyHeaders["X-Forwarded-For"] = forwardedFor;
    proxyHeaders["X-Real-IP"] = forwardedFor.split(",")[0].trim();
  }
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
