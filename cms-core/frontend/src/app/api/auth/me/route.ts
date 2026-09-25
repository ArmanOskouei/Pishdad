import { NextRequest, NextResponse } from "next/server";

const UPSTREAM = process.env.INTERNAL_API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8080/api";

/** GET /api/auth/me — سشن از کوکی httpOnly + پروفایل واقعی از بک‌اند (ProfileController@show). */
export async function GET(req: NextRequest) {
  const token = req.cookies.get("auth_token")?.value;
  if (!token) return NextResponse.json({ user: null }, { status: 401 });
  const up = await fetch(`${UPSTREAM}/v1/admin/profile`, {
    headers: { Accept: "application/json", Authorization: `Bearer ${token}` }, cache: "no-store",
  }).catch(() => null);
  if (!up || !up.ok) return NextResponse.json({ user: { token: true } }, { status: 200 });
  const json = await up.json().catch(() => ({}));
  return NextResponse.json({ user: json?.data ?? json }, { status: 200 });
}
