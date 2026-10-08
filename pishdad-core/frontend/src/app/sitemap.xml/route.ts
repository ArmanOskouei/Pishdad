import { NextRequest, NextResponse } from "next/server";
import { fetchSiteChrome, fetchSitePages, siteBaseUrl } from "@/lib/site";

/** sitemap.xml داینامیک: خانه + صفحات منتشرشده (lastmod از updated_at). */
export const revalidate = 300;

function esc(s: string): string {
  return s
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

export async function GET(req: NextRequest): Promise<NextResponse> {
  const [chrome, pages] = await Promise.all([
    fetchSiteChrome().catch(() => null),
    fetchSitePages().catch(() => []),
  ]);
  const base =
    siteBaseUrl(chrome) ?? req.nextUrl.origin.replace(/\/+$/, "");
  const urls = [
    `  <url><loc>${esc(base)}/</loc><changefreq>daily</changefreq><priority>1.0</priority></url>`,
    ...pages.map((p) => {
      const loc = `${base}/${p.slug}`;
      const lastmod = p.updated_at ?? p.published_at;
      return `  <url><loc>${esc(loc)}</loc>${
        lastmod ? `<lastmod>${esc(lastmod.slice(0, 10))}</lastmod>` : ""
      }<changefreq>weekly</changefreq><priority>0.8</priority></url>`;
    }),
  ];
  const xml =
    `<?xml version="1.0" encoding="UTF-8"?>\n` +
    `<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n` +
    urls.join("\n") +
    `\n</urlset>`;
  return new NextResponse(xml, {
    headers: { "Content-Type": "application/xml; charset=utf-8" },
  });
}
