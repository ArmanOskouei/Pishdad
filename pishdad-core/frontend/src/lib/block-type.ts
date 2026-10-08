/**
 * K6.3 — سیاست «بلوکِ ناشناس» برای `BlockRenderer`.
 *
 * چرا اینجا و نه داخل کامپوننت:
 *  - تصمیمِ «این `type` بلوکِ هسته است یا نه» **واژگان** است، نه ویو. واژگان باید
 *    یک‌جا نوشته شود تا لاگ، اعلانِ دیداری و تست، سه روایتِ جدا از هم نداشته باشند.
 *  - این فایل عمداً هیچ JSX و هیچ importِ React ندارد ⇒ `node --test` می‌تواند
 *    بی‌هیچ رندرری آن را بخواند و آزمون‌ها واقعاً اجرا شوند (glob اسکریپت تست
 *    فقط فایل‌های `test.ts` را می‌گیرد و Next/Node هیچ‌کدام JSX را در تست
 *    ترجمه نمی‌کنند).
 *
 * قراردادِ حاکم (خواندنی، از سمت ما تغییرناپذیر):
 * `PluginPackageContract.php:401` ⇒ `site.block_type` با
 * `status => 'declared_only'` و `openness => 'open_vocabulary'`.
 * یعنی افزونه **می‌تواند** نام دلخواه اعلام کند، ولی بدنهٔ آن به رندر وصل
 * **نیست**؛ پس از پیش‌بینی‌ناپذیر بودن، «ناشناس» یک حالتِ مشروع است — نه
 * استثنا.
 */

// ECO2 — import نسبیِ `.ts` (بدون alias) چون این فایل در مسیر `node --test`
// هم بار می‌شود (`block-type.test.ts`) و alias آنجا resolve نمی‌شود.
import { publicT, type PublicLocale } from "./i18n/public/index.ts";

/**
 * واژگانِ بلوک‌های هسته. تنها منبع حقیقتِ «چه چیزی بلوکِ هسته است».
 *
 * ⚠️ این فهرست باید **دقیقاً** برابرِ برچسب‌های `case` در `switch` داخل
 * `BlockRenderer.tsx` بماند. آزمون `block-type.test.ts` همین را از روی متنِ
 * فایل می‌خواند و مقایسه می‌کند، پس کوتاه‌آمدنِ یکی از دو طرف، تست را
 * می‌اندازد — نه چشمِ بازبین را.
 *
 * `contact` نام مستقل ندارد: با `contact-form` یک `case` مشترک دارند، ولی
 * `PageEditor.tsx:564` هر دو را می‌شناسد، پس در واژگان هم هر دو می‌آید.
 */
export const BUILTIN_BLOCK_TYPES: readonly string[] = Object.freeze([
  "hero",
  "text",
  "image",
  "cta",
  "gallery",
  "quote",
  "video",
  "faq",
  "form",
  "contact-form",
  "contact",
]);

// `Set` نه شیء قابل‌دسترسی‌پس‌اندازی است و نه `BUILTIN_BLOCK_TYPES[type]`؛
// پس `constructor` / `__proto__` / `toString` که هرگز `case` ندارند، از
// درِ شاخهٔ `default` هم رد نمی‌شوند و به آبجکت `Object.prototype` نمی‌رسند.
const BUILTIN = new Set<string>(BUILTIN_BLOCK_TYPES);

/** آیا `type` داده‌شده بلوکِ هسته است؟ (غیررشته هم می‌تواند بیاید ⇒ `false`.) */
export function isBuiltinBlockType(type: unknown): boolean {
  return typeof type === "string" && BUILTIN.has(type);
}

/** بیشترین طولی که `type` می‌تواند در لاگ و در DOM داشته باشد. */
export const UNKNOWN_TYPE_MAX = 64;

/**
 * نویسه‌هایی که از `type` بیرون نمی‌آیند: کنترلی‌ها (تزریق خطِ جعلی در لاگ) و
 * بی‌دوجهه‌کننده‌های یونیکد (جعلِ جهتِ متن — «Trojan Source»).
 */
const STRIP_CHARS = /[\u0000-\u001f\u007f\u200e\u200f\u202a-\u202e\u2066-\u2069]/g;

