import { NextRequest, NextResponse } from "next/server";

/** محافظت روت‌های admin/central (F1): بدون کوکی auth_token → ریدایرکت به login. */
export function proxy(req: NextRequest) {
  const token = req.cookies.get("auth_token")?.value;
  if (!token) {
    const url = req.nextUrl.clone();
    url.pathname = "/login";
    return NextResponse.redirect(url);
  }
  return NextResponse.next();
}

export const config = { matcher: ["/admin/:path*", "/central/:path*"] };
