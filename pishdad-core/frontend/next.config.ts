import type { NextConfig } from "next";

// ── F3 / F5.1: cacheHandler روی Redis (کش ISR چابکان اِفمِرال است) ──
// env-gated: فقط وقتی `REDIS_URL` ست باشد فعال می‌شود. بدون آن، Next از کش
// پیش‌فرضِ خودش استفاده می‌کند و repo بدون Redis هم `next build`/`start` سبز
// می‌ماند. cache-handler.mjs خودش هم در فاز بیلد و در نبود پکیج `redis` به
// حافظهٔ process-local fallback می‌کند.
// فعال‌سازی روی VPS/چابکان:
//   `REDIS_URL=redis://<host>:6379` (+ اختیاری `PISHDAD_CACHE_PREFIX=<tenant>:`) و `npm i redis`
const redisIsc = process.env.REDIS_URL
  ? { cacheHandler: "./cache-handler.mjs", cacheMaxMemorySize: 0 }
  : {};

// ── WF-M13: assetPrefix برای فایل‌های ایستای `/_next/static/*` ──
// همراستا با `docs/CDN-ASSESSMENT.md`: اگر روزی CDN لازم شد، فقط روی همین
// مسیر کم‌ریسک است (هش در نام ⇒ پاک‌سازی لازم ندارد)، نه HTML. تنظیم از env
// است چون `assetPrefix` ذاتاً زمان ساخت خوانده می‌شود؛ مقدار پنلِ «دامنهٔ
// دارایی» برای دارایی‌های محلیِ سایت در `lib/site.ts` اعمال می‌شود.
const assetPrefix = (process.env.NEXT_PUBLIC_ASSET_PREFIX ?? "").trim().replace(/\/+$/, "");

const nextConfig: NextConfig = {
  output: "standalone",
  reactStrictMode: true,
  ...redisIsc,
  ...(assetPrefix ? { assetPrefix } : {}),
  // PLAN.md: گوگل‌فونت در ایران مسدود است — فونت اول از next/font، fallback CDN + سیستم.
};

export default nextConfig;
