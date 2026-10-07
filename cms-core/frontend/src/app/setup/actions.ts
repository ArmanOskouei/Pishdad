"use server";

/**
 * اکشن‌های نصب‌کنندهٔ وبِ فرانت.
 *
 * ## چرا سرور و نه مرورگر
 *
 * آزمونِ اتصال باید از **سرور** انجام شود، نه از مرورگرِ کاربر:
 *   • CORS: بک‌اند ممکن است originِ فرانت را در فهرستِ مجاز نداشته باشد.
 *   • `revalidateSecret` هرگز نباید به مرورگر برود؛ نوشتنِ آن هم فقط کارِ سرور است.
 *   • همان مسیری که در نهایت استفاده می‌شود (`internalApiUrl`) فقط از سرور
 *     قابلِ آزمودن است.
 */

import { PUBLIC_LOCALES, isPublicLocale } from "@/lib/i18n/public";
import { configWritable, isInstalled, writeSiteConfig, type SiteConfig } from "@/lib/site-config";

export type SetupInput = {
  apiUrl: string;
  internalApiUrl: string;
  siteUrl: string;
  locales: string;
  primaryLocale: string;
  mediaUrl: string;
  revalidateSecret: string;
  vapidPublicKey: string;
  siteId: string;
};

export type TestResult = {
  ok: boolean;
  message: string;
  /** نشانیِ کارآمدِ API — ممکن است با آنچه کاربر نوشت فرق کند (تصحیحِ خودکار). */
  apiUrl?: string;
};

export type SaveResult = { ok: boolean; message: string; errors?: string[] };

const TIMEOUT_MS = 8000;

/** فقط `http(s)`. هر چیز دیگری (مثل `file:`) یعنی تلاش برای SSRF. */
function httpUrl(raw: string): URL | null {
  try {
    const url = new URL(raw.trim());
    return url.protocol === "http:" || url.protocol === "https:" ? url : null;
  } catch {
    return null;
  }
}

function normalizeBase(raw: string): string {
  return raw.trim().replace(/\/+$/, "");
}

/**
 * یکی از مسیرهای ممکنِ وضعیت را می‌آزماید.
 *
 * اگر کاربر `https://api.example.com` بنویسد به‌جای
 * `https://api.example.com/api`، اینجا خودش `/api` را هم امتحان می‌کند و
 * نشانیِ درست را برمی‌گرداند. این «تنظیمِ یک‌خطیِ اشتباه» رایج‌ترین خطای نصب
 * است و بهتر است نصب‌کننده خودش حلش کند تا کاربر.
 */
export async function testConnection(input: SetupInput): Promise<TestResult> {
  const base = normalizeBase(input.apiUrl);
  if (!base) return { ok: false, message: "نشانیِ API را وارد کنید." };

  const parsed = httpUrl(base);
  if (!parsed) return { ok: false, message: "نشانی باید با http:// یا https:// شروع شود." };

  const candidates = parsed.pathname.replace(/\/+$/, "").endsWith("/api") ? [base] : [base, `${base}/api`];

  const problems: string[] = [];

  for (const candidate of candidates) {
    try {
      const res = await fetch(`${candidate}/v1/site/status`, {
        cache: "no-store",
        signal: AbortSignal.timeout(TIMEOUT_MS),
        headers: { Accept: "application/json" },
      });

      if (!res.ok) {
        problems.push(`${candidate} → HTTP ${res.status}`);
        continue;
      }

      const json = (await res.json()) as { data?: { message?: string; status?: string } };
      const message = json?.data?.message?.trim();

      return {
        ok: true,
        apiUrl: candidate,
        message: message
          ? `اتصال برقرار شد — ${message}`
          : "اتصال برقرار شد و بک‌اند پاسخ داد.",
      };
    } catch (e) {
      const reason = e instanceof Error ? (e.name === "TimeoutError" ? "بی‌پاسخ (timeout)" : e.message) : String(e);
      problems.push(`${candidate} → ${reason}`);
    }
  }

  return {
    ok: false,
    message: `به بک‌اند نرسیدم. این‌ها را امتحان کردم: ${problems.join(" | ")}`,
  };
}

