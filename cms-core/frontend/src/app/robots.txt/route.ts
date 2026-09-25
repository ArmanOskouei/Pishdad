import { NextRequest, NextResponse } from "next/server";
import { fetchSiteChrome, siteBaseUrl } from "@/lib/site";

/** robots.txt داینامیک: احترام به robots_index + معرفی sitemap و llms.txt. */
export const revalidate = 300;

export async function GET(req: NextRequest): Promise<NextResponse> {
  const chrome = await fetchSiteChrome().catch(() => null);
  const base =
    siteBaseUrl(chrome) ?? req.nextUrl.origin.replace(/\/+$/, "");
  const blocked = (chrome?.robots_index ?? true) === false;
  const body = blocked
    ? `User-agent: *\nDisallow: /\n`
    : `User-agent: *\nAllow: /\n\nSitemap: ${base}/sitemap.xml\n\n# خلاصه هوش مصنوعی: ${base}/llms.txt\n`;
  return new NextResponse(body, {
    headers: { "Content-Type": "text/plain; charset=utf-8" },
  });
}
