/**
 * K6.6 — شکل کوکی `auth_token`.
 *
 * پیش از این فایل، پروکسی فقط **وجود** کوکی را می‌سنجید
 * (`src/proxy.ts` نسخهٔ قبل: `if (!token)`) ⇒ تنها چیزی که رد می‌شد غیبت یا
 * رشتهٔ خالی بود. هر رشتهٔ غیرخالی — `"x"`، `"null"`، `"   "`، یک کوکی
 * دست‌کاری‌شده — عبور می‌کرد و `NextResponse.next()` می‌گرفت.
 *
 * ⛔ این **احراز هویت نیست.** این فقط شکل مقدار را می‌سنجد (خالی/placeholder/
 * کاراکتر کنترلی/طول غیرمنطقی) و عمداً هیچ بررسی امضا یا انقضایی نمی‌کند؛
 * اصالت توکن همچنان کار بک‌اند است که از راه `Authorization: Bearer` رفت‌وبرگشت
 * می‌کند (`app/api/proxy/[...path]/route.ts:24` و `:35`،
 * `lib/server-api.ts:27`). ساختن رمزنگاری/احراز هویت جدید خارج از دامنهٔ این
 * تسک بود.
 *
 * چرا جداست: همان دلیل `lib/proxy-allowlist.ts` — App Router از فایل
 * proxy/route فقط هندلر و `config` صادر می‌کند و منطق امنیتی باید مستقل و
 * قابل تست باشد.
 */

/** مقادیری که از شکست خواندن JSON یا نبود فیلد می‌آیند و هرگز توکن واقعی نیستند. */
const PLACEHOLDER_VALUES = new Set(["null", "undefined", "none", "nil", "nan", "false", "true"]);

/**
 * کف طول. توکن واقعی این پروژه یا `id|hash` است یا JWT — هر دو از این بیشترند.
 * کف پایین، رشته‌های بی‌معنا (`"x"`, `"abc"`) را می‌گیرد.
 */
export const MIN_SESSION_TOKEN_LENGTH = 8;

/** سقف طول. توکن معتبر از این بیشتر نیست؛ بزرگ‌تر یعنی دادهٔ دلخواه، نه توکن. */
export const MAX_SESSION_TOKEN_LENGTH = 4096;

/** کاراکتر کنترلی/فاصلهٔ خاص — در ساخت هدر `Authorization` تزریق خط می‌دهد. */
const UNSAFE_TOKEN_CHARS = /[\u0000-\u0020\u007f]/;

export type SessionTokenDecision = { ok: true; token: string } | { ok: false; reason: string };

/**
 * آیا مقدار کوکی، **به‌عنوان شکل**، قابل استفاده به‌نظر می‌رسد؟
 *
 * رد می‌کند: غیبت، غیررشته، خالی، فقط فاصله، placeholderهای
 * `null/undefined/none/nil/NaN/true/false`، کاراکتر کنترلی یا فاصلهٔ
 * داخلی، و طول خارج از بازه.
 *
 * قبول می‌کند: هر رشتهٔ متراکمِ توکن‌مانند. عمداً سخت‌گیرانه‌تر نمی‌شود چون
 * قالب توکن در اختیار بک‌اند است.
 */
export function inspectSessionToken(raw: unknown): SessionTokenDecision {
  if (typeof raw !== "string") {
    return { ok: false, reason: "نشستی وجود ندارد." };
  }
  // فاصلهٔ ابتدا/انتها یعنی هدر `Bearer  x` با فاصلهٔ اضافه می‌شد.
  if (raw !== raw.trim()) {
    return { ok: false, reason: "مقدار کوکی نشست نامعتبر است." };
  }
  if (raw.length === 0) {
    return { ok: false, reason: "نشستی وجود ندارد." };
  }
  if (PLACEHOLDER_VALUES.has(raw.toLowerCase())) {
    return { ok: false, reason: "مقدار کوکی نشست نامعتبر است." };
  }
  if (UNSAFE_TOKEN_CHARS.test(raw)) {
    return { ok: false, reason: "مقدار کوکی نشست نامعتبر است." };
  }
  if (raw.length < MIN_SESSION_TOKEN_LENGTH) {
    return { ok: false, reason: "مقدار کوکی نشست نامعتبر است." };
  }
  if (raw.length > MAX_SESSION_TOKEN_LENGTH) {
    return { ok: false, reason: "مقدار کوکی نشست نامعتبر است." };
  }
  return { ok: true, token: raw };
}
