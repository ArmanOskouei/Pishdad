import { NextRequest, NextResponse } from "next/server";
import { setSessionCookie } from "@/lib/session-cookie";
import { serverApiBase } from "@/lib/server-api-base";
import { clientIpHeaders } from "@/lib/client-ip";

/**
 * SEC-3 — ورود.
 *
 * پیش از این، کل JSON بک‌اند (شامل `token`) به مرورگر برمی‌گرفت و کلاینت
 * همان توکن را برای `Authorization: Bearer` درخواست‌های بعدی استفاده می‌کرد
 * — یعنی هر XSS در پنل، توکنِ ۷روزه را می‌دزدید و کوکی httpOnly عملاً
 * بی‌اثر بود. حالا:
 *
 *   1. توکن **هرگز** از این سمت بیرون نمی‌رود؛ فقط در کوکی httpOnly می‌نشیند
 *      (همان پاسخ، Route Handler آن را ست می‌کند).
 *   2. بدنهٔ پاسخ **allowlist** است، نه حذفِ یک کلید: هر فیلدِ تازهٔ بک‌اند
 *      (`refresh_token`، `plain_text_token`، …) هم به‌طور پیش‌فرض بیرون نمی‌رود.
 *   3. IP کلاینت دیگر از هدرِ جعل‌پذیر خوانده نمی‌شود (بخشِ امنیتیِ جدا).
 *
 * `challenge` عمداً در بدنه می‌ماند: توکنِ نشست نیست (یک‌بارمصرف، ۱۰ دقیقه،
 * فقط `2fa/verify` را مجاز می‌کند و هنوز TOTP لازم دارد).
 */

type LoginBody = {
  two_factor_required?: boolean;
  requires_2fa_setup?: boolean;
  two_factor_setup_required?: boolean;
  challenge?: string;
  message?: string;
  user?: unknown;
};

export async function POST(req: NextRequest) {
  const body = await req.json().catch(() => ({}));
  const headers: Record<string, string> = { "Content-Type": "application/json", Accept: "application/json" };
  // SEC-3/S-04 — IP واقعی از هدرِ مرورگر خوانده نمی‌شود. `clientIpHeaders`
  // در نبودِ پروکزیِ معتبر، هیچ هدر IP نفرستد و بک‌اند آدرسِ همتای اتصال
  // (غیرقابلِ جعل) را می‌بیند. تا TODAY، جعلِ `X-Forwarded-For` هم
  // `admin.ip` را دور می‌زد و هم `throttle:auth-login-ip` را خنثی می‌کرد.
  Object.assign(headers, clientIpHeaders(req));
  const up = await fetch(`${serverApiBase()}/v1/auth/login`, {
    method: "POST", headers, body: JSON.stringify(body),
  });
  const json = (await up.json().catch(() => ({}))) as LoginBody;
  if (!up.ok) return NextResponse.json(json, { status: up.status });

  const res = NextResponse.json({
    two_factor_required: json.two_factor_required === true,
    requires_2fa_setup: json.requires_2fa_setup === true,
    two_factor_setup_required: json.two_factor_setup_required === true,
    challenge: json.challenge,
    message: json.message,
    user: json.user,
  }, { status: 200 });

  // ⚠️ هرگز `Set-Cookie` آپ‌استریم را pass-through نکن: بک‌اند خودش هم کوکی
  // می‌گذارد و روی دامنهٔ خودش است. ما فقط توکنِ بدنه را در کوکیِ خودمان
  // می‌نشانی‌م‌.
  const token = (json as { token?: string }).token;
  if (token && json.two_factor_required !== true) {
    setSessionCookie(res, token);
  }
  return res;
}
