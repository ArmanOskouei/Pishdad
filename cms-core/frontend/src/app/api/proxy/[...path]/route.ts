import { NextRequest, NextResponse } from "next/server";
import { checkPath } from "@/lib/proxy-allowlist";

/** پروکسی احرازهویت‌شده: /api/proxy/v1/admin/... → بک‌اند با Bearer از کوکی httpOnly. */
const UPSTREAM = process.env.INTERNAL_API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8080/api";

async function proxy(req: NextRequest, path: string[]) {
  const token = req.cookies.get("auth_token")?.value;
  if (!token) return NextResponse.json({ message: "نشست منقضی شد — دوباره وارد شوید." }, { status: 401 });

  // مسیر کاربر هرگز مستقیم به `UPSTREAM` نمی‌رود؛ بدون این allowlist هر کسی که
  // لاگین کرده بود می‌توانست `internal/heartbeat` (بدون auth) را صدا بزند.
  const decision = checkPath(path);
  if (!decision.ok) return NextResponse.json({ message: decision.reason }, { status: 403 });

  const target = `${UPSTREAM}/${decision.path}${req.nextUrl.search}`;
  const contentType = req.headers.get("content-type") ?? "";

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
