/**
 * K6.12 — انتخابِ «الگوی رندر» برای بلوک، از روی schema/data.
 *
 * ## چرا این فایل جدا است
 *
 * `BlockRenderer.tsx` یک کامپوننت (JSX) است و در `node --test` ترجمه نمی‌شود؛
 * پس منطقِ «این بلوک با کدام الگو رندر شود؟» اینجا زندگی می‌کند تا واقعاً
 * آزمون شود. رندرر فقط `switch` روی برچسبِ برگشتی می‌زند.
 *
 * ## مدل
 *
 * افزونه هیچ React/JS نمی‌فرستد (قید ECO1 بند ۱). بلوک فقط `type` + `data`
 * + یک `schema` اعلانی است. هسته با ~۱۱ **الگوی عمومی** آن را رندر می‌کند.
 * نگاشتِ پیش‌فرض از روی `type` است؛ اگر `schema` صریحاً `x-pattern` بدهد،
 * همان برنده است (پلاگین اجازه دارد بگوید «من مثل list رندر شو»).
 *
 * هیچ‌وقت throw نمی‌کند: یک بلوکِ بد نباید کل صفحه را بیندازد.
 */

/**
 * ۱۱ الگوی عمومی رندر. این‌ها ثابت‌های هسته‌اند؛ بلوک‌های افزونه به یکی از
 * این‌ها نگاشت می‌شوند و هرگز کد خودشان را نمی‌فرستند.
 */
export const BLOCK_PATTERNS = Object.freeze([
  "hero",
  "image",
  "grid",
  "list",
  "gallery",
  "form",
  "rich-text",
  "embed",
  "faq",
  "quote",
  "cta",
] as const);

export type BlockPattern = (typeof BLOCK_PATTERNS)[number];

const PATTERN_SET = new Set<string>(BLOCK_PATTERNS);

/**
 * نگاشتِ **نام‌های شناخته‌شده** به الگو. `type`هایی که اینجا نیستند اما
 * `x-pattern` هم ندارند، به الگوی `default` (کلیدواژهٔ متن) می‌روند.
 *
 * نکته: نگاشتِ `hero`/`image`/... صرفاً برای «هم‌نام‌ها» است — پایهٔ الگو
 * همان `type` است. جدولِ زیر فقط اسم‌های مترادف (مثلاً `video`→`embed`) را
 * می‌گیرد.
 */
const TYPE_TO_PATTERN: Readonly<Record<string, BlockPattern>> = Object.freeze({
  hero: "hero",
  image: "image",
  grid: "grid",
  list: "list",
  gallery: "gallery",
  form: "form",
  "rich-text": "rich-text",
  richtext: "rich-text",
  text: "rich-text",
  embed: "embed",
  video: "embed",
  faq: "faq",
  quote: "quote",
  cta: "cta",
  // بلوک‌های هسته که به الگوهای عمومی نگاشت می‌شوند (سازگاری حفظ می‌شود):
  "contact-form": "form",
  contact: "form",
});

/**
 * کلیدواژه‌ای که در `schema.properties['x-pattern'].default` نوشته می‌شود تا
 * یک `type` افزونه صریحاً به یک الگو نگاشت شود.
 */
export const PATTERN_SCHEMA_KEY = "x-pattern";

/** شکلِ کمینهٔ schema که این تابع می‌فهمد (نه `BlockSchema` کامل — بدون import). */
type SchemaLike = {
  properties?: Record<string, { default?: unknown; enum?: unknown }> | null;
} | null;

function isPattern(v: unknown): v is BlockPattern {
  return typeof v === "string" && PATTERN_SET.has(v);
}

/**
 * الگویِ صریحِ اعلام‌شده در schema (اگر معتبر باشد).
 * هم `default` و هم تک‌عضوی `enum` را می‌پذیرد تا نویسندهٔ افزونه راحت باشد.
 */
export function declaredPattern(schema: SchemaLike): BlockPattern | null {
  const spec = schema?.properties?.[PATTERN_SCHEMA_KEY];
  if (!spec) return null;
  if (isPattern(spec.default)) return spec.default;
  if (Array.isArray(spec.enum) && spec.enum.length === 1 && isPattern(spec.enum[0])) {
    return spec.enum[0];
  }
  return null;
}

/**
 * انتخاب الگو برای یک بلوک.
 *
 * ترتیب تصمیم:
 *  ۱. `x-pattern` صریحِ schema (اگر معتبر باشد) — افزونه خودش گفته.
 *  ۲. `type` معلوم در جدولِ نگاشت.
 *  ۳. `type`ی که خودش دقیقاً یکی از ۱۱ الگوست.
 *  ۴. `default` — هرگز بلاکِ کل صفحه نمی‌شود.
 *
 * @param type   نوع بلوک (`hero`, `price_table`, …). غیررشته ⇒ `default`.
 * @param schema schema اعلانی بلوک (اختیاری). ممکن است ناقص/خراب باشد.
 */
export function selectBlockPattern(type: unknown, schema: SchemaLike = null): BlockPattern | "default" {
  const explicit = declaredPattern(schema);
  if (explicit) return explicit;

  if (typeof type === "string" && type) {
    // `Object.hasOwn` نه `TYPE_TO_PATTERN[type]`: جست‌وجوی ساده `constructor`
    // و `__proto__` را «پیدا» می‌کند و رشتهٔ تابعی را به‌عنوان الگو برمی‌گرداند.
    if (Object.hasOwn(TYPE_TO_PATTERN, type)) return TYPE_TO_PATTERN[type];
    if (isPattern(type)) return type;
  }

  return "default";
}
