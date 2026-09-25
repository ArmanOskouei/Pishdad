import { NextRequest, NextResponse } from "next/server";

/** ست‌کردن دستی کوکی سشن (بعد از login موفق سمت کلاینت) + logout. */
export async function POST(req: NextRequest) {
  const { token } = await req.json().catch(() => ({}));
  if (!token) return NextResponse.json({ message: "توکن الزامی است." }, { status: 400 });
  const res = NextResponse.json({ ok: true });
  res.cookies.set("auth_token", token, { httpOnly: true, sameSite: "lax", path: "/", maxAge: 60 * 60 * 24 * 7 });
  return res;
}

export async function DELETE(req: NextRequest) {
  const token = req.cookies.get("auth_token")?.value;
  const UPSTREAM = process.env.INTERNAL_API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8080/api";
  if (token) {
    await fetch(`${UPSTREAM}/v1/auth/logout`, { method: "POST", headers: { Accept: "application/json", Authorization: `Bearer ${token}` } }).catch(() => undefined);
  }
  const res = NextResponse.json({ ok: true });
  res.cookies.delete("auth_token");
  return res;
}
