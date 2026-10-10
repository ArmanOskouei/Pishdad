import { NextRequest, NextResponse } from "next/server";
import { serverApiBase } from "@/lib/server-api-base";
import { setSessionCookie } from "@/lib/session-cookie";
import { clientIpHeaders } from "@/lib/client-ip";

/**
 * SEC-3 — تأیید مرحلهٔ دوم (2FA).
 *
 * مثل `login`: توکن در کوکی httpOnly می‌نشیند و به بدنه نمی‌رود. پیش از این
 * این فایل **بدون `secure`** کوکی می‌ساخت — یعنی نشستِ تازه‌صادرشده روی
 * HTTPS قابل downgrade بود. هِلپرِ مشترک آن را هم one-point کرده.
 */

type VerifyBody = {
  token?: string;
  two_factor_required?: boolean;
  requires_2fa_setup?: boolean;
  two_factor_setup_required?: boolean;
  message?: string;
  user?: unknown;
};

export async function POST(req: NextRequest) {
  const body = await req.json().catch(() => ({}));
  const headers: Record<string, string> = { "Content-Type": "application/json", Accept: "application/json" };
  Object.assign(headers, clientIpHeaders(req));
  const up = await fetch(`${serverApiBase()}/v1/auth/2fa/verify`, {
    method: "POST", headers, body: JSON.stringify(body),
  });
  const json = (await up.json().catch(() => ({}))) as VerifyBody;
  if (!up.ok) return NextResponse.json(json, { status: up.status });

  const res = NextResponse.json({
    two_factor_required: json.two_factor_required === true,
    requires_2fa_setup: json.requires_2fa_setup === true,
    two_factor_setup_required: json.two_factor_setup_required === true,
    message: json.message,
    user: json.user,
  }, { status: 200 });

  const token = json.token;
  if (token) setSessionCookie(res, token);
  return res;
}
