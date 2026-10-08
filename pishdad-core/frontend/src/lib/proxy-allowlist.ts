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
 * ریشهٔ `v1` تنها ریشهٔ مستقل است؛ هیچ ریشهٔ موازی دیگری وجود ندارد که
 * فراموش شود و باز کردن یکی از آن‌ها سطحِ حمله را بزرگ می‌کرد.
 */
const ALLOWED_ROOTS = new Set(["v1"]);

/**
 * slugهایی که از راه catch-all افزونه‌ها از مرورگر بازند — **از پیکربندی**.
 *
 * این لیست عمداً ثابت نیست (L-B12): قبلاً اینجا نامِ یک افزونهٔ خاص در بایندِ
 * خودِ هسته نوشته شده بود، یعنی هر افزونهٔ تازهٔ مرورگری برای کار کردن نیاز به
 * **ویرایش کد هسته** پیدا می‌کرد.
 *
 * حالا از `NEXT_PUBLIC_BROWSER_PLUGIN_SLUGS` می‌آید — یک متغیر محیطی که هنگام
 * build تعیین می‌شود. در build عمومی خالی است، پس ریشهٔ افزونه از مرورگر باز
 * نیست و هسته هیچ چیزی دربارهٔ افزونه‌های خاص نمی‌داند.
 *
 * ## چرا هنوز allowlist لازم است
 *
 * `/v1/p/{slug}` سطحِ عمومیِ dispatch است — یعنی **هر بسته‌ای که روی دیسک
 * نصب باشد**. احراز هویت و مجوزش را افزونه در مانیفستش اعلام می‌کند و
 * `PluginRouter` اجرایشان می‌کند. پروکسی راهی ندارد که بداند آن اعلان درست
 * است یا نه، پس باز کردن کل ریشه یعنی اعتماد به هر کدی که یک بسته نصب کرده.
 *
 * پس این لیست **عمداً** کوچک است: فقط بسته‌هایی که واقعاً باید از مرورگر
 * صدا زده شوند. هر بستهٔ موردنیاز خودش را در این متغیر اعلام می‌کند، نه
 * اینکه هسته برایش حدس بزند.
 */
function browserReachablePluginSlugs(): Set<string> {
  // ⚠️ عمداً بدونِ import از `runtime-config`: این ماژول را اجراکنندهٔ تستِ Node
  // بار می‌کند و رزولورِ ESMِ Node برای importِ نسبی به پسوند نیاز دارد. همان
  // الگوی «نامِ بدونِ پیشوند = زمانِ اجرا، نامِ NEXT_PUBLIC_ = زمانِ بیلد»
  // اینجا مستقیم نوشته شده تا ماژول خودکفا بماند.
  const raw =
    (process.env["PISHDAD_BROWSER_PLUGIN_SLUGS"] ?? "").trim() ||
    (process.env.NEXT_PUBLIC_BROWSER_PLUGIN_SLUGS ?? "");
  return new Set(
    raw
      .split(",")
      .map((s) => s.trim())
      .filter((s) => /^[a-z0-9][a-z0-9._-]*$/.test(s)),
  );
}

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
 * `v1/**` می‌خواند.
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

  // K7.9 — catch-all افزونه‌ها: فقط slugهایی که build اعلام کرده بازند.
  //
  // جای این شرط، **بعد از** نرمال‌سازی و **قبل از** هر lookup است، چون
  // `slug` از ورودی کاربر می‌آید و قبل از این چک هیچ اعتبارسنجی‌ای رویش نبود.
  if (pieces[1] === "p") {
    if (pieces.length < 3) {
      return { ok: false, reason: "این مسیر افزونه از راه مرورگر در دسترس نیست." };
    }

    if (!browserReachablePluginSlugs().has(pieces[2])) {
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
