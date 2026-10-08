import { NextRequest, NextResponse } from "next/server";

/**
 * تصمیم کوکی/توکن (مستند در README):
 * بک‌اند در tokenResponse هم JSON {token} و هم کوکی httpOnly `auth_token` می‌دهد،
 * اما آن کوکی روی دامین بک‌اند (8080:) ست می‌شود و مرورگر آن را برای فرانت (3000:)
 * پس نمی‌فرستد. پس فرانت خودش کوکی httpOnly می‌سازد: این Route Handler توکن بک‌اند
 * را می‌گیرد و با همان نام روی دامین فرانت ست می‌کند. JS هرگز توکن را نمی‌بیند.
 */

const UPSTREAM = process.env.INTERNAL_API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8080/api";

export async function POST(req: NextRequest) {
  const body = await req.json().catch(() => ({}));
  const headers: Record<string, string> = { "Content-Type": "application/json", Accept: "application/json" };
  const userAgent = req.headers.get("user-agent");
  const forwardedFor = req.headers.get("x-forwarded-for") ?? req.headers.get("x-real-ip");
  if (userAgent) headers["User-Agent"] = userAgent;
  if (forwardedFor) {
    headers["X-Forwarded-For"] = forwardedFor;
    headers["X-Real-IP"] = forwardedFor.split(",")[0].trim();
  }
  const up = await fetch(`${UPSTREAM}/v1/auth/login`, {
    method: "POST", headers, body: JSON.stringify(body),
  });
  const json = await up.json().catch(() => ({}));
  if (!up.ok) return NextResponse.json(json, { status: up.status });
  const res = NextResponse.json(json, { status: 200 });
  const token = json?.token;
  if (token && json?.two_factor_required !== true) {
    res.cookies.set("auth_token", token, { httpOnly: true, sameSite: "lax", path: "/", maxAge: 60 * 60 * 24 * 7 });
  }
  return res;
}
