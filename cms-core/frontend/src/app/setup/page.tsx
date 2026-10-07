import type { Metadata } from "next";
import Link from "next/link";
import { headers } from "next/headers";
import { DEFAULT_PUBLIC_LOCALE, PUBLIC_LOCALES } from "@/lib/i18n/public";
import { configFilePath, configWritable, effectiveValue, isInstalled, readSiteConfig } from "@/lib/site-config";
import type { SetupInput } from "./actions";
import { SetupForm } from "./SetupForm";

/**
 * نصب‌کنندهٔ وبِ فرانت (E43).
 *
 * طراحی: کاربر **فقط اطلاعاتِ بک‌اند** را وارد می‌کند؛ نصب‌کننده اتصال را واقعاً
 * می‌آزماید، مقادیر را در یک فایلِ JSON روی volume می‌نویسد و همان لحظه
 * همه‌چیز — از جمله میدل‌ور — مقدارِ تازه را می‌بیند. هیچ بازسازی‌ای لازم نیست،
 * چون لایهٔ config در `lib/site-config.ts` زمانِ اجرا خوانده می‌شود.
 *
 * `force-dynamic`: این صفحه هرگز نباید کش شود. یک نسخهٔ کش‌شدهٔ «نصب کامل شد»
 * یا «نصب لازم است» می‌تواند گمراه‌کننده باشد.
 */
export const dynamic = "force-dynamic";

export const metadata: Metadata = {
  title: "نصبِ فرانت‌اند",
  // صفحهٔ نصب هرگز نباید ایندکس شود.
  robots: { index: false, follow: false },
};

/** origin از هدرها، تا نشانیِ سایت پیش‌فرض درست باشد. */
async function requestOrigin(): Promise<string> {
  const h = await headers();
  const host = h.get("x-forwarded-host") ?? h.get("host") ?? "";
  if (!host) return "";

  const proto = h.get("x-forwarded-proto") ?? (/^(localhost|127\.|\[::1\])/.test(host) ? "http" : "https");
  return `${proto}://${host}`;
}

/** یک مقدار: فایل ← محیطِ زمانِ اجرا ← محیطِ زمانِ بیلد، و در نهایت پیش‌فرض. */
const pick = (fromFile: string | undefined, runtimeName: string, buildTime: string | undefined, fallback = ""): string =>
  fromFile ?? effectiveValue(runtimeName, buildTime, fallback) ?? "";

/**
 * ریشهٔ عمومیِ بک‌اند، از روی نشانیِ API.
 *
 * `http://localhost:8080/api` → `http://localhost:8080`
 *
 * ⚠️ چرا مهم است: تصاویرِ آپلودی و **گالری** روی خودِ بک‌اند ذخیره می‌شوند
 * (`/storage/...`) و نشانی‌شان از پایهٔ رسانه ساخته می‌شود. اگر این مقدار خالی
 * بماند، مرورگر تصویر را از **فرانت** می‌خواهد و ۴۰۴ می‌گیرد — دلیلِ اینکه
 * گالری خالی بود ولی قالب‌های محتوایی (که از `frontend/public` سرو می‌شوند)
 * درست می‌آمدند. پس پیش‌فرضش را از همان نشانیِ API می‌گیریم تا کاربر لازم نباشد
 * چیزی را حدس بزند.
 */
function backendOrigin(apiUrl: string): string {
  const trimmed = apiUrl.trim();
  if (trimmed === "") return "";

  try {
    return new URL(trimmed).origin;
  } catch {
    return "";
  }
}

