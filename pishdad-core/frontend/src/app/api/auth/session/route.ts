import { NextRequest, NextResponse } from "next/server";
import { serverApiBase } from "@/lib/server-api-base";

/**
 * SEC-3 — نشست.
 *
 * `POST` **حذف شد**: قبلاً توکن را از **بدنهٔ کلاینت** می‌گرفت و کوکی
 * می‌ساخت. یعنی هر کسی که یک توکنِ لو رفته داشته باشد (لاگ، HAR، تاریخچهٔ
 * DevTools) می‌توانست آن را در مرورگرِ قربانی تزریق کند. کوکی حالا فقط
 * توسط خودِ Route Handlerهای `login`/`verify-2fa` ست می‌شود.
 *
 * `410 Gone` برمی‌گرداند، نه `405`: باندلِ کهنهٔ کش‌شده پیامِ قابل‌فهم
 * می‌گیرد و کاربر را به ورودِ دوباره می‌فرستد، به‌جای خطای خامِ متد.
 */

const GONE = { message: "ست‌کردن کوکی از سمت کلاینت دیگر پشتیبانی نمی‌شود — دوباره وارد شوید." };

export async function POST() {
  return NextResponse.json(GONE, { status: 410 });
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
