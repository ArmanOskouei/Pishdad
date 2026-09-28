/**
 * allowlist مسیرهای قابل دسترس از راه پروکسی مرورگر.
 *
 * جدا از `route.ts` نگه داشته شده چون App Router اجازه نمی‌دهد فایل route
 * چیزی جز هندلرها و کلیدهای config صادر کند — و منطق امنیتی باید
 * مستقل و قابل تست باشد.
 */

/**
 * فقط این ریشه‌ها از راه مرورگر قابل دسترسی‌اند و هر چیز دیگری رد می‌شود.
 *
 * K7.8 — `central` دیگر ریشهٔ مستقل نیست. پنل مرکزی یک افزونهٔ معمولی شد و
 * زیر catch-all افزونه‌ها سرو می‌شود: `/api/v1/p/central/…`. یعنی ریشهٔ
 * `v1` همان که بود می‌ماند و چیزی به فهرست اضافه نمی‌شود.
 *
 * این فقط جابه‌جایی رشته نیست: ریشهٔ `central` یک **سطح دسترسی جدا** بود.
 * نگه‌داشتنش یعنی هر route بازاریِ دیگری هم از راه مرورگر قابل صدا زدن می‌شد.
 * حذفش سطح حمله را کوچک‌تر می‌کند — پنل مرکزی حالا دقیقاً همان احراز هویتِ
 * `v1` را می‌گیرد و هیچ ریشهٔ موازی‌ای وجود ندارد که فراموش شود.
 */
const ALLOWED_ROOTS = new Set(["v1"]);

/**
 * تنها slugی که از راه catch-all افزونه‌ها از مرورگر باز است.
 *
 * ## چرا فقط این یکی
 *
 * `/v1/p/{slug}` سطحِ عمومیِ dispatch است — یعنی **هر بسته‌ای که روی دیسک
 * نصب باشد**. احراز هویت و مجوزش را افزونه در مانیفستش اعلام می‌کند و
 * `PluginRouter` اجرایشان می‌کند. پروکسی هیچ راهی ندارد که بداند آن اعلان
 * درست است یا نه، پس باز کردن کل ریشه یعنی اعتماد به هر کدی که یک بسته
 * نصب کرده.
 *
 * مرکز استثناست چون **داخلی** است: در مخزن است، با `git pull` می‌آید، قابل
 * غیرفعال‌سازی نیست و پروژه خودش آن را بازبینی می‌کند. این دقیقاً همان
 * تمایزی است که K7.9 «بدون tier جدید اعتماد» نامیده بود — تفاوت در
 * *منشأ* است، نه در یک لایهٔ اعتمادِ تازه.
 *
 * ## چرا ۳۹ فراخوانیِ مرورگری این را قطعی می‌کنند
 *
 * `BillingClients`, `ClientsTable`, `OperatorClient`, `ReviewClient`,
 * `UpdatesClient`, `ChabokanClient` همه `authed()` می‌زنند، یعنی از مرورگر
 * و از راه همین پروکسی. بستنش یعنی پنل مرکزی در مرورگر کار نمی‌کند.
 */
const BROWSER_REACHABLE_PLUGIN_SLUGS = new Set(["central"]);

/** مسیرهایی که حتی زیر `v1` هم نباید از مرورگر قابل صدا زدن باشند. */
const DENIED_PREFIXES = ["v1/internal"];

export type Decision = { ok: true; path: string } | { ok: false; reason: string };

/**
 * مسیر دریافتی کاربر است، پس قبل از الحاق به `UPSTREAM` باید نرمال شود.
 *
 * پیش از این allowlist، مسیر کاربر مستقیم به `UPSTREAM` می‌رفت؛ یعنی هر کسی
 * که لاگین کرده بود می‌توانست `internal/heartbeat` را صدا بزند — که در ریشهٔ
 * `api.php` ثبت شده و فقط `throttle` دارد، نه auth. عملاً سرور را به POST به
 * هر مقصدی که کنترلر هدف گرفته بود وادار می‌کرد. `webhooks/payment` هم در
 * همان ریشه است و اصلاً نباید از مرورگر صدا زده شود.
 *
 * فهرست از روی فراخوانی‌های واقعی فرانت استخراج شد، نه حدس: پنل مشتری
 * `v1/**` می‌خواند و پنل مرکزی `central/**`.
 */
export function checkPath(raw: string[]): Decision {
  const pieces: string[] = [];

  for (const segment of raw) {
    let s: string;
    try {
      s = decodeURIComponent(segment);
    } catch {
      return { ok: false, reason: "مسیر درخواستی قابل خواندن نیست." };
    }
    // eslint-disable-next-line no-control-regex
    if (/[\u0000-\u001f\u007f]/.test(s)) {
      return { ok: false, reason: "کاراکتر کنترلی در مسیر مجاز نیست." };
    }

    // بعد از decode ممکن است یک segment چند بخش شود. `%2e%2e%2f` به `../`
    // تبدیل می‌شود که برابر `..` نیست، پس چک segment به‌تنهایی آن را رد
    // نمی‌کند و `v1/../../etc` ساخته می‌شد. هر بخشِ پس از decode جدا
    // بررسی می‌شود.
    for (const part of s.split(/[/\\]/)) {
      if (part === "" || part === "." || part === "..") {
        // تنها استثنا: آرگومان خالیِ تنها، که Next برای مسیر ریشه می‌فرستد.
        if (part === "" && pieces.length === 0 && raw.length === 1) continue;
        return { ok: false, reason: "Traversal در مسیر مجاز نیست." };
      }
      pieces.push(part);
    }
  }

  if (pieces.length === 0) return { ok: false, reason: "مسیر خالی است." };

  const joined = pieces.join("/");

  if (!ALLOWED_ROOTS.has(pieces[0])) {
    return { ok: false, reason: "این بخش از API از راه مرورگر در دسترس نیست." };
  }

  // K7.9 — catch-all افزونه‌ها: فقط slugهایِ داخلی بازند.
  //
  // جای این شرط، **بعد از** نرمال‌سازی و **قبل از** هر lookup است، چون
  // `slug` از ورودی کاربر می‌آید و قبل از این چک هیچ اعتبارسنجی‌ای رویش نبود.
  if (pieces[1] === "p") {
    if (pieces.length < 3) {
      return { ok: false, reason: "این مسیر افزونه از راه مرورگر در دسترس نیست." };
    }

    if (!BROWSER_REACHABLE_PLUGIN_SLUGS.has(pieces[2])) {
      return { ok: false, reason: "این مسیر افزونه از راه مرورگر در دسترس نیست." };
    }
  }

  for (const denied of DENIED_PREFIXES) {
    if (joined === denied || joined.startsWith(`${denied}/`)) {
      return { ok: false, reason: "این مسیر داخلی است و از راه مرورگر در دسترس نیست." };
    }
  }

  return { ok: true, path: joined };
}
