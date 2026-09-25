import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  output: "standalone",
  reactStrictMode: true,
  // PLAN.md: گوگل‌فونت در ایران مسدود است — فونت اول از next/font، fallback CDN + سیستم.

  // ── F3: cacheHandler روی Redis (یافته PLAN.md — کش ISR چابکان اِفمِرال است) ──
  // پیش‌فرض: خاموش — repo بدون Redis هم `next build`/`next start` سبز می‌ماند
  // (cache-handler.mjs در این حالت fallback حافظه process-local دارد).
  // فعال‌سازی روی چابکان:
  //   1) `npm i redis`
  //   2) `REDIS_URL=redis://<host>:6379` (+ اختیاری `CMS_CACHE_PREFIX=<tenant>:`)
  //   3) باز کردن کامنت خط بعد:
  // cacheHandler: require.resolve("./cache-handler.mjs"),
  // cacheMaxMemorySize: 0, // با Redis خارجی، کش درون‌حافظه پیش‌فرض را ببندید
};

export default nextConfig;