export default async function SetupPage() {
  const config = readSiteConfig();
  const installed = isInstalled();
  const writable = configWritable();
  const origin = await requestOrigin();

  if (installed) {
    return (
      <div className="mx-auto w-full max-w-2xl px-4 py-16" dir="rtl">
        <div className="rounded-2xl border border-neutral-200 p-8">
          <h1 className="text-2xl font-bold text-neutral-900">این فرانت قبلاً نصب شده است</h1>
          <p className="mt-3 text-sm leading-6 text-neutral-600">
            برای جلوگیری از بازتنظیمِ سایت توسط هر کسی که به این نشانی می‌رسد، نصب‌کننده قفل است. برای نصبِ دوباره،
            فایلِ تنظیمات را پاک کنید و این صفحه را دوباره باز کنید.
          </p>

          <dl className="mt-6 space-y-3 text-sm">
            <div>
              <dt className="text-neutral-500">فایلِ تنظیمات</dt>
              <dd className="mt-0.5 break-all font-mono text-xs text-neutral-800">{configFilePath()}</dd>
            </div>
            <div>
              <dt className="text-neutral-500">نشانیِ API</dt>
              <dd className="mt-0.5 break-all font-mono text-xs text-neutral-800">{config.apiUrl}</dd>
            </div>
            <div>
              <dt className="text-neutral-500">زمانِ نصب</dt>
              <dd className="mt-0.5 text-neutral-800">{config.installedAt}</dd>
            </div>
            <div>
              <dt className="text-neutral-500">زبان‌ها</dt>
              <dd className="mt-0.5 text-neutral-800">
                {config.locales ?? DEFAULT_PUBLIC_LOCALE}
                {config.primaryLocale ? ` (پایه: ${config.primaryLocale})` : ""}
              </dd>
            </div>
          </dl>

          <Link
            href="/"
            className="mt-8 inline-block rounded-lg bg-neutral-900 px-5 py-2 text-sm font-medium text-white hover:bg-neutral-800"
          >
            رفتن به سایت
          </Link>
        </div>
      </div>
    );
  }

  // نشانیِ API جدا حساب می‌شود چون پیش‌فرضِ رسانه از آن مشتق می‌شود.
  const apiUrl = pick(config.apiUrl, "CMS_PUBLIC_API_URL", process.env.NEXT_PUBLIC_API_URL);

  const defaults: SetupInput = {
    apiUrl,
    internalApiUrl: pick(config.internalApiUrl, "INTERNAL_API_URL", process.env.INTERNAL_API_URL),
    siteUrl: pick(config.siteUrl, "CMS_SITE_URL", process.env.NEXT_PUBLIC_SITE_URL) || origin,
    locales: pick(config.locales, "CMS_SITE_LOCALES", process.env.NEXT_PUBLIC_SITE_LOCALES) || DEFAULT_PUBLIC_LOCALE,
    primaryLocale:
      pick(config.primaryLocale, "CMS_SITE_PRIMARY_LOCALE", process.env.NEXT_PUBLIC_SITE_PRIMARY_LOCALE) ||
      DEFAULT_PUBLIC_LOCALE,
    // ⚠️ پیش‌فرض از ریشهٔ بک‌اند مشتق می‌شود، نه از فرانت — دلیلش در `backendOrigin`.
    mediaUrl:
      pick(config.mediaUrl, "CMS_PUBLIC_MEDIA_URL", process.env.NEXT_PUBLIC_MEDIA_URL) || backendOrigin(apiUrl),
    // ⚠️ رازِ ذخیره‌شده هرگز در فرم بازنشانده نمی‌شود؛ اگر از قبل هست، کاربر
    // باید دوباره واردش کند. افشای آن در HTML یک صفحه یعنی هر بازدیدکننده‌ای
    // می‌توانست کشِ سایت را پاک کند.
    revalidateSecret: "",
    vapidPublicKey: pick(config.vapidPublicKey, "CMS_PUBLIC_VAPID_PUBLIC_KEY", process.env.NEXT_PUBLIC_VAPID_PUBLIC_KEY),
    siteId: pick(config.siteId, "CMS_SITE_ID", process.env.NEXT_PUBLIC_SITE_ID),
  };

  return <SetupForm defaults={defaults} writable={writable} />;
}
