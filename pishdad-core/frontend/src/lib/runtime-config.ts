/**
 * پیکربندیِ عمومیِ فرانت در **زمانِ اجرا**.
 *
 * ## چرا این فایل وجود دارد
 *
 * Next مقادیرِ `NEXT_PUBLIC_*` را در زمانِ `next build` داخلِ باندل می‌نویسد.
 * نتیجه برای یک محصولِ نصب‌شدنی این بود: «نصب» یعنی «بیلدِ دوباره»، و روی
 * سرورِ فرانت به Node و `node_modules` نیاز داشت.
 *
 * این فایل آن مقدارها را به زمانِ **اجرا** می‌برد: سرور هنگامِ رندر،
 * مقدارهای فعلیِ محیط را در HTML می‌نویسد و کدِ مرورگر از همان می‌خواند.
 * یعنی یک باندلِ بیلدشده برای هر نصبی کار می‌کند و نصب‌کننده فقط تنظیمات را
 * می‌نویسد — بدونِ بازسازی.
 *
 * ## ⚠️ چرا نامِ متغیرها `NEXT_PUBLIC_` نیست (درسِ گران)
 *
 * نخستین پیاده‌سازی همین‌جا `process.env.NEXT_PUBLIC_API_URL` را در زمانِ
 * درخواست می‌خواند. **کار نکرد.** با یک آزمایشِ واقعی ثابت شد: سرور با
 * `NEXT_PUBLIC_API_URL=https://RUNTIME-WINNER.example/api` بالا آمد ولی HTML
 * همچنان مقدارِ زمانِ بیلد را نشان داد. دلیل: Next هر جا رشتهٔ
 * `process.env.NEXT_PUBLIC_X` را ببیند — **حتی در کدِ سرور** — آن را با ثابتِ
 * زمانِ بیلد جایگزین می‌کند، پس «خواندن در زمانِ اجرا» بی‌اثر می‌شود.
 *
 * ولي دسترسیِ **محاسبه‌شده** (`process.env[name]` با نامِ متغیر) توسط Next
 * جایگزین نمی‌شود و واقعاً در زمانِ اجرا خوانده می‌شود. پس مقدارهای زنده از
 * نام‌های بدونِ پیشوند می‌آیند و `NEXT_PUBLIC_*` فقط **fallbackِ زمانِ بیلد**
 * می‌ماند — که یعنی می‌توان همان بیلدِ ساده را بدونِ هیچ تنظیمی اجرا کرد.
 *
 * ## سه مقداری که واقعاً به مرورگر می‌رود
 *
 * فقط مقدارهایی که **مرورگر** مصرف می‌کند اینجا هستند. باقیِ `NEXT_PUBLIC_*`
 * از مرورگر خوانده نمی‌شود:
 *   • `SITE_ID`, `SITE_URL`, زبان‌ها و `PANEL_ROOTS` در `site.ts` و `proxy.ts`
 *     مصرف می‌شوند که سمتِ سرور/پروکسی اجرا می‌شوند.
 *   • `ASSET_PREFIX` طبقِ طراحی زمانِ بیلد است (`assetPrefix` در next.config).
 */

/** نامِ کلیدی که سرور در `window` می‌گذارد. */
export const PUBLIC_CONFIG_KEY = "__CMS_CONFIG__";

/** نامِ متغیرهای **زمانِ اجرا** (بدونِ پیشوندِ `NEXT_PUBLIC_` — دلیلش بالا). */
export const RUNTIME_VARS = {
  apiUrl: "PISHDAD_PUBLIC_API_URL",
  mediaUrl: "PISHDAD_PUBLIC_MEDIA_URL",
  vapidPublicKey: "PISHDAD_PUBLIC_VAPID_PUBLIC_KEY",
} as const;

export type PublicConfig = {
  apiUrl: string;
  mediaUrl: string;
  vapidPublicKey: string;
};

