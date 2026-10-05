import { NextRequest, NextResponse } from "next/server";

/**
 * ECO6 — دانلود ZIP فروشگاه از راه پروکسی احرازهویت‌شده.
 *
 * چرا route مستقل و نه پروکسی عمومی `[...path]`: آن پروکسی همیشه بدنه را
 * JSON می‌خواند (`up.json()`)، چون برای APIها نوشته شده. ZIP یک بدنهٔ باینری
 * است؛ از آن مسیر، بایت‌ها دور ریخته می‌شوند و کاربر یک فایل خراب می‌گیرد.
 *
 * این route بایت‌ها را دست‌نخورده stream می‌کند و هدرهای مهم (نوعِ محتوا و
 * نامِ فایل) را از upstream عبور می‌دهد. مسیر **صریح** است، نه catch-all، چون
 * هر چیز دیگری زیر `v1/market/catalog/*` باید از allowlist رد شود.
 */
const UPSTREAM =
  process.env.INTERNAL_API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8080/api";

export async function GET(req: NextRequest, ctx: { params: Promise<{ pluginId: string }> }) {
  const token = req.cookies.get("auth_token")?.value;
  if (!token) return NextResponse.json({ message: "نشست منقضی شد — دوباره وارد شوید." }, { status: 401 });

  const { pluginId } = await ctx.params;
  // شناسه باید عدد باشد؛ وگرنه همان مسیرِ عمومیِ upstream را صدا می‌زنیم.
  if (!/^\d+$/.test(pluginId)) {
    return NextResponse.json({ message: "شناسهٔ افزونه نامعتبر است." }, { status: 400 });
  }

  const up = await fetch(`${UPSTREAM}/v1/market/catalog/${pluginId}/download`, {
    headers: { Accept: "application/zip, application/json", Authorization: `Bearer ${token}` },
    cache: "no-store",
  });

  if (!up.ok) {
    const json = await up.json().catch(() => ({}));
    return NextResponse.json(json, { status: up.status });
  }

  return new NextResponse(up.body, {
    status: 200,
    headers: {
      "Content-Type": up.headers.get("content-type") ?? "application/zip",
      "Content-Disposition":
        up.headers.get("content-disposition") ?? `attachment; filename="plugin-${pluginId}.zip"`,
      "X-Delivered-Checksum": up.headers.get("x-delivered-checksum") ?? "",
      "Cache-Control": "no-store",
    },
  });
}
