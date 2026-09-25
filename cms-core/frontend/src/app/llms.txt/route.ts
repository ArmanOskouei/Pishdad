import { NextRequest, NextResponse } from "next/server";
import { fetchSiteChrome, fetchSitePages, siteBaseUrl } from "@/lib/site";

/**
 * llms.txt — خلاصه خودکار سایت + صفحات منتشرشده برای موتورهای هوش مصنوعی.
 * استاندارد پیشنهادی https://llmstxt.org : تیتر + خلاصه + تماس + فهرست لینک‌ها.
 */
export const revalidate = 300;

export async function GET(req: NextRequest): Promise<NextResponse> {
  const [chrome, pages] = await Promise.all([
    fetchSiteChrome().catch(() => null),
    fetchSitePages().catch(() => []),
  ]);
  const base =
    siteBaseUrl(chrome) ?? req.nextUrl.origin.replace(/\/+$/, "");
  const title = chrome?.title ?? "وب‌سایت";
  const summary =
    chrome?.ai_summary?.trim() || chrome?.description?.trim() || "";
  const lines: string[] = [`# ${title}`, ""];
  if (summary) lines.push(`> ${summary}`, "");
  if (base) lines.push(`نشانی: ${base}`, "");
  const contact: string[] = [
    chrome?.phone ? `تلفن: ${chrome.phone}` : null,
    chrome?.email ? `ایمیل: ${chrome.email}` : null,
    chrome?.address ? `نشانی: ${chrome.address}` : null,
  ].filter((x): x is string => typeof x === "string");
  if (contact.length > 0) lines.push(`## تماس`, "", ...contact, "");
  lines.push(`## صفحات`, "");
  if (pages.length === 0) {
    lines.push("هنوز صفحه منتشرشده‌ای نیست.");
  } else {
    for (const p of pages) {
      lines.push(`- [${p.title}](${base}/${p.slug})`);
    }
  }
  lines.push("");
  return new NextResponse(lines.join("\n"), {
    headers: { "Content-Type": "text/plain; charset=utf-8" },
  });
}
