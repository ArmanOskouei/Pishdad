import type { Metadata } from "next";
import "./vazirmatn.css";
import "./globals.css";
import { ThemeProvider } from "@/lib/theme";
import { LanguageProvider } from "@/lib/i18n";
import { AuthProvider } from "@/lib/auth";
import { ToastProvider } from "@/components/ui/Toast";

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
      { url: "/icons/icon-32.png", sizes: "32", type: "image/png" },
      { url: "/icons/icon-128.png", sizes: "128", type: "image/png" },
      { url: "/icons/icon-256.png", sizes: "256", type: "image/png" },
    ],
    apple: [{ url: "/icons/icon-512.png", sizes: "180" }],
  },
};

/**
 * PERF-1 — لایوت دیگر `headers()` نمی‌خواند.
 *
 * ECO2 قدیمی زبانِ سایت را از هدرِ میدل‌ور می‌گرفت. ولی `headers()` هر صفحهٔ
 * عمومی را **داینامیک** می‌کرد و `export const revalidate = 300` را می‌کشت —
 * کل سایت عملاً SSR در هر درخواست بود. حالا:
 *
 *   - زبان/جهتِ سایتِ عمومی در `[locale]/layout.tsx` از پارامترِ مسیر
 *     می‌آید (و همان `<div lang dir>` در HTML است — گوگل از هر عنصری
 *     می‌خواند).
 *   - پیکربندیِ زمانِ اجرا به مسیرِ `/runtime-config.js` رفت (کششدنی،
 *     بدون نیاز به nonce ⇒ بدون مجبورکردنِ صفحه به داینامیک).
 *   - تم ضدِ FOUC هم فایلِ استاتیک شد.
 *
 * `data-cms-surface` حذف شد چون فقط سمتِ کلاینت خوانده می‌شد و دو اسکریپتِ
 * `<head>` الآن سطح را از `__CMS_CONFIG__.panelRoots` می‌شناسند.
 *
 * فونت همیشه محلی است (هرگز CDN خارجی) و `font-display: swap` تضمین می‌کند
 * خاموش‌کردن preload تجربه را نمی‌شکند.
 */
export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="fa" dir="rtl" suppressHydrationWarning>
      <head>
        {/* PERF-1: پیکربندیِ زمانِ درخواست + تم ضدِ FOUC — اسکریپتِ کلاسیکِ
            بلاک‌کننده و به‌ترتیب، پس قبل از اولین رنگ اجرا می‌شوند (همان
            تضمینِ ضد-FOUC) اما بدون nonce و بدون inline. */}
        <script src="/runtime-config.js" />
        <script src="/js/anti-fouc.js" />
        {/* WF-M13 — فونت محلی، preload بی‌قید و شرط (پنل هم همان فونت را
            دارد). */}
        <link rel="preload" href="/fonts/Vazirmatn-Regular.woff2" as="font" type="font/woff2" crossOrigin="anonymous" />
        {/* F5.2 — لینکِ صریحِ iOS. `metadata.icons.apple` هم همین را می‌سازد،
            ولی این‌جا دستی هم هست تا اگر بعداً metadata عوض شد، نصب روی iOS
            ناگهان بی‌آیکون نشود. */}
        <link rel="apple-touch-icon" href="/icons/icon-512.png" />
      </head>
      <body>
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
