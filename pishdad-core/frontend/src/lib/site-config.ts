/**
 * پیکربندیِ **نصبِ** فرانت — از یک فایلِ JSON که نصب‌کنندهٔ وب می‌نویسد.
 *
 * ## چرا فایل و نه فقط متغیرِ محیط
 *
 * `runtime-config.ts` مقدارها را در زمانِ اجرا از محیط می‌خواند، ولی نوشتنِ
 * متغیرِ محیط در زمانِ اجرا ممکن نیست: پروسهٔ در حالِ اجرا محیطش را عوض
 * نمی‌کند. اگر تنها راهِ تنظیم، متغیرِ محیط باشد، «نصب» یعنی «توقفِ کانتینر،
 * ویرایشِ فایل، شروعِ دوباره».
 *
 * این فایل آن گام را حذف می‌کند: نصب‌کنندهٔ وبی JSON را روی یک volume می‌نویسد
 * و **همان لحظه** همه‌چیز (از جمله میدل‌ور) مقدارِ تازه را می‌بیند. بازسازی
 * لازم نیست، توقفِ کانتینر هم لازم نیست.
 *
 * ## ترتیبِ اولویت
 *
 *   فایلِ نصب  ←  متغیرِ محیط با نامِ `CMS_*`  ←  مقدارِ زمانِ بیلدِ `NEXT_PUBLIC_*`  ←  پیش‌فرض
 *
 * فایل بالاتر از محیط است چون تنها چیزی است که کاربر از راهِ نصب‌کننده نوشته
 * و باید بر تنظیماتِ ازپیش‌موجودِ اپراتور بچربد. نبودِ فایل یعنی همه‌چیز دقیقاً
 * مثلِ قبل کار می‌کند — این افزودنی، نه جانشین.
 *
 * ## ⚠️ این ماژول فقط سمتِ سرور است
 *
 * `node:fs` می‌خواهد، پس هرگز نباید از کامپوننتِ کلاینت import شود. مقدارهایی
 * که به مرورگر می‌رود از `effectivePublicConfig()` عبور می‌کنند که فقط سه
 * مقدارِ بی‌خطر را برمی‌گرداند؛ `revalidateSecret` هرگز به مرورگر نمی‌رود.
 */

