/**
 * WF-H1 — سازندهٔ متن robots.txt.
 *
 * خالص و بدون وابستگی تا هم روت (`app/robots.txt/route.ts`) و هم تستِ واحد
 * بتوانند بدون resolver/alias استفاده کنند. اولویت:
 *   1. `robots_index === false` ⇒ کل سایت مسدود (سوئیچِ ایمنیِ سراسری).
 *   2. متن سفارشیِ ذخیره‌شده ⇒ همان (`robots_txt`).
 *   3. پیش‌فرضِ حساس: اجازه به همه + لینک sitemap و llms.txt.
 */

export type RobotsInput = {
  robotsIndex?: boolean | null;
  robotsTxt?: string | null;
  base: string;
};

export function defaultRobotsTxt(base: string): string {
  const clean = base.replace(/\/+$/, "");
  return `User-agent: *\nAllow: /\n\nSitemap: ${clean}/sitemap.xml\n\n# خلاصه هوش مصنوعی: ${clean}/llms.txt\n`;
}

export function buildRobotsTxt({ robotsIndex, robotsTxt, base }: RobotsInput): string {
  if (robotsIndex === false) return "User-agent: *\nDisallow: /\n";
  const custom = (robotsTxt ?? "").trim();
  if (custom) return custom.endsWith("\n") ? custom : `${custom}\n`;
  return defaultRobotsTxt(base);
}
