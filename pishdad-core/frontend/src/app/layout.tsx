import type { Metadata } from "next";
import { headers } from "next/headers";
import { Vazirmatn } from "next/font/google";
import "./vazirmatn.css";
import "./globals.css";
import { ThemeProvider } from "@/lib/theme";
import { LanguageProvider } from "@/lib/i18n";
import { AuthProvider } from "@/lib/auth";
import { ToastProvider } from "@/components/ui/Toast";
import { isPublicLocale, publicDir, publicHtmlLang } from "@/lib/i18n/public";
import { SITE_LOCALE_HEADER } from "@/lib/public-routing";
import { publicConfigScript } from "@/lib/runtime-config";
import { effectivePublicConfig } from "@/lib/site-config";
import { fetchSiteChrome, siteFontPreloadEnabled } from "@/lib/site";

/**
 * فونت وزیرمتن: اول next/font/google؛ اگر آفلاین بود بیلد فونت فیل می‌شود.
 * fallback = فونت محلی از `vazirmatn.css` (public/fonts).
 * قانون: هرگز CDN خارجی برای فونت استفاده نشود — در نبود دسترسی به اینترنت
 * لود نمی‌شود و مرورگر به فونت جایگزین می‌افتد (عنوان هدر و جستجو ناخوانا می‌شد).
 */
const vazir = Vazirmatn({ subsets: ["arabic", "latin"], weight: ["300", "400", "500", "700"], display: "swap", variable: "--font-vazir" });

export const metadata: Metadata = {
  title: "پنل مدیریت CMS",
  description: "پنل مدیریت فارسی — Next.js 15",
  // F5.1/F5.2 — manifest با مسیرِ Route Handler. بدون این، «نصب روی صفحهٔ اصلی» اصلاً
  // پیشنهاد نمی‌شود (نه در iOS، نه در اندروید). یک manifest است و هر دو سطح را
  // می‌پوشاند — `start_url` ریشهٔ **سایتِ عمومی** است.
  manifest: "/manifest.webmanifest",
  // آیکونِ iOS از فایلِ Route Handler خوانده نمی‌شود؛ `apple-touch-icon` باید
  // لینکِ مستقیم باشد. (۵۱۲ است و iOS خودش کوچکش می‌کند.)
  appleWebApp: { capable: true, title: "پیشداد", statusBarStyle: "black-translucent" },
  icons: {
    icon: [
      { url: "/favicon.ico", sizes: "any" },
      { url: "/icons/icon-32.png", sizes: "32x32", type: "image/png" },
      { url: "/icons/icon-128.png", sizes: "128x128", type: "image/png" },
      { url: "/icons/icon-256.png", sizes: "256x256", type: "image/png" },
    ],
    apple: [{ url: "/icons/icon-512.png", sizes: "512x512", type: "image/png" }],
  },
};

/**
 * اسکریپت ضد FOUC: خواندن تم از localStorage و اعمال data-attributes قبل از رنگ.
 *
 * ⭐ ECO2 — روی سایتِ عمومی (`data-cms-surface="site"`) جهت/زبان را **دست نمی‌زند**:
 * آن‌ها سمت سرور از لوکالِ مسیر می‌آیند. اسکریپت تمِ پنل (localStorage) فقط برای
 * پوستهٔ پنل است؛ اجرای آن روی `/en` باعث می‌شد صفحهٔ انگلیسی پس از hydration
 * راست‌به‌چپ شود.
 */
const ANTI_FOUC = `(function(){try{var h=document.documentElement;var s=h.getAttribute("data-cms-surface");var t=JSON.parse(localStorage.getItem("cms-ui-f1")||"{}");var mode=t.mode||"dark";if(mode==="system")mode=matchMedia("(prefers-color-scheme: light)").matches?"light":"dark";h.dataset.direction=t.preset||"amaliyat";h.dataset.theme=mode;h.dataset.accent=t.accent||"indigo";h.dataset.radius=t.radius||"sharp";h.dataset.density=t.density||"compact";h.dataset.fontSize=t.font||"md";if(s!=="site"){h.dir=t.dir||"rtl";/* F4.4: زبان از همان جهت می‌آید — دو کانالِ جدا یعنی صفحه‌ای که راست‌به‌چپ است ولی انگلیسی خوانده می‌شود (یا برعکس). */h.lang=h.dir==="ltr"?"en":"fa";}}catch(e){}})();`;

export default async function RootLayout({ children }: { children: React.ReactNode }) {
  // ECO2 — زبانِ سرور-رندر از هدرِ میدل‌ور می‌آید، نه از پارامترِ مسیر (layout
  // به `params` دسترسی ندارد). میدل‌ور برای سایتِ عمومی `x-site-locale` را ست
  // می‌کند؛ پنل/API آن را ندارند و در نتیجه به پیش‌فرضِ fa/rtl می‌مانند.
  const h = await headers();
  const raw = h.get(SITE_LOCALE_HEADER);
  const siteLocale = isPublicLocale(raw) ? raw : null;

  // WF-M13 — preload فونت فقط برای سایتِ عمومی و فقط اگر در تنظیمات کارایی
  // روشن باشد. فونت همیشه محلی است (هرگز CDN خارجی) و `font-display: swap`
  // تضمین می‌کند خاموش‌کردن preload تجربه را نمی‌شکند.
  const siteChrome = siteLocale ? await fetchSiteChrome().catch(() => null) : null;
  const preloadFont = siteFontPreloadEnabled(siteChrome);

  return (
    <html
      lang={siteLocale ? publicHtmlLang(siteLocale) : "fa"}
      dir={siteLocale ? publicDir(siteLocale) : "rtl"}
      data-cms-surface={siteLocale ? "site" : "panel"}
      suppressHydrationWarning
    >
      <head>
        {/* مقدارهای عمومی در **زمانِ درخواست** اینجا نوشته می‌شوند، نه در
            زمانِ بیلد. دلیلش: یک باندلِ بیلدشده باید روی هر سروری، با هر
            نشانیِ API، «نصب» شود — نه اینکه برای هر نصب دوباره ساخته شود.
            مصرف‌کننده‌اش `lib/runtime-config.ts` است. */}
        <script dangerouslySetInnerHTML={{ __html: publicConfigScript(effectivePublicConfig()) }} />
        <script dangerouslySetInnerHTML={{ __html: ANTI_FOUC }} />
        {preloadFont ? (
          <link
            rel="preload"
            href="/fonts/Vazirmatn-Regular.woff2"
            as="font"
            type="font/woff2"
            crossOrigin="anonymous"
          />
        ) : null}
        {/* F5.2 — لینکِ صریحِ iOS. `metadata.icons.apple` هم همین را می‌سازد،
            ولی این‌جا دستی هم هست تا اگر بعداً metadata عوض شد، نصب روی iOS
            ناگهان بی‌آیکون نشود. */}
        <link rel="apple-touch-icon" href="/icons/icon-512.png" />
      </head>
      <body className={vazir.className}>
        <ThemeProvider>
          <LanguageProvider>
            <AuthProvider>
              <ToastProvider>{children}</ToastProvider>
            </AuthProvider>
          </LanguageProvider>
        </ThemeProvider>
      </body>
    </html>
  );
}
