import type { NextRequest } from "next/server";

/**
 * SEC-3/S-04 — نشانیِ واقعیِ کلاینت، قابلِ اعتماد.
 *
 * ## مشکلِ قبلی
 *
 * `login/route.ts` و `api/proxy/[...path]/route.ts` هدر `x-forwarded-for`
 * مرورگر را **عیناً** کپی می‌کردند (و `split(",")[0]` می‌گرفتند). این یعنی هر
 * کسی می‌توانست از مرورگر بنویسد:
 *
 *     fetch('/api/proxy/v1/admin/pages', { headers: { 'X-Forwarded-For': '8.8.8.8' } })
 *
 * و از فهرست IPهای مجازِ پnل (`admin.ip`) رد شود — یا با فرستادنِ XFF تصادفی
 * در هر تلاش، `throttle:auth-login-ip` را برای همیشه خنثی کند و brute-force
 * رمز بی‌سقف شود. بک‌اند `trustProxies(at:'*')` دارد، پس `$request->ip()`
 * می‌شود **چپ‌ترین** مقدار زنجیره — یعنی همان چیزی که مهاجم چسبانده.
 *
 * ## قاعده
 *
 * به هدرِ ورودیِ کاربر هیچ‌وقت اعتماد نمی‌کنیم. فقط در نخستین پروکسیِ معتبر
 * (nginx، که با `$proxy_add_x_forwarded_for` آدرسِ همتای واقعی را **انتهای**
 * زنجیره اضافه می‌کند) به آخرین حلقه اعتماد داریم — و آن حالت وقتی است که
 * خودمان پشتِ پروکزی باشیم. استقرارِ فعلی (سرور Next مستقیم روی پورتِ هاست)
 * هیچ پروکزji ندارird، پس هیچی فرستاده نمی‌شود و بک‌اند آدرسِ همتای اتصال را
 * می‌بیند — که قابلِ جعل نیست.
 *
 * اگر روزی سرور Next پشتِ nginx/CDN رفت، همین تابع باید فقط وقتی XFF را
 * ببرد که اتصال از پروکزیِ مورد اطمینان آمده باشد (لیستِ subnet در env)،
 * نه بی‌قید و شرط.
 */

/** هدرها و پرچم‌هایی که مرورگر می‌تواند روی‌شان جعل کند. */
const SPOOFABLE_IP_HEADERS = ["x-forwarded-for", "x-real-ip", "x-client-ip", "cf-connecting-ip"] as const;

/**
 * حلقهٔ قابلِ اعتمادِ زنجیرهٔ XFF برای فرستادن به بک‌اند — یا `null` اگر
 * پروکزی در مسیر نیست.
 *
 * عمداً `null` برمی‌گرداند، نه رشتهٔ خالی: فرستادنِ هدرِ خالیِ XFF باعث
 * می‌شود Laravel فکر کند یک پروکزی حرف دارد و مقدار را از اتصال بردارد.
 */
export function clientIpFor(req: NextRequest): string | null {
  // بدونِ اطمینان به پیش‌پروکزی، هیچ‌کدام از هدرهای بالا را نمی‌خوانیم.
  // اتصالِ مستقیمِ مرورگر به این سرور، تنها شاهدِ قابلِ اعتماد است.
  for (const header of SPOOFABLE_IP_HEADERS) {
    if (req.headers.get(header) !== null) {
      // هدر هست ولی منبعش نامعلوم است ⇒ مثلِ نبودش رفتار می‌کنیم و به
      // بک‌اند چیزی نمی‌گوییم؛ او آدرسِ همتای اتصال را می‌گیرد.
      break;
    }
  }

  return null;
}

/**
 * هدرهایی که به بک‌اند می‌روند تا `$request->ip()` درست شود.
 *
 * وقتی `clientIpFor` تهی است (حالتِ پیش‌فرض)، **هیچ** هدر IP فرستاده
 * نمی‌شود؛ در غیر این صورت فقط `X-Forwarded-For` با همان یک مقدار.
 */
export function clientIpHeaders(req: NextRequest): Record<string, string> {
  const ip = clientIpFor(req);
  if (ip === null) return {};

  return { "X-Forwarded-For": ip, "X-Real-IP": ip };
}
