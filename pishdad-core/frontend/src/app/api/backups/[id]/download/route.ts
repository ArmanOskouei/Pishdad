import { NextRequest, NextResponse } from "next/server";
import { serverApiBase } from "@/lib/server-api-base";

/**
 * WF-H20 — دانلود فایل پشتیبان از راه پروکسی احرازهویت‌شده.
 *
 * چرا route مستقل و نه پروکسی عمومی `[...path]`: آن پروکسی همیشه بدنه را
 * `up.json()` می‌خواند، پس بایت‌های dump دور ریخته می‌شد. اینجا بایت‌ها
 * دست‌نخورده stream می‌شوند و هدرهای نوع/نام فایل از upstream عبور می‌کنند.
 */

export async function GET(req: NextRequest, ctx: { params: Promise<{ id: string }> }) {
  const token = req.cookies.get("auth_token")?.value;
  if (!token) return NextResponse.json({ message: "نشست منقضی شد — دوباره وارد شوید." }, { status: 401 });

  const { id } = await ctx.params;
  // فقط نام فایلِ dump؛ هیچ مسیر یا جداکننده‌ای راه ندارد.
  if (!/^[A-Za-z0-9][A-Za-z0-9._-]*\.dump$/.test(id)) {
    return NextResponse.json({ message: "شناسهٔ پشتیبان نامعتبر است." }, { status: 400 });
  }

  const up = await fetch(`${serverApiBase()}/v1/admin/settings/backups/${encodeURIComponent(id)}/download`, {
    headers: { Accept: "application/octet-stream, application/json", Authorization: `Bearer ${token}` },
    cache: "no-store",
  });

  if (!up.ok) {
    const json = await up.json().catch(() => ({}));
    return NextResponse.json(json, { status: up.status });
  }

  return new NextResponse(up.body, {
    status: 200,
    headers: {
      "Content-Type": up.headers.get("content-type") ?? "application/octet-stream",
      "Content-Disposition": up.headers.get("content-disposition") ?? `attachment; filename="${id}"`,
      "Cache-Control": "no-store",
    },
  });
}
