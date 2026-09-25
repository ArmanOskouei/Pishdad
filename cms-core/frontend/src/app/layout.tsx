import type { Metadata } from "next";
import { Vazirmatn } from "next/font/google";
import "./vazirmatn.css";
import "./globals.css";
import { ThemeProvider } from "@/lib/theme";
import { AuthProvider } from "@/lib/auth";
import { ToastProvider } from "@/components/ui/Toast";

/**
 * فونت وزیرمتن: اول next/font/google؛ اگر آفلاین بود بیلد فونت فیل می‌شود.
 * fallback = فونت محلی از `vazirmatn.css` (public/fonts).
 * قانون: هرگز CDN خارجی برای فونت استفاده نشود — در نبود دسترسی به اینترنت
 * لود نمی‌شود و مرورگر به فونت جایگزین می‌افتد (عنوان هدر و جستجو ناخوانا می‌شد).
 */
const vazir = Vazirmatn({ subsets: ["arabic", "latin"], weight: ["300", "400", "500", "700"], display: "swap", variable: "--font-vazir" });

export const metadata: Metadata = { title: "پنل مدیریت CMS", description: "پنل مدیریت فارسی — Next.js 15" };

/** اسکریپت ضد FOUC: خواندن تم از localStorage و اعمال data-attributes قبل از رنگ. */
const ANTI_FOUC = `(function(){try{var t=JSON.parse(localStorage.getItem("cms-ui-f1")||"{}");var h=document.documentElement;var mode=t.mode||"dark";if(mode==="system")mode=matchMedia("(prefers-color-scheme: light)").matches?"light":"dark";h.dataset.direction=t.preset||"amaliyat";h.dataset.theme=mode;h.dataset.accent=t.accent||"indigo";h.dataset.radius=t.radius||"sharp";h.dataset.density=t.density||"compact";h.dataset.fontSize=t.font||"md";h.dir=t.dir||"rtl";}catch(e){}})();`;

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="fa" dir="rtl" suppressHydrationWarning>
      <head>
        <script dangerouslySetInnerHTML={{ __html: ANTI_FOUC }} />
        <link
          rel="preload"
          href="/fonts/Vazirmatn-Regular.woff2"
          as="font"
          type="font/woff2"
          crossOrigin="anonymous"
        />
      </head>
      <body className={vazir.className}>
        <ThemeProvider>
          <AuthProvider>
            <ToastProvider>{children}</ToastProvider>
          </AuthProvider>
        </ThemeProvider>
      </body>
    </html>
  );
}
