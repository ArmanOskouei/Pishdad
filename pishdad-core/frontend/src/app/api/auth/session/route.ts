import { NextRequest, NextResponse } from "next/server";
import { serverApiBase } from "@/lib/server-api-base";

/** ست‌کردن دستی کوکی سشن (بعد از login موفق سمت کلاینت) + logout. */
export async function POST(req: NextRequest) {
  const { token } = await req.json().catch(() => ({}));
  if (!token) return NextResponse.json({ message: "توکن الزامی است." }, { status: 400 });
  // SEC-4 — `secure` روی HTTPS فعال می‌شود؛ روی HTTPِ خالصِ محلی لازم نیست.
  const cookieOpts = { httpOnly: true, sameSite: "lax" as const, path: "/", maxAge: 60 * 60 * 24 * 7, secure: process.env.NODE_ENV === "production" };
  const res = NextResponse.json({ ok: true });
  res.cookies.set("auth_token", token, cookieOpts);
  return res;
}

export async function DELETE(req: NextRequest) {
  const token = req.cookies.get("auth_token")?.value;
  if (token) {
    await fetch(`${serverApiBase()}/v1/auth/logout`, { method: "POST", headers: { Accept: "application/json", Authorization: `Bearer ${token}` } }).catch(() => undefined);
  }
  const res = NextResponse.json({ ok: true });
  res.cookies.delete("auth_token");
  return res;
}
