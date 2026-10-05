/**
 * K6.4 — سیاست «ویجتِ ناشناس» برای `ChromeWidget` (هدر/فوتر سایت عمومی).
 *
 * چرا اینجا و نه داخل کامپوننت:
 *  - تصمیمِ «این `type` ویجتِ هسته است یا نه» **واژگان** است، نه ویو. واژگان باید
 *    یک‌جا نوشته شود تا لاگ، اعلانِ دیداری و تست، سه روایتِ جدا از هم نداشته باشند.
 *  - این فایل عمداً هیچ JSX و هیچ importِ React ندارد ⇒ `node --test` می‌تواند
 *    بی‌هیچ رندرری آن را بخواند و آزمون‌ها واقعاً اجرا شوند (glob اسکریپت تست
 *    فقط فایل‌های `test.ts` را می‌گیرد و Next/Node هیچ‌کدام JSX را در تست
 *    ترجمه نمی‌کنند).
 *
 * ── چرا این نقطهٔ اتصال «شکل» ندارد (و چرا این فایل جای آن را پر نمی‌کند) ──
 *
 * قراردادِ حاکم (خواندنی، از سمت ما تغییرناپذیر):
 * `PluginPackageContract.php:314` و `:340` ⇒ `site.header_widget` و
 * `site.footer_widget`، هر دو با `status => 'declared_only'` و
 * `openness => 'schema_defined'` — ولی با
 * `'schema' => ['fields' => [], …]` و `'open_schema' => false`.
 *
 * سه نتیجهٔ محض از همین سه خط، که در `widget-type.test.ts` قفل شده‌اند:
 *
 *  1. **هیچ اعلانِ قانونی وجود ندارد.** `validateDeclaration` وقتی
 *     `fields === [] && open_schema === false` باشد، خطای `no_schema` می‌دهد و
 *     برمی‌گردد (`PluginPackageContract.php:829-837`). یعنی این نقطه فقط
 *     «بی‌اثر» نیست — اعلامش **نصب را رد می‌کند**. (نشانگرِ همین تناقض در خودِ
 *     قرارداد: `example_ok` هر دو نقطه، `PluginPackageContract.php:326` و
 *     `:352`، دقیقاً همان چیزی است که خطا می‌دهد.)
 *  2. **مصرف‌کنندهٔ runtime وجود ندارد.** رشتهٔ `site.header_widget` /
 *     `site.footer_widget` در کل بک‌اند فقط در خودِ قرارداد و در تستی که همین
 *     `declared_only` را قفل می‌کند دیده می‌شود.
 *  3. **کانالِ واقعی، کلیدِ دیگری در مانیفست است.** `ManifestRegistry` ویجت‌های
 *     افزونه را از `manifest.widgets.header` / `manifest.widgets.footer`
 *     می‌خواند (`ManifestRegistry.php:175-206`) — نه از
 *     `panel.extensions[].point = 'site.header_widget'`. این کانال *واقعاً* زنده
 *     است: `LayoutController.php:53-56` آن `type` را می‌پذیرد، `:138-147`
 *     `href`هایش را می‌سنجد، و پنل با `SchemaForm` تنظیمش می‌کند.
 *
 * ولی گامِ آخر زنده نیست: `ChromeWidget` یک `switch` بسته است و هر `type`
 * ناشناسی `null` برمی‌گرداند. پس وضعیتِ واقعیِ امروز این است:
 *
 *     افزونه می‌تواند ویجت را در پنل بگذارد و ذخیره کند ⇒ سایت عمومی هیچ چیزی
 *     رندر نمی‌کند، بی‌صدا.
 *
 * آن سکوت همان دروغی است که K6.10 برای کارتِ ویجتِ داشبورد حذف کرد. پس کارِ
 * درست اینجا ساختنِ **جای رندر** نیست (ساختنش یعنی یا رندرِ هیچی، یا اجازهٔ
 * HTML/JS افزونه در سایت عمومی — که قرارداد خودش در `FORBIDDEN`
 * `PluginPackageContract.php:118-121` banned کرده). کارِ درست اعلامِ صریحِ
 * همین وضعیت است: لاگِ همیشگی + اعلانِ دیداری برای اپراتور.
 */

// ECO2 — import نسبیِ `.ts` (بدون alias) چون این فایل در مسیر `node --test`
// هم بار می‌شود (`widget-type.test.ts`) و alias آنجا resolve نمی‌شود.
import { publicT, type PublicLocale } from "./i18n/public/index.ts";