const DEFAULT_API_URL = "http://localhost:8080/api";

/**
 * یک مقدارِ محیطی را **در زمانِ اجرا** می‌خواند و اگر نبود به مقدارِ زمانِ
 * بیلد و سپس پیش‌فرض می‌افتد.
 *
 * ⚠️ `process.env[name]` عمداً با نامِ متغیر (محاسبه‌شده) نوشته شده است. اگر
 * `process.env.PISHDAD_PUBLIC_API_URL` نوشته شود Next می‌تواند آن را جایگزین کند؛
 * با کلیدِ متغیر، این یک lookupِ واقعیِ زمانِ اجرا می‌ماند.
 *
 * در محیطِ Edge (اگر پروکسی روی Edge اجرا شود) `process.env` پویا در دسترس
 * نیست؛ آنجا `undefined` برمی‌گردد و مقدارِ زمانِ بیلد می‌ماند — یعنی این
 * تابع **هرگز چیزی را نمی‌شکند** و فقط فرصتِ زمانِ اجرا را اضافه می‌کند.
 */
export function runtimeValue(name: string, buildTime: string | undefined, fallback = ""): string {
  const live = (process.env[name] ?? "").trim();
  return live || (buildTime ?? "").trim() || fallback;
}

/**
 * مقدارهای عمومی از محیطِ **سرور** — در زمانِ هر درخواست خوانده می‌شود.
 */
export function serverPublicConfig(): PublicConfig {
  return {
    apiUrl: runtimeValue(RUNTIME_VARS.apiUrl, process.env.NEXT_PUBLIC_API_URL, DEFAULT_API_URL),
    mediaUrl: runtimeValue(RUNTIME_VARS.mediaUrl, process.env.NEXT_PUBLIC_MEDIA_URL).replace(/\/+$/, ""),
    vapidPublicKey: runtimeValue(RUNTIME_VARS.vapidPublicKey, process.env.NEXT_PUBLIC_VAPID_PUBLIC_KEY),
  };
}

/** همان مقدارها به‌صورت یک اسنیپتِ JSON برای تزریق در HTML. */
export function publicConfigScript(config: PublicConfig = serverPublicConfig()): string {
  // `<` به `\u003c` تبدیل می‌شود تا مقدار نتواند تگِ `</script>` را ببندد.
  const json = JSON.stringify(config).replace(/</g, "\\u003c");

  return `window.${PUBLIC_CONFIG_KEY}=${json};`;
}

/**
 * مقدارهای عمومی از دیدِ **مصرف‌کننده** (مرورگر یا سرور).
 *
 * ترتیب: مقدارِ تزریق‌شده ← مقدارِ زمانِ اجرا (سرور) ← مقدارِ زمانِ بیلد ←
 * پیش‌فرض. اولین موردی که ناتهی باشد برنده است، پس یک نصبِ معمولی هر مسیر را
 * پوشش می‌دهد.
 */
export function publicConfig(): PublicConfig {
  // سمتِ سرور: مستقیم از محیط — تزریق لازم نیست.
  if (typeof window === "undefined") return serverPublicConfig();

  const injected =
    ((window as unknown as Record<string, Partial<PublicConfig> | undefined>)[PUBLIC_CONFIG_KEY]) ?? null;

  const pick = (fromInjected: string | undefined, fromBuild: string | undefined, fallback = ""): string =>
    (fromInjected ?? "").trim() || (fromBuild ?? "").trim() || fallback;

  return {
    apiUrl: pick(injected?.apiUrl, process.env.NEXT_PUBLIC_API_URL, DEFAULT_API_URL),
    mediaUrl: pick(injected?.mediaUrl, process.env.NEXT_PUBLIC_MEDIA_URL).replace(/\/+$/, ""),
    vapidPublicKey: pick(injected?.vapidPublicKey, process.env.NEXT_PUBLIC_VAPID_PUBLIC_KEY),
  };
}
