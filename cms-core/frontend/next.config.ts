import type { NextConfig } from "next";

// ── F3 / F5.1: cacheHandler روی Redis (کش ISR چابکان اِفمِرال است) ──
// env-gated: فقط وقتی `REDIS_URL` ست باشد فعال می‌شود. بدون آن، Next از کش
// پیش‌فرضِ خودش استفاده می‌کند و repo بدون Redis هم `next build`/`start` سبز
// می‌ماند. cache-handler.mjs خودش هم در فاز بیلد و در نبود پکیج `redis` به
// حافظهٔ process-local fallback می‌کند.
// فعال‌سازی روی VPS/چابکان:
//   `REDIS_URL=redis://<host>:6379` (+ اختیاری `CMS_CACHE_PREFIX=<tenant>:`) و `npm i redis`
const redisIsc = process.env.REDIS_URL
  ? { cacheHandler: "./cache-handler.mjs", cacheMaxMemorySize: 0 }
  : {};

const nextConfig: NextConfig = {
  output: "standalone",
  reactStrictMode: true,
  ...redisIsc,
  // PLAN.md: گوگل‌فونت در ایران مسدود است — فونت اول از next/font، fallback CDN + سیستم.
};

export default nextConfig;