/**
 * واژگانِ ویجت‌های هسته. تنها منبع حقیقتِ «چه چیزی ویجتِ هسته است».
 *
 * ⚠️ این فهرست باید **دقیقاً** برابرِ برچسب‌های `case` در `switch` داخل
 * `Chrome.tsx` بماند. آزمون `widget-type.test.ts` همین را از روی متنِ فایل
 * می‌خواند و مقایسه می‌کند، پس کوتاه‌آمدنِ یکی از دو طرف، تست را می‌اندازد —
 * نه چشمِ بازبین را. همین قفل، `registry-drift` را عملاً ناممکن می‌کند.
 *
 * یک `switch`، نه دو تا: `ChromeWidget` هر دو ناحیه را با یک `switch` رندر
 * می‌کند، پس واژگان هم یکی است. اگر روزی به دو `switch` شکسته شد، واژگان هم
 * باید دو تا شود — وگرنه تست عمداً می‌افتد.
 *
 * ترتیب با `config/widgets.php` هسته هم‌خوان است (هسته: `header` بعد `footer`)
 * ولی روی آن قفل نیست؛ قفل روی *مجموعه* است، نه ترتیب.
 */
export const CORE_CHROME_WIDGET_TYPES: readonly string[] = Object.freeze([
  // header
  "logo",
  "nav",
  "search",
  "cta",
  "socials",
  // footer
  "about",
  "links",
  "contact",
  "newsletter",
  "copyright",
]);

// `Set` نه شیء قابل‌دستکاری‌پس‌اندازی است و نه `CORE_CHROME_WIDGET_TYPES[type]`؛
// پس `constructor` / `__proto__` / `toString` که هرگز `case` ندارند، از
// درِ شاخهٔ `default` هم رد نمی‌شوند و به آبجکت `Object.prototype` نمی‌رسند.
const CORE = new Set<string>(CORE_CHROME_WIDGET_TYPES);

/** ناحیهٔ کروم — فقط برای متنِ گزارش، نه برای تصمیمِ رندر. */
export type ChromeArea = "header" | "footer";

/** آیا `type` داده‌شده ویجتِ هسته است؟ (غیررشته هم می‌تواند بیاید ⇒ `false`.) */
export function isCoreChromeWidgetType(type: unknown): boolean {
  return typeof type === "string" && CORE.has(type);
}

/** بیشترین طولی که `type` می‌تواند در لاگ و در DOM داشته باشد. */
export const UNKNOWN_WIDGET_TYPE_MAX = 64;

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
 *     جست‌وجوی بعدی برای «کدام ویجت بود» را گمراه می‌کند ⇒ نویسه‌های کنترلی
 *     (از جمله `\n` و `\t`) حذف می‌شوند.
 *  2. **جعلِ جهتِ متن** — `U+202E`/`U+2066` می‌توانند ادامهٔ لاگ را وارونه
 *     کنند و چیزی که نیست نشان دهند ⇒ بی‌دوجهه‌کننده‌ها هم حذف می‌شوند.
 *  3. **رشدِ بی‌حد** — یک `type` ۱۰۰ کیلوبایتی، DOM صفحهٔ عمومی را متورم و صفحهٔ
 *     لاگ را غیرقابل‌جست‌وجو می‌کند ⇒ سقف ۶۴ نویسه.
 *
 * این تابع **هرگز throw نمی‌کند**: مسیرِ رندرِ صفحهٔ عمومی نباید به آن حساس باشد.
 */
export function describeUnknownWidgetType(type: unknown): string {
  const raw = typeof type === "string" ? type : "";
  const cleaned = raw.replace(STRIP_CHARS, "");
  return cleaned.length > UNKNOWN_WIDGET_TYPE_MAX
    ? `${cleaned.slice(0, UNKNOWN_WIDGET_TYPE_MAX)}…`
    : cleaned;
}

/** دلیلِ رسیدن به شاخهٔ `default`. */
export type UnknownWidgetReason =
  /** رجیستری می‌گوید هسته است ولی `switch` نخورد ⇒ لغزشِ خودِ ما. */
  | "registry-drift"
  /**
   * کانالِ زندهٔ `manifest.widgets.*` این `type` را داده ولی `switch` نخورده ⇒
   * نه باگ است و نه تقصیر محتوا: **نتیجهٔ قطعیِ نبودِ primitive** در
   * قرارداد. ویجت ذخیره و پیکربندی می‌شود ولی هیچ‌جا رندر نمی‌شود.
   */
  | "unrenderable";