import { existsSync, mkdirSync, readFileSync, renameSync, statSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { runtimeValue, type PublicConfig } from "./runtime-config.ts";

/** شکلِ فایل. همه اختیاری‌اند تا فایلِ نیمه‌کاره هم بی‌خطر باشد. */
export type SiteConfig = {
  /** نشانیِ عمومیِ API بک‌اند (مرورگر این را صدا می‌زند). مثل `https://api.example.com/api`. */
  apiUrl?: string;
  /**
   * نشانیِ سرور-به-سرور (SSR و پروکسی). اگر خالی باشد از `apiUrl` استفاده می‌شود.
   * در داکر معمولاً نامِ سرویس است تا از اینترنت دور بزند.
   */
  internalApiUrl?: string;
  /** پایهٔ سرورِ بک‌اند (لاراول) برای دارایی‌های رسانه‌ای (MinIO/S3 یا `/storage`). */
  mediaUrl?: string;
  /** کلیدِ عمومیِ Web Push. */
  vapidPublicKey?: string;
  /** شناسهٔ سایت در نصب‌های چندسایتی. در نصبِ معمولی خالی است. */
  siteId?: string;
  /** نشانیِ عمومیِ همین سایتِ فرانت (canonical/OG/sitemap/llms). */
  siteUrl?: string;
  /** پایهٔ CDN برای `/_next/static` (به‌ندرت لازم). */
  assetUrl?: string;
  /** زبان‌های فعال، جداشده با کاما. مثل `fa,en`. */
  locales?: string;
  /** زبانِ پایه (بدونِ پیشوند در URL). مثل `fa`. */
  primaryLocale?: string;
  /** ریشه‌هایی که پوستهٔ پنل‌اند (پیش‌فرض `admin`). */
  panelRoots?: string;
  /** slugهای افزونه‌ای که پروکسیِ مرورگر باید عبور دهد. */
  browserPluginSlugs?: string;
  /**
   * ⚠️ هم‌ارزِ `REVALIDATE_SECRET` بک‌اند. رازِ امضا است، پس **هرگز** به مرورگر
   * تزریق نمی‌شود و در پاسخِ نصب‌کننده هم بازنمایش داده نمی‌شود.
   */
  revalidateSecret?: string;
  /** زمانِ نصب — وجودش یعنی نصب‌کننده قفل است. */
  installedAt?: string;
};

/**
 * نامِ متغیرِ محیط ← کلیدِ فایل.
 *
 * همین نقشه است که اجازه می‌دهد `runtimeValue` بدونِ تغییرِ هیچ‌کدام از
 * مصرف‌کننده‌ها، مقدارِ فایل را هم ببیند.
 */
const VAR_KEYS: Record<string, keyof SiteConfig> = {
  PISHDAD_PUBLIC_API_URL: "apiUrl",
  PISHDAD_PUBLIC_MEDIA_URL: "mediaUrl",
  PISHDAD_PUBLIC_VAPID_PUBLIC_KEY: "vapidPublicKey",
  PISHDAD_SITE_ID: "siteId",
  PISHDAD_SITE_URL: "siteUrl",
  PISHDAD_ASSET_URL: "assetUrl",
  PISHDAD_SITE_LOCALES: "locales",
  PISHDAD_SITE_PRIMARY_LOCALE: "primaryLocale",
  INTERNAL_API_URL: "internalApiUrl",
  PISHDAD_INTERNAL_API_URL: "internalApiUrl",
  REVALIDATE_SECRET: "revalidateSecret",
  PISHDAD_PANEL_ROOTS: "panelRoots",
  PISHDAD_BROWSER_PLUGIN_SLUGS: "browserPluginSlugs",
};

/**
 * مسیرِ فایل.
 *
 * عمداً **هرگز** از درخواست گرفته نمی‌شود — فقط از محیط. اگر مسیر از ورودیِ
 * کاربر می‌آمد، نصب‌کننده به یک آسیب‌پذیریِ نوشتنِ فایلِ دلبخواه تبدیل می‌شد.
 */
export function configFilePath(): string {
  const fromEnv = (process.env.PISHDAD_CONFIG_FILE ?? "").trim();
  if (fromEnv) return fromEnv;
  return join(process.cwd(), "config", "runtime.json");
}

type Cache = { path: string; mtimeMs: number; size: number; data: SiteConfig };

let cache: Cache | null = null;

/**
 * فایل را می‌خواند، با کشِ مبتنی بر `mtime`.
 *
 * این تابع در هر درخواست (از پروکسی و از رندر) صدا زده می‌شود، پس نباید هر
 * بار دیسک را بزند. `statSync` ارزان است و اگر `mtime`/اندازه عوض نشده باشد
 * محتوای قبلی برمی‌گردد — یعنی نوشتنِ نصب‌کننده **بی‌درنگ** دیده می‌شود بدونِ
 * این‌که هر درخواست یک read کامل بدهد.
 *
 * هیچ‌وقت throw نمی‌کند: فایلِ خراب یا نبودِ مجوز نباید کلِ سایت را بیندازد.
 * نبودِ فایل = `{}` = رفتارِ قبلی (همه‌چیز از محیط).
 */
export function readSiteConfig(): SiteConfig {
  const path = configFilePath();

  try {
    const st = statSync(path);
    if (cache && cache.path === path && cache.mtimeMs === st.mtimeMs && cache.size === st.size) {
      return cache.data;
    }

    const raw = readFileSync(path, "utf8");
    const parsed: unknown = JSON.parse(raw);
    const data =
      parsed && typeof parsed === "object" && !Array.isArray(parsed) ? (parsed as SiteConfig) : {};

    cache = { path, mtimeMs: st.mtimeMs, size: st.size, data };
    return data;
  } catch {
    // نبودِ فایل (ENOENT) حالتِ عادیِ یک نصبِ متغیر-محور است، نه خطا.
    cache = null;
    return {};
  }
}

/** فقط برای تست‌ها: کش را خالی می‌کند تا فایلِ تازه خوانده شود. */
export function resetSiteConfigCache(): void {
  cache = null;
}

/**
 * نوشتنِ اتمیک با مجوزِ محدود.
 *
 * نوشتنِ مستقیم روی فایلِ نهایی یعنی اگر پروسه بینِ دو نوشتن بمیرد، یک فایلِ
 * نیمه‌بریده می‌ماند و سایت با JSONِ خراب بالا می‌آید. نوشتن در فایلِ موقت و
 * بعد `rename` اتمیک است.
 *
 * `mode: 0o600` چون `revalidateSecret` داخلِ همین فایل است.
 */
export function writeSiteConfig(config: SiteConfig): void {
  const path = configFilePath();
  mkdirSync(dirname(path), { recursive: true });

  const tmp = `${path}.${process.pid}.tmp`;
  writeFileSync(tmp, JSON.stringify(config, null, 2) + "\n", { encoding: "utf8", mode: 0o600 });
  renameSync(tmp, path);

  cache = null;
}

/** آیا فایل قابلِ نوشتن است؟ نصب‌کننده باید پیش از تلاش، این را بداند. */
export function configWritable(): boolean {
  try {
    const path = configFilePath();
    mkdirSync(dirname(path), { recursive: true });
    // اگر فایل هست مجوزش را ببین، وگرنه پوشهٔ پدر باید قابلِ نوشتن باشد.
    return existsSync(path) ? true : existsSync(dirname(path));
  } catch {
    return false;
  }
}

/** نصب کامل شده است؟ (فایل نوشته شده و رازِ revalidate دارد) */
export function isInstalled(): boolean {
  const c = readSiteConfig();
  return Boolean(c.installedAt && c.apiUrl && c.revalidateSecret);
}

/**
 * یک مقدار با اولویتِ فایل ← محیطِ زمانِ اجرا ← زمانِ بیلد ← پیش‌فرض.
 *
 * جانشینِ `runtimeValue` برای کدِ سمتِ سرور است.
 */
export function effectiveValue(name: string, buildTime: string | undefined, fallback = ""): string {
  const key = VAR_KEYS[name];
  if (key) {
    const fromFile = readSiteConfig()[key];
    if (typeof fromFile === "string" && fromFile.trim()) return fromFile.trim();
  }

  return runtimeValue(name, buildTime, fallback);
}

/**
 * سه مقدارِ عمومی که به مرورگر تزریق می‌شوند.
 *
 * عمداً یک فهرستِ سفید است: هر چیزی که اینجا نباشد (مثل `revalidateSecret`)
 * هرگز به HTML نمی‌رسد.
 */
export function effectivePublicConfig(): PublicConfig {
  return {
    apiUrl: effectiveValue("PISHDAD_PUBLIC_API_URL", process.env.NEXT_PUBLIC_API_URL, "http://localhost:8080/api"),
    mediaUrl: effectiveValue("PISHDAD_PUBLIC_MEDIA_URL", process.env.NEXT_PUBLIC_MEDIA_URL).replace(/\/+$/, ""),
    vapidPublicKey: effectiveValue("PISHDAD_PUBLIC_VAPID_PUBLIC_KEY", process.env.NEXT_PUBLIC_VAPID_PUBLIC_KEY),
  };
}
