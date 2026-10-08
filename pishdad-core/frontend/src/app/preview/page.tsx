import type { Metadata } from "next";
import Link from "next/link";
import { fetchHomepage, fetchSiteChrome } from "@/lib/site";
import { BlockRenderer } from "@/components/site/BlockRenderer";
import { SiteFooter, SiteHeader, themeVars } from "@/components/site/Chrome";

/**
 * F4.1.F — پیش‌نمایشِ سراسریِ سایت، **بیرون از پوستهٔ پنل**.
 *
 * ## ⭐ چرا مسیرِ جداست و نه modal
 *
 * یک modal داخل `Shell` رندر می‌شد، پس `<html>` همان `data-*` و همان `:root`ِ
 * پنل را داشت و کلِ `env` رنگ از آن ارث می‌برد. آن‌وقت پیش‌نمایش دقیقاً همان چیزی
 * را نشان می‌داد که این تسک می‌خواست **ثابت کند اشتباه است**: سایتی که توکن‌های
 * پنل را می‌پوشد. یعنی ابزارِ دیدنِ باگ، خودش حاملِ باگ بود.
 *
 * `/preview` در گروهِ مسیرِ `(client)` نیست، پس `Shell` ندارد، `ThemeProvider`
 * دارد ولی `data-direction`/`data-accent` را چیزی روی این صفحه عوض نمی‌کند و
 * `globals.css` توکن‌های پنل را فقط از `:root` می‌خواند — که scopeِ `.site`
 * دیگر به آن‌ها وابسته نیست.
 *
 * ## محتوا از کجا می‌آید
 *
 * همان داده‌ای که سایتِ واقعی می‌بیند (`/v1/site/chrome` + صفحهٔ خانه). یعنی
 * پیش‌نمایش دروغ نمی‌گوید: اگر رنگ در پیش‌نمایش درست است ولی در سایت نه، مشکل
 * از کش است نه از رندر.
 */
export const revalidate = 0;
export const dynamic = "force-dynamic";

export const metadata: Metadata = {
  title: "پیش‌نمایش سایت",
  // پیش‌نمایش هرگز نباید ایندکس شود: نسخهٔ نیمه‌تنظیم‌شدهٔ سایت در نتایج
  // گوگل ظاهر شدن، بدترین «باگِ بی‌ضرر» نیست — بدنه‌ای است که گوگل به کاربر
  // نشان می‌دهد و بعداً پس‌گرفته نمی‌شود.
  robots: { index: false, follow: false, nocache: true },
};

export default async function PreviewPage() {
  const [page, chrome] = await Promise.all([
    fetchHomepage().catch(() => null),
    fetchSiteChrome().catch(() => null),
  ]);

  /**
   * ⚠️ TODO(F4.1.F): `/v1/site/chrome` حالت (`light|dark`) را منتشر **نمی‌کند**،
   * فقط `theme.globals` را می‌دهد. پس `data-mode` فعلاً `undefined` است و scope
   * پایه (تاریک) را می‌گیرد — یعنی رفتارِ امروزِ سایت بدون رگرسیون.
   *
   * به محض اینکه بک‌اند `theme.mode` را اضافه کند، همین یک خط کار می‌کند و نیازی
   * به دست‌زدن به CSS نیست. عمداً الان چیزی **حدس** نمی‌زنیم: `selection.mode`
   * سروری است و حدسِ سمتِ فرانت یعنی پیش‌نمایش چیزی نشان می‌دهد که سایتِ
   * واقعی نشان نمی‌دهد — دقیقاً همان دروغی که این صفحه برای جلوگیری از آن است.
   */
  const mode = typeof chrome?.mode === "string" ? chrome.mode : undefined;

  return (
    // `style` فقط `--theme-*` را می‌گذارد — هیچ توکنِ پنلی.
    <div className="site" style={themeVars(chrome)} data-mode={mode}>
      <div className="preview-bar">
        <span>
          پیش‌نمایش {mode ? <>— حالت <b>{mode}</b></> : <>— بدون پوستهٔ پنل</>}
        </span>
        <div style={{ flex: 1 }} />
        <Link href="/admin/themes">ویرایش در پنل</Link>
        <Link href="/">سایتِ واقعی</Link>
      </div>

      <SiteHeader chrome={chrome} />

      {page ? (
        <div className="site-body">
          <main>
            <article>
              <BlockRenderer blocks={page.blocks ?? []} />
            </article>
          </main>
        </div>
      ) : (
        <main style={{ minBlockSize: "40vh", display: "grid", placeItems: "center", padding: 24 }}>
          <div className="card card-pad" style={{ maxInlineSize: 520, inlineSize: "100%", textAlign: "center" }}>
            <h1 style={{ fontSize: 19, marginBlock: "8px 4px" }}>صفحه‌ای برای پیش‌نمایش نیست</h1>
            <p style={{ fontSize: 14, color: "var(--text-muted)" }}>
              هنوز صفحهٔ خانه‌ای منتشر نشده. رنگ و توکن‌ها بالا اعمال شده‌اند — برای
              دیدن محتوا یک صفحه منتشر کنید.
            </p>
          </div>
        </main>
      )}

      <SiteFooter chrome={chrome} />
    </div>
  );
}