/** اعتبارسنجی، مشترک بین آزمون و ذخیره. */
function validate(input: SetupInput): string[] {
  const errors: string[] = [];

  if (!httpUrl(normalizeBase(input.apiUrl))) errors.push("نشانیِ API معتبر نیست.");

  const internal = normalizeBase(input.internalApiUrl);
  if (internal && !httpUrl(internal)) errors.push("نشانیِ داخلی معتبر نیست.");

  const site = normalizeBase(input.siteUrl);
  if (site && !httpUrl(site)) errors.push("نشانیِ سایت معتبر نیست.");

  const locales = input.locales
    .split(",")
    .map((s) => s.trim())
    .filter(Boolean);
  if (locales.length === 0) errors.push("حداقل یک زبان لازم است.");
  for (const l of locales) {
    if (!isPublicLocale(l)) errors.push(`زبانِ ناشناخته: «${l}» (پشتیبانی‌شده: ${PUBLIC_LOCALES.join(", ")})`);
  }

  const primary = input.primaryLocale.trim();
  if (!isPublicLocale(primary)) {
    errors.push(`زبانِ پایه نامعتبر است (پشتیبانی‌شده: ${PUBLIC_LOCALES.join(", ")})`);
  } else if (locales.length > 0 && !locales.includes(primary)) {
    errors.push("زبانِ پایه باید یکی از زبان‌های فعال باشد.");
  }

  if (!input.revalidateSecret.trim()) {
    errors.push("رازِ revalidate لازم است (همان REVALIDATE_SECRET بک‌اند).");
  }

  return errors;
}

export async function saveConfig(input: SetupInput): Promise<SaveResult> {
  // ⚠️ قفل. بدونِ این، هر کسی که به `/setup` برسد می‌توانست سایت را به سرورِ
  // خودش وصل کند. نصب یک‌بار انجام می‌شود و تمام.
  if (isInstalled()) {
    return { ok: false, message: "این فرانت قبلاً نصب شده است. برای نصبِ دوباره فایلِ config را پاک کنید." };
  }

  if (!configWritable()) {
    return {
      ok: false,
      message:
        "فایلِ تنظیمات قابلِ نوشتن نیست. پوشهٔ config را قابلِ نوشتن کنید یا مقادیرِ زیر را در .env بگذارید و کانتینر را دوباره بالا بیاورید.",
    };
  }

  const errors = validate(input);
  if (errors.length > 0) return { ok: false, message: "چند ایراد هست:", errors };

  // پیش از ذخیره، اتصال **دوباره** واقعاً آزموده می‌شود تا configِ خراب نوشته نشود.
  const probe = await testConnection(input);
  if (!probe.ok) return { ok: false, message: probe.message, errors: [probe.message] };

  const config: SiteConfig = {
    apiUrl: probe.apiUrl ?? normalizeBase(input.apiUrl),
    revalidateSecret: input.revalidateSecret.trim(),
    locales: input.locales
      .split(",")
      .map((s) => s.trim())
      .filter(Boolean)
      .join(","),
    primaryLocale: input.primaryLocale.trim(),
    installedAt: new Date().toISOString(),
  };

  const internal = normalizeBase(input.internalApiUrl);
  if (internal) config.internalApiUrl = internal;

  const site = normalizeBase(input.siteUrl);
  if (site) config.siteUrl = site;

  const media = normalizeBase(input.mediaUrl);
  if (media) config.mediaUrl = media;

  const vapid = input.vapidPublicKey.trim();
  if (vapid) config.vapidPublicKey = vapid;

  const siteId = input.siteId.trim();
  if (siteId) config.siteId = siteId;

  try {
    writeSiteConfig(config);
  } catch (e) {
    return { ok: false, message: `نوشتنِ فایل شکست خورد: ${e instanceof Error ? e.message : String(e)}` };
  }

  return { ok: true, message: "نصب کامل شد." };
}
