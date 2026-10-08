import Link from "next/link";
import { serverOne } from "@/lib/server-api";
import type { ThemeItem } from "@/lib/domain";
import { Alert } from "@/components/ui/primitives";
import { ThemesClient } from "./ThemesClient";
import { SiteThemeEditor } from "./site-theme/SiteThemeEditor";

/**
 * F4.1.F — صفحهٔ قالبِ سایت، با **دو تب**.
 *
 * ## ⭐ چرا دو تب و نه یک صفحه
 *
 * تا قبل، این صفحه فقط بستهٔ ZIP بود: آپلود، امضا، بازبینیِ مرکزی. حالا که
 * `F4.1.B` اندپوینت‌های `site-theme/*` را دارد، یک چیزِ **کاملاً متفاوت** هم
 * اینجاست: پوسته و رنگ‌بندیِ درون‌ساخت.
 *
 * این‌ها دو چیز نیستند و قاطی‌کردنشان بد است:
 *  - ZIP یک **بستهٔ کدِ اجرایی** است که باید امضا و بازبینی شود و روی فایل‌سیستم
 *    کار می‌کند (`themes`).
 *  - `site-theme/*` فقط **توکن** می‌نویسد (`site_themes`/`site_theme_presets`) و
 *    `Q5` صریحاً ممنوع کرده که مسیر ZIP بتواند رنگِ درون‌ساخت را عوض کند.
 *
 * یک فهرستِ آمیخته یعنی کاربر نمی‌فهمد کدام دکمه «کدِ اجرا می‌کند» است — و این
 * دقیقاً تفاوتی است که باید بداند.
 *
 * تب با `?tab=` است نه state: لینک‌محور یعنی آدرسِ قابلِ اشتراک/بازگشت و بدون
 * نیاز به کلاینت‌کامپوننتِ تب.
 */
export default async function ThemesPage({
  searchParams,
}: {
  searchParams: Promise<{ tab?: string }>;
}) {
  const sp = await searchParams;
  const tab = sp.tab === "zip" ? "zip" : "skin";

  // فقط وقتی تبِ ZIP باز است بسته‌ها خوانده می‌شوند — تبِ پوسته به آن‌ها نیازی
  // ندارد و `ThemesClient` کاملشان را به کلاینت می‌دهد.
  let initial: ThemeItem[] = [];
  let error: string | null = null;
  if (tab === "zip") {
    try {
      const data = await serverOne<ThemeItem[] | { data: ThemeItem[] }>(`/v1/admin/themes`);
      initial = Array.isArray(data) ? data : (data.data ?? []);
    } catch (e) {
      error = e instanceof Error ? e.message : "خطا در بارگذاری قالب‌ها.";
    }
  }

  return (
    <div>
      <div className="page-head">
        <div>
          <h1>قالب سایت</h1>
          <p>ظاهر سایت عمومی — پوسته و رنگ در یک تب، بستهٔ ZIP در تب دیگر</p>
        </div>
      </div>

      <nav className="tabs" aria-label="نوع قالب">
        <Link href="/admin/themes" className={tab === "skin" ? "on" : ""} aria-current={tab === "skin" ? "page" : undefined}>
          پوسته و رنگ
        </Link>
        <Link href="/admin/themes?tab=zip" className={tab === "zip" ? "on" : ""} aria-current={tab === "zip" ? "page" : undefined}>
          بستهٔ ZIP
        </Link>
      </nav>

      {/*
       * این یادآوری عمداً فقط در تبِ ZIP است: در تبِ پوسته، انتخاب بین
       * «پوستهٔ سایت» و «قالب پنل» معنا ندارد چون این صفحه فقط یکی را
       * می‌دیزد — ولی در گالری ZIP هر دو کنار هم‌اند و قاطی‌شدنشان
       * «فعال شد ولی چیزی عوض نشد» می‌سازد.
       */}
      {tab === "zip" ? (
        error ? (
          <Alert tone="red">{error}</Alert>
        ) : (
          <ThemesClient initial={initial} />
        )
      ) : (
        <>
          <Alert tone="blue">
            این تب ظاهرِ <b>سایتِ عمومی</b> را عوض می‌کند — نه ظاهرِ همین پنل.
            ظاهر پنل از <Link href="/admin/appearance">قالب پنل</Link> تنظیم می‌شود. این
            دو از هم جدا هستند: تغییر اینجا رنگِ پنل شما را تکان نمی‌دهد.
          </Alert>
          <div style={{ marginBlockStart: 14 }}>
            <SiteThemeEditor />
          </div>
        </>
      )}
    </div>
  );
}