/**
 * یک جملهٔ لاگ برای ویجتی که رندر نشد.
 *
 * هر دو شاخهٔ `warn` و `error` از **همین** متن می‌آید ⇒ لاگ و رفتار نمی‌توانند
 * از هم جدا بیفتند. `type` از `describeUnknownWidgetType` می‌گذرد، پس هرچه هم
 * نویسهٔ کنترلی داشته باشد، یک خطِ لاگ را یکی می‌سازد.
 *
 * متنِ شاخهٔ `unrenderable` عمداً علتِ **ساختاری** را می‌گوید، نه فقط «ناشناس»:
 * بدون این جمله، اپراتور یک باگِ رندر می‌بیند و دنبال `Chrome.tsx` می‌گردد،
 * در حالی که ریشه در قرارداد است و با افزودن `case` حل نمی‌شود.
 */
export function unknownWidgetWarning(
  type: unknown,
  reason: UnknownWidgetReason = "unrenderable",
): string {
  const named = describeUnknownWidgetType(type) || "(empty)";
  return reason === "registry-drift"
    ? `[site] chrome widget type "${named}" is in CORE_CHROME_WIDGET_TYPES but the ChromeWidget switch did not match it — registry drift; rendered as unknown.`
    : `[site] chrome widget type "${named}" has no case in the ChromeWidget switch — a plugin/theme widget declared in manifest.widgets.{header,footer} renders nothing. site.header_widget / site.footer_widget cannot change this: the contract declares them with an empty schema, so any declaration is a validation error.`;
}

/**
 * ECO2 — اعلانِ دیداری به زبانِ سایتِ عمومی. در پنل/پیش‌نمایش که زبان از URL
 * نمی‌آید، فراخوان با پیش‌فرضِ `fa` رفتار قبلی را می‌دهد.
 */
export function unknownWidgetNotice(locale: PublicLocale, type: unknown): string {
  const named = describeUnknownWidgetType(type) || publicT(locale, "widget.emptyName");
  return publicT(locale, "widget.unknownNotice", { name: named });
}

/** متنِ فارسیِ اعلانِ دیداری (سازگاریِ عقب‌رو + پیش‌فرضِ زبان پایه). */
export function unknownWidgetNoticeFa(type: unknown): string {
  return unknownWidgetNotice("fa", type);
}

/**
 * آیا اعلانِ دیداریِ ویجتِ ناشناس باید رندر شود؟
 *
 * پیش‌فرض: هر چیزی که production نباشد (dev، preview، تست) روشن است. در
 * production، روشن‌کردنِ عمدی با `CHROME_WIDGET_DIAGNOSTICS=1` ممکن.
 *
 * ⚠️ این متغیر **هرگز** `NEXT_PUBLIC_` نشود: هم به بستهٔ مرورگر می‌رود و هم یک
 * اهرمِ دور زدنِ کنترل می‌شود.
 *
 * نکتهٔ فرق با `block-type.ts`: آن یکی فقط کامپوننتِ **سرور** را تغذیه می‌کند،
 * ولی `ChromeWidget` را هم صفحات عمومی (سرور) رندر می‌کنند و هم پنل (کلاینت).
 * در باندلِ کلاینت، متغیرِ غیر `NEXT_PUBLIC_` مقدار ندارد ⇒ این دروازه فقط
 * سایتِ عمومیِ رندرشده در سرور را کنترل می‌کند. پنل به آن تکیه نمی‌کند: آنجا
 * `diagnostics` **صریح** پاس داده می‌شود (مثل `PageEditor` برای `BlockRenderer`)،
 * پس اعلان در production هم برای اپراتور دیده می‌شود.
 *
 * ورودی، `env` است نه مستقیم `process.env` تا آزمون بتواند بدون دست‌زدن به
 * وضعیتِ فرایند، هر حالتی را بسازد.
 */
export function chromeWidgetDiagnosticsEnabled(
  env: Record<string, string | undefined> = process.env,
): boolean {
  return env.NODE_ENV !== "production" || env.CHROME_WIDGET_DIAGNOSTICS === "1";
}
