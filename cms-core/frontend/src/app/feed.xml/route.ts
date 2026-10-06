import { NextRequest, NextResponse } from "next/server";
import { buildSiteFeedXml } from "@/lib/site";
import { DEFAULT_PUBLIC_LOCALE } from "@/lib/i18n/public";

/**
 * WF-M19 — `/feed.xml` سراسری: فید RSS از **همهٔ** محتوای منتشرشده، برای زبانِ پایه
 * (پیش از M19 فقط نوشته‌های بلاگ بود). مسیرِ زبان‌دارِ
 * `/[locale]/blog/feed.xml` همین فید را با زبانِ خودش می‌دهد.
 *
 * در نبودِ بک‌اند یک فیدِ خالیِ معتبر برمی‌گردد (هرگز ۵۰۰ ندهیم، چون فید نباید
 * سایت را بشکند) و آن‌وقت `no-store` می‌گیرد تا تلاشِ بعدی تازه باشد.
 */
export const revalidate = 300;

const EMPTY_FEED =
  '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Blog</title></channel></rss>';

export async function GET(req: NextRequest): Promise<NextResponse> {
  const xml = await buildSiteFeedXml({
    locale: DEFAULT_PUBLIC_LOCALE,
    origin: req.nextUrl.origin,
    kind: "root",
  });

  return new NextResponse(xml ?? EMPTY_FEED, {
    status: 200,
    headers: {
      "Content-Type": "application/rss+xml; charset=utf-8",
      "Cache-Control": xml ? "public, max-age=300, s-maxage=300" : "no-store",
    },
  });
}