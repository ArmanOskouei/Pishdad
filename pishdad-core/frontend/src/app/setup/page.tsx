import type { Metadata } from "next";
import Link from "next/link";
import { headers } from "next/headers";
import { DEFAULT_PUBLIC_LOCALE, PUBLIC_LOCALES } from "@/lib/i18n/public";
import { configFilePath, configWritable, effectiveValue, isInstalled, readSiteConfig } from "@/lib/site-config";
import type { SetupInput } from "./actions";
import { SetupForm } from "./SetupForm";

/**
 * نصب‌کنندهٔ وبِ پنل (E54).
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
  // عنوانِ سرور (پیش‌فرضِ فارسی). سوییچِ زبانِ کلاینتی در `SetupForm` بعد از
  // mount عنوانِ مرورگر را هم‌راستا می‌کند (در `useEffect`، نه در رندر).
  title: "نصب پنل مدیریت پیشداد",
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
 * ⭐ ترتیبِ اولویتِ «نشانیِ عمومیِ سایت».
 *
 *   ۱. مقدارِ **صریح** در فایلِ config (کاربر قبلاً نوشته؛ بر همه‌چیز مقدم است)
 *   ۲. **originِ درخواستِ واقعی** (از هدرها)
 *   ۳. `PISHDAD_SITE_URL`ِ محیطِ زمانِ اجرا
 *   ۴. `NEXT_PUBLIC_SITE_URL`ِ زمانِ بیلد
 *   ۵. رشتهٔ خالی (فرم خودش اعتبارسنجی می‌کند)
 *
 * چرا origin **بالاتر** از `NEXT_PUBLIC_SITE_URL` است: فرانت خودش بهترین
 * منبعِ آدرسِ عمومیِ خودش است — همان دامنه‌ای که کاربر همین حالا در مرورگر
 * باز کرده و درخواست از آن رسیده. ولی `NEXT_PUBLIC_*` یک مقدارِ **پخته‌شده در
 * زمانِ بیلد** است؛ چون باندل باید روی هر سروری نصب شود (بخشِ `runtime-config`)،
 * این مقدار به‌سرعت کهنه می‌شود و همان باگی را می‌سازد که کاربر `http://localhost:3000`
 * را می‌بیند در حالی که صفحه روی `http://localhost:3100` باز است.
 *
 * ⚠️ بقیهٔ فیلدها دست‌نخورده‌اند و همچنان با `pick` حل می‌شوند.
 */
function resolveSiteUrl(fromFile: string | undefined, origin: string): string {
  const explicit = (fromFile ?? "").trim();
  return explicit || origin || effectiveValue("PISHDAD_SITE_URL", process.env.NEXT_PUBLIC_SITE_URL);
}

/**
 * ریشهٔ عمومیِ سرورِ بک‌اند (لاراول)، از روی نشانیِ API.
 *
 * `http://localhost:8080/api` → `http://localhost:8080`
 *
 * ⚠️ چرا مهم است: فایل‌ها و تصویرهای آپلودی و **گالری** روی خودِ بک‌اند ذخیره
 * می‌شوند (`/storage/...`) و نشانی‌شان از این پایه ساخته می‌شود. اگر این مقدار
 * خالی بماند، مرورگر تصویر را از **فرانت** می‌خواهد و ۴۰۴ می‌گیرد — دلیلِ
 * اینکه گالری خالی بود ولی قالب‌های محتوایی (که از `frontend/public` سرو
 * می‌شوند) درست می‌آمدند. پس پیش‌فرضش را از همان نشانیِ API می‌گیریم تا کاربر
 * لازم نباشد چیزی را حدس بزند.
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
      <div className="setup" dir="rtl">
        <div className="setup-wrap setup-wrap-narrow">
          <header className="setup-head">
            <div>
              <p className="setup-eyebrow">نصب پنل مدیریت پیشداد</p>
              <h1>این پنل قبلاً نصب شده است</h1>
              <p className="setup-lead">
                برای جلوگیری از بازتنظیمِ سایت توسط هر کسی که به این نشانی می‌رسد، نصب‌کننده قفل است. برای نصبِ دوباره،
                فایلِ تنظیمات را پاک کنید و این صفحه را دوباره باز کنید.
              </p>
            </div>
            <span className="setup-status ok">
              <span className="dot ok" aria-hidden="true" />
              نصب‌شده
            </span>
          </header>

          <section className="card card-pad">
            <dl className="setup-kv">
              <div>
                <dt>فایلِ تنظیمات</dt>
                <dd>
                  <code>{configFilePath()}</code>
                </dd>
              </div>
              <div>
                <dt>نشانیِ API</dt>
                <dd>
                  <code>{config.apiUrl}</code>
                </dd>
              </div>
              <div>
                <dt>زمانِ نصب</dt>
                <dd>{config.installedAt}</dd>
              </div>
              <div>
                <dt>زبان‌ها</dt>
                <dd>
                  {config.locales ?? DEFAULT_PUBLIC_LOCALE}
                  {config.primaryLocale ? ` (پایه: ${config.primaryLocale})` : ""}
                </dd>
              </div>
            </dl>

            <Link className="btn btn-primary" href="/">
              رفتن به سایت
            </Link>
          </section>
        </div>
      </div>
    );
  }

  // نشانیِ API جدا حساب می‌شود چون پیش‌فرضِ سرورِ بک‌اند از آن مشتق می‌شود.
  const apiUrl = pick(config.apiUrl, "PISHDAD_PUBLIC_API_URL", process.env.NEXT_PUBLIC_API_URL);

  const defaults: SetupInput = {
    apiUrl,
    internalApiUrl: pick(config.internalApiUrl, "INTERNAL_API_URL", process.env.INTERNAL_API_URL),
    // ⚠️ اولویت: فایل → originِ درخواست → `PISHDAD_SITE_URL` → `NEXT_PUBLIC_SITE_URL` (دلیلش در `resolveSiteUrl`).
    siteUrl: resolveSiteUrl(config.siteUrl, origin),
    locales: pick(config.locales, "PISHDAD_SITE_LOCALES", process.env.NEXT_PUBLIC_SITE_LOCALES) || DEFAULT_PUBLIC_LOCALE,
    primaryLocale:
      pick(config.primaryLocale, "PISHDAD_SITE_PRIMARY_LOCALE", process.env.NEXT_PUBLIC_SITE_PRIMARY_LOCALE) ||
      DEFAULT_PUBLIC_LOCALE,
    // ⚠️ پیش‌فرض از ریشهٔ سرورِ بک‌اند مشتق می‌شود، نه از فرانت — دلیلش در `backendOrigin`.
    mediaUrl:
      pick(config.mediaUrl, "PISHDAD_PUBLIC_MEDIA_URL", process.env.NEXT_PUBLIC_MEDIA_URL) || backendOrigin(apiUrl),
    // ⚠️ رازِ ذخیره‌شده هرگز در فرم بازنشانده نمی‌شود؛ اگر از قبل هست، کاربر
    // باید دوباره واردش کند. افشای آن در HTML یک صفحه یعنی هر بازدیدکننده‌ای
    // می‌توانست کشِ سایت را پاک کند.
    revalidateSecret: "",
    vapidPublicKey: pick(config.vapidPublicKey, "PISHDAD_PUBLIC_VAPID_PUBLIC_KEY", process.env.NEXT_PUBLIC_VAPID_PUBLIC_KEY),
    siteId: pick(config.siteId, "PISHDAD_SITE_ID", process.env.NEXT_PUBLIC_SITE_ID),
  };

  return <SetupForm defaults={defaults} writable={writable} />;
}
