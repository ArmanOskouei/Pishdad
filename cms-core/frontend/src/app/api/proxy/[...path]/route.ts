import { NextRequest, NextResponse } from "next/server";

/** پروکسی احرازهویت‌شده: /api/proxy/v1/admin/... → بک‌اند با Bearer از کوکی httpOnly. */
const UPSTREAM = process.env.INTERNAL_API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8080/api";

async function proxy(req: NextRequest, path: string[]) {
  const token = req.cookies.get("auth_token")?.value;
  if (!token) return NextResponse.json({ message: "نشست منقضی شد — دوباره وارد شوید." }, { status: 401 });
  const target = `${UPSTREAM}/${path.join("/")}${req.nextUrl.search}`;
  const contentType = req.headers.get("content-type") ?? "";

  // آپلود ZIP (قالب/پلاگین): بدنه multipart باید دست‌نخورده با همان boundary فوروارد شود.
  if (contentType.includes("multipart/form-data")) {
    const buf = await req.arrayBuffer().catch(() => null);
    const up = await fetch(target, {
      method: req.method,
      headers: { "Content-Type": contentType, Accept: "application/json", Authorization: `Bearer ${token}` },
      body: buf ? Buffer.from(buf) : undefined,
      cache: "no-store",
    });
    const json = await up.json().catch(() => ({}));
    return NextResponse.json(json, { status: up.status });
  }

  const body = req.method === "GET" || req.method === "HEAD" ? undefined : await req.text().catch(() => undefined);
  const up = await fetch(target, {
    method: req.method,
    headers: { "Content-Type": "application/json", Accept: "application/json", Authorization: `Bearer ${token}` },
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