/**
 * `type` ناشناس را برای نمایش/لاگ **بی‌خطر** می‌کند.
 *
 * `type` دادهٔ نویسنده/افزونه است، نه ثابتِ ما، و مستقیم می‌رود داخل لاگ و
 * داخل صفتِ `data-` در DOM. سه خطر مشخص دارد که هر سه همین‌جا بسته می‌شوند:
 *  1. **تزریق لاگ** — یک `type` چندخطی در ترمینال یک خط جعلی می‌سازد و
 *     جست‌وجوی بعدی برای «کدام بلوک بود» را گمراه می‌کند ⇒ نویسه‌های کنترلی
 *     (از جمله `\n` و `\t`) حذف می‌شوند.
 *  2. **جعلِ جهتِ متن** — `U+202E`/`U+2066` می‌توانند ادامهٔ لاگ را وارونه
 *     کنند و چیزی را که نیست نشان دهند ⇒ بی‌دوجهه‌کننده‌ها هم حذف می‌شوند.
 *  3. **رشدِ بی‌حد** — یک `type` ۱۰۰ کیلوبایتی، DOM صفحهٔ عمومی را متورم و صفحهٔ
 *     لاگ را غیرقابل‌جست‌وجو می‌کند ⇒ سقف ۶۴ نویسه.
 *
 * این تابع **هرگز throw نمی‌کند**: مسیرِ رندرِ صفحهٔ عمومی نباید به آن حساس باشد.
 */
export function describeUnknownType(type: unknown): string {
  const raw = typeof type === "string" ? type : "";
  const cleaned = raw.replace(STRIP_CHARS, "");
  return cleaned.length > UNKNOWN_TYPE_MAX ? `${cleaned.slice(0, UNKNOWN_TYPE_MAX)}…` : cleaned;
}

/** دلیلِ رسیدن به شاخهٔ `default`. */
export type UnknownBlockReason =
  /** رجیستری می‌گوید هسته است ولی `switch` نخورد ⇒ لغزشِ خودِ ما. */
  | "registry-drift"
  /** هیچ بلوکِ هسته‌ای این `type` را ندارد ⇒ حالتِ مشروعِ `declared_only`. */
  | "unrecognised";

/**
 * یک جملهٔ لاگ برای بلوکی که رندر نشد.
 *
 * هر دو شاخهٔ `warn` و `error` از **همین** متن می‌آیند ⇒ لاگ و رفتار نمی‌توانند
 * از هم جدا بیفتند. `type` از `describeUnknownType` می‌گذرد، پس هرچه هم
 * نویسهٔ کنترلی داشته باشد، یک خطِ لاگ را یکی می‌سازد.
 */
export function unknownBlockWarning(type: unknown, reason: UnknownBlockReason = "unrecognised"): string {
  const named = describeUnknownType(type) || "(empty)";
  return reason === "registry-drift"
    ? `[site] block type "${named}" is in BUILTIN_BLOCK_TYPES but the BlockRenderer switch did not match it — registry drift; rendered as unknown.`
    : `[site] unknown block type "${named}" — no built-in block matches it. site.block_type is declared_only, so a plugin's declared block type renders nothing.`;
}

/**
 * ECO2 — اعلانِ دیداری به زبانِ سایتِ عمومی (فقط وقتی تشخیص روشن است رندر
 * می‌شود). زبان از URL می‌آید و به‌صورت prop به رندرر می‌رسد.
 */
export function unknownBlockNotice(locale: PublicLocale, type: unknown): string {
  const named = describeUnknownType(type) || publicT(locale, "block.emptyName");
  return publicT(locale, "block.unknownNotice", { name: named });
}

/** متنِ فارسیِ اعلانِ دیداری (سازگاریِ عقب‌رو + پیش‌فرضِ زبان پایه). */
export function unknownBlockNoticeFa(type: unknown): string {
  return unknownBlockNotice("fa", type);
}

/**
 * آیا اعلانِ دیداریِ بلوکِ ناشناس باید رندر شود؟
 *
 * پیش‌فرض: هر چیزی که production نباشد (dev، preview، تست) روشن است. در
 * production، روشن‌کردنِ عمدی با `BLOCK_RENDER_DIAGNOSTICS=1` ممکن است.
 *
 * ⚠️ این متغیر **هرگز** `NEXT_PUBLIC_` نشود: هم به بستهٔ مرورگر می‌رود و هم یک
 * اهرمِ دور زدنِ کنترل می‌شود. سمتِ سرور خوانده می‌شود (`BlockRenderer`
 * کامپوننتِ سرور است، نه `use client`).
 *
 * ورودی، `env` است نه مستقیم `process.env` تا آزمون بتواند بدون دست‌زدن به
 * وضعیتِ فرایند، هر حالتی را بسازد.
 */
export function blockDiagnosticsEnabled(
  env: Record<string, string | undefined> = process.env,
): boolean {
  return env.NODE_ENV !== "production" || env.BLOCK_RENDER_DIAGNOSTICS === "1";
}
