import { NextRequest, NextResponse } from "next/server";
import { buildSiteFeedXml } from "@/lib/site";
import { isPublicLocale } from "@/lib/i18n/public";

/**
 * WF-C7/WF-M19 — فید RSS 2.0 برای هر زبان، از **همهٔ** محتوای منتشرشده (پیش از
 * M19 فقط نوشته‌های بلاگ بود).
 *
 * XML در فرانت از روی دادهٔ عمومیِ `site/feed` ساخته می‌شود تا یک فرانسازندهٔ
 * مشترک بین این مسیر و `/feed.xml` داشته باشیم؛ در نبودِ بک‌اند یک فیدِ خالیِ
 * معتبر برمی‌گردد (هرگز ۵۰۰ ندهیم، چون فید نباید سایت را بشکند).
 */
export const revalidate = 300;

type Ctx = { params: Promise<{ locale: string }> };

const EMPTY_FEED =
  '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Blog</title></channel></rss>';

export async function GET(req: NextRequest, { params }: Ctx): Promise<NextResponse> {
  const { locale } = await params;
  const xml = await buildSiteFeedXml({
    locale: isPublicLocale(locale) ? locale : "fa",
    origin: req.nextUrl.origin,
    kind: "localized",
  });

  return new NextResponse(xml ?? EMPTY_FEED, {
    status: 200,
    headers: {
      "Content-Type": "application/rss+xml; charset=utf-8",
      "Cache-Control": xml ? "public, max-age=300, s-maxage=300" : "no-store",
    },
  });
}