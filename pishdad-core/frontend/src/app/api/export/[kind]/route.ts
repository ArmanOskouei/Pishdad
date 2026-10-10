import { NextRequest, NextResponse } from "next/server";
import { serverApiBase } from "@/lib/server-api-base";

/**
 * WF-C6 — دانلود برون‌بری محتوا از راه پروکسی احرازهویت‌شده.
 *
 * چرا route مستقل و نه پروکسی عمومی `[...path]`: آن پروکسی همیشه بدنه را
 * `up.json()` می‌خواند؛ بدنهٔ XML (WXR) خراب می‌شد. این route بایت‌ها را
 * دست‌نخورده stream می‌کند و هدرهای نام/نوع فایل را از upstream عبور می‌دهد.
 */

const KINDS: Record<string, string> = {
  json: "application/json; charset=utf-8",
  wxr: "application/xml; charset=UTF-8",
};

export async function GET(req: NextRequest, ctx: { params: Promise<{ kind: string }> }) {
  const token = req.cookies.get("auth_token")?.value;
  if (!token) return NextResponse.json({ message: "نشست منقضی شد — دوباره وارد شوید." }, { status: 401 });

  const { kind } = await ctx.params;
  if (!(kind in KINDS)) {
    return NextResponse.json({ message: "نوع خروجی نامعتبر است." }, { status: 400 });
  }

  const up = await fetch(`${serverApiBase()}/v1/admin/content/export/${kind}`, {
    headers: { Accept: `${KINDS[kind]}, */*;q=0.8`, Authorization: `Bearer ${token}` },
    cache: "no-store",
  });

  if (!up.ok) {
    const json = await up.json().catch(() => ({}));
    return NextResponse.json(json, { status: up.status });
  }

  return new NextResponse(up.body, {
    status: 200,
    headers: {
      "Content-Type": up.headers.get("content-type") ?? KINDS[kind],
      "Content-Disposition":
        up.headers.get("content-disposition") ??
        `attachment; filename="content-export.${kind === "wxr" ? "xml" : "json"}"`,
      "Cache-Control": "no-store",
    },
  });
}
