import { NextRequest, NextResponse } from "next/server";

/**
 * WF-H10 — دانلود CSV پاسخ‌های فرم از راه پروکسی احرازهویت‌شده.
 *
 * route مستقل تا بدنهٔ CSV دست‌نخورده stream شود (پروکسی عمومی `[...path]`
 * همیشه بدنه را JSON می‌خواند و CSV را خراب می‌کند).
 */
const UPSTREAM =
  process.env.INTERNAL_API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8080/api";

export async function GET(req: NextRequest, ctx: { params: Promise<{ id: string }> }) {
  const token = req.cookies.get("auth_token")?.value;
  if (!token) return NextResponse.json({ message: "نشست منقضی شد — دوباره وارد شوید." }, { status: 401 });

  const { id } = await ctx.params;
  if (!/^\d+$/.test(id)) {
    return NextResponse.json({ message: "شناسهٔ فرم نامعتبر است." }, { status: 400 });
  }

  const up = await fetch(`${UPSTREAM}/v1/admin/forms/${id}/submissions.csv`, {
    headers: { Accept: "text/csv, */*;q=0.8", Authorization: `Bearer ${token}` },
    cache: "no-store",
  });

  if (!up.ok) {
    const json = await up.json().catch(() => ({}));
    return NextResponse.json(json, { status: up.status });
  }

  return new NextResponse(up.body, {
    status: 200,
    headers: {
      "Content-Type": up.headers.get("content-type") ?? "text/csv; charset=UTF-8",
      "Content-Disposition": up.headers.get("content-disposition") ?? `attachment; filename="form-${id}-submissions.csv"`,
      "Cache-Control": "no-store",
    },
  });
}
