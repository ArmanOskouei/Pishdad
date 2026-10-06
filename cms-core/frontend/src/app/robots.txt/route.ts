import { NextRequest, NextResponse } from "next/server";
import { fetchSiteChrome, siteBaseUrl } from "@/lib/site";
import { buildRobotsTxt } from "@/lib/robots";

/** robots.txt داینامیک (WF-H1): متنِ سفارشیِ ذخیره‌شده، وگرنه پیش‌فرض + sitemap. */
export const revalidate = 300;

export async function GET(req: NextRequest): Promise<NextResponse> {
  const chrome = await fetchSiteChrome().catch(() => null);
  const base =
    siteBaseUrl(chrome) ?? req.nextUrl.origin.replace(/\/+$/, "");
  const body = buildRobotsTxt({
    robotsIndex: chrome?.robots_index,
    robotsTxt: chrome?.robots_txt,
    base,
  });
  return new NextResponse(body, {
    headers: { "Content-Type": "text/plain; charset=utf-8" },
  });
}
