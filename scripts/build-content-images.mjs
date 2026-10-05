#!/usr/bin/env node
/**
 * تولید تصاویر محتوای «پیشداد» به‌صورت SVG محلی (S1.8).
 *
 * ## چرا SVG
 *
 * تصویر محتوا باید دو چیز باشد: سبک و بدون CDN. SVG هر دو را با هم
 * می‌دهد — چند کیلوبایت، در هر رزولوشنی تیز، و بدون هیچ درخواست شبکه.
 *
 * PNG برای لوگو لازم بود (نشانِ رنگی و آیکون PWA) ولی برای نمودارهای
 * مفهومیِ محتوا SVG انتخاب درست است.
 *
 * ## چرا دست‌ساز نه AI
 *
 * این‌ها نمودارِ مفهومی‌اند (هسته + افزونه، جریان محتوا، معماری)، نه
 * تصویر تزئینی. دست‌سازی یعنی متن، اعداد و جهت راست‌به‌چپ دقیقاً همان
 * چیزی است که مستندات می‌گویند — بدون اینکه چیزی hallucinate شود.
 *
 * ## اجرا
 *
 *     node scripts/build-content-images.mjs
 *
 * خروجی: `cms-core/frontend/public/images/content/*.svg`
 */

import { existsSync, mkdirSync, writeFileSync } from "node:fs";
import { dirname, resolve, join } from "node:path";
import { fileURLToPath } from "node:url";

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, "..");
const OUT = join(ROOT, "cms-core", "frontend", "public", "images", "content");

/**
 * پالت — هم‌خانواده با `Logo/pishdad-lockup-final*.svg` و تم داشبورد.
 *
 * ⚠️ رنگ‌ها `currentColor` و متغیرهای CSS هستند، نه مقدار ثابت. چون:
 *
 *  • سایت تمِ تیره دارد ولی اولین render تصویر را با پس‌زمینهٔ سفید
 *    نشان داد — یک لکهٔ روشن وسط صفحهٔ تیره.
 *  • اگر رنگ ثابت باشد، با هر تغییرِ تم دوباره باید تصویر را
 *    بازتولید کرد.
 *
 * SVG وقتی داخل `<img>` رندر می‌شود **CSS بیرونی به آن نمی‌رسد**، پس
 * این متغیرها باید داخل خودِ فایل تعریف شوند و رنگ متن هم
 * `currentColor` باشد تا از بیرون ارث ببرد. برای جاهایی که `<img>`
 * نیست (inline) هم درست کار می‌کند.
*
 * ## رنگ — چرا یک کارتِ تیرهٔ خودبسنده
 *
 * نسخهٔ اول روی سطح روشن (`panel` خاکستریِ روشن) بود و در تم تیرهٔ پیش‌فرض
 * مثل «لکهٔ روشن» وسط صفحه می‌نشست.
 *
 * وسوسهٔ بعدی `currentColor` بود، ولی **آزمایش نشان داد کار نمی‌کند**:
 * `currentColor` داخل SVGای که با `<img>` بارگذاری می‌شود ارث نمی‌برد، چون
 * آن SVG یک سندِ جداگانه است. با `color: #f00` روی `<img>` رنگِ مستطیل
 * `rgb(0,0,0)` درآمد، نه قرمز. پس `currentColor` و `var(--…)` هر دو
 * بی‌فایده‌اند و فقط متن را نامرئی می‌کنند.
 *
 * بنابراین تصویر **خودبسنده** است: یک کارتِ تیره با متنِ روشن، دقیقاً مثل
 * هر تصویرِ دیگری در صفحه. روی تم تیره با پس‌زمینه یکی می‌شود و «لکه» از
 * بین می‌رود؛ روی تم روشن هم مثل یک تصویرِ معمولی خوانا می‌ماند.
 *
 * اگر بعداً واقعاً رنگِ متغیر بخواهی، تنها راه درست **درون‌خطی کردن** SVG در
 * DOM است (آن‌وقت `var(--text)` ارث می‌برد) — که نیازمند تولید JSX از
 * همین فایل است و تصمیمِ معماریِ جداگانه‌ای است.
 *
 * رنگ‌ها برای خوانایی روی سطح تیره انتخاب شده‌اند: متن روشن، خطوط میانی،
 * و لهجه‌هایی که روی هر دو تم کنتراست دارند.
 */
const C = {
  panel: "#101423",
  ink: "#e8ecf6",
  muted: "#9aa4bd",
  line: "#2b3348",
  brand: "#7c8cff",
  brandSoft: "#232b45",
  accent: "#38bdf8",
  accentSoft: "#1c2b3d",
  warn: "#fbbf24",
  good: "#34d399",
  onAccent: "#0b0f19",
};

/**
 * متن فارسی — SVG بدون فونت، پس به فونتِ سیستم تکیه می‌کند.
 */
const font = "Vazirmatn, 'Segoe UI', Tahoma, sans-serif";

/**
 * ⚠️ `direction` را روی ریشهٔ `<svg>` می‌گذاریم چون SVG ژرفا از `dir`
 * والد HTML پیروی **نمی‌کند**. بدون این، متن فارسی چپ‌چین رندر می‌شود و
 * نمودار برعکس به‌نظر می‌رسد — همان اشتباهی که در اولین render دیدم.
 *
 * `text-anchor="start"` هم باید صریح باشد چون در RTL یعنی «چسبیده به شروع
 * خط»، نه «سمت چپ صفحه».
 */
/**
 * ⚠️ نکتهٔ ظریفِ RTL در SVG
 *
 * در `direction="rtl"` مقدار `text-anchor` **معکوس** می‌شود:
 *
 *   • `start` ⇒ سمت **راست**
 *   • `end`   ⇒ سمت **چپ**
 *
 * پس `text-anchor="end"` با `x` روی لبهٔ راستِ قاب، متن را به بیرون
 * قاب می‌اندازد — همان چیزی که در اولین render دیدم.
 *
 * قاعدهٔ درست این است: در RTL همیشه `start` (یا مقدارِ خالی) استفاده کن
 * و `x` را روی لبهٔ راست بگذار.
 *
 * ## چرا رنگ‌ها ثابت‌اند
 *
 * SVGای که با `<img>` رندر می‌شود یک سندِ جداگانه است، پس CSS بیرونی را
 * نمی‌بیند: نه `var(--text)` کار می‌کند و نه `currentColor` (هر دو آزموده
 * شدند و سیاه برمی‌گردند). پس نمودار خودبسنده است و پالتش اینجا می‌ماند.
 * جزئیات در توضیح `C` بالا آمده.
 */
const svg = (w, h, body, title) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${w} ${h}" width="${w}" height="${h}" direction="rtl" role="img" aria-label="${title}">
  <title>${title}</title>
  <style>
    text { font-family: ${font}; fill: ${C.ink}; direction: rtl; }
    .t-lg { font-size: 26px; font-weight: 700; }
    .t    { font-size: 15px; }
    .t-sm { font-size: 13px; fill: ${C.muted}; }
    .t-xs { font-size: 11px; fill: ${C.muted}; }
    /* کارتِ خودبسنده: پس‌زمینهٔ ماتِ تیره + خطِ میانی.
       روی تم تیره با صفحه یکی می‌شود، روی تم روشن مثل یک تصویر می‌ماند. */
    .box  { fill: ${C.panel}; stroke: ${C.line}; }
    .accent   { fill: ${C.brand}; }
    .accent-s { fill: ${C.brandSoft}; }
    .cyan     { fill: ${C.accent}; }
    .good    { fill: ${C.good}; }
    .cyan-s   { fill: ${C.accentSoft}; }
    .rule { fill: none; stroke: ${C.muted}; }
  </style>
${body}
</svg>
`;

/** کمک‌کار: متنِ راست‌چین با فاصلهٔ امن از لبهٔ قاب. */
const pad = 24;

/* ── ۱. هسته و افزونه‌ها ─────────────────────────────────────────────────── */

/**
 * ⚠️ چیدمان از راست شروع می‌شود: در RTL خواننده از راست می‌خواند، پس
 * «هسته» باید سمت راست باشد و افزونه‌ها به سمت چپ از آن جدا شوند.
 * نسخهٔ اول برعکس بود و نمودار چپ‌به‌راست خوانده می‌شد.
 */
const architecture = svg(
  960, 560,
  `  <text class="t-lg" x="920" y="60" text-anchor="start">افزونه‌پذیری</text>
  <text class="t-sm" x="920" y="94" text-anchor="start">هسته یک بار نصب می‌شود؛ بقیه افزونه است</text>

  <rect class="box" x="560" y="140" width="360" height="230" rx="16"/>
  <rect class="accent-s" x="900" y="140" width="20" height="230" rx="3"/>
  <text class="t-lg" x="880" y="196" text-anchor="start">هستهٔ خام</text>
  <text class="t-sm" x="880" y="232" text-anchor="start">صفحه و بلوک</text>
  <text class="t-sm" x="880" y="260" text-anchor="start">رسانه و نقش</text>
  <text class="t-sm" x="880" y="288" text-anchor="start">اعلان و 2FA</text>
  <text class="t-sm" x="880" y="316" text-anchor="start">کش و ISR</text>
  <text class="t-xs" x="880" y="344" text-anchor="start">بدون دادهٔ نمونه</text>
  <text class="t-xs" x="880" y="360" text-anchor="start">بدون افزونهٔ فعال</text>

  <rect class="box" x="40" y="140" width="440" height="106" rx="14"/>
  <rect class="accent" x="466" y="140" width="14" height="106" rx="3"/>
  <text class="t" x="440" y="182" text-anchor="start">افزونهٔ فروشگاه</text>
  <text class="t-xs" x="440" y="212" text-anchor="start">سبد، سفارش، پرداخت</text>

  <rect class="box" x="40" y="276" width="440" height="106" rx="14"/>
  <rect class="cyan" x="466" y="276" width="14" height="106" rx="3"/>
  <text class="t" x="440" y="318" text-anchor="start">افزونهٔ چندنصبی</text>
  <text class="t-xs" x="440" y="348" text-anchor="start">مرکز، مشتری، صورتحساب</text>

  <rect class="box" x="40" y="412" width="440" height="106" rx="14"/>
  <rect class="good" x="466" y="412" width="14" height="106" rx="3"/>
  <text class="t" x="440" y="454" text-anchor="start">افزونهٔ شما</text>
  <text class="t-xs" x="440" y="484" text-anchor="start">امضای لاراول + جدول اختصاصی</text>

  <path d="M560 255 L480 193 M560 255 L480 329 M560 255 L480 465"
        class="rule" stroke-width="2" stroke-dasharray="5 5"/>`,
  "معماری هسته و افزونه‌های پیشداد",
);

/* ── ۲. جریان انتشار محتوا ──────────────────────────────────────────────── */

/**
 * ⚠️ جریان در RTL از راست به چپ است: «پیش‌نویس» سمت راست شروع می‌شود
 * و «سایت زنده» سمت چپ. نسخهٔ اول برعکس بود.
 */
const publishFlow = svg(
  960, 320,
  `  <text class="t-lg" x="920" y="56" text-anchor="start">جریان انتشار</text>
  <text class="t-sm" x="920" y="84" text-anchor="start">هر تغییر یک تراکنش است: بازبینی و بازگشت کامل</text>

  <g>
    <rect class="box" x="740" y="130" width="180" height="80" rx="12"/>
    <text class="t" x="830" y="164" text-anchor="middle">پیش‌نویس</text>
    <text class="t-xs" x="830" y="188" text-anchor="middle">ویرایش در پنل</text>
  </g>
  <g>
    <rect class="box" x="490" y="130" width="180" height="80" rx="12"/>
    <text class="t" x="580" y="164" text-anchor="middle">بازبینی</text>
    <text class="t-xs" x="580" y="188" text-anchor="middle">نسخهٔ تازه ساخته می‌شود</text>
  </g>
  <g>
    <rect class="accent-s" x="240" y="130" width="180" height="80" rx="12"/>
    <text class="t" x="330" y="164" text-anchor="middle">انتشار</text>
    <text class="t-xs" x="330" y="188" text-anchor="middle">اشاره‌گر جابه‌جا می‌شود</text>
  </g>
  <g>
    <rect class="box" x="40" y="130" width="130" height="80" rx="12"/>
    <text class="t" x="105" y="164" text-anchor="middle">سایت زنده</text>
    <text class="t-xs" x="105" y="188" text-anchor="middle">ISR</text>
  </g>

  <path d="M735 170 L675 170" class="rule" stroke-width="2" marker-end="url(#a)"/>
  <path d="M485 170 L425 170" class="rule" stroke-width="2" marker-end="url(#a)"/>
  <path d="M235 170 L175 170" class="rule" stroke-width="2" marker-end="url(#a)"/>

  <path d="M330 215 L330 250 L830 250 L830 215" stroke="${C.warn}" stroke-width="2"
        fill="none" stroke-dasharray="6 4" marker-end="url(#b)"/>
  <text class="t-xs" x="580" y="288" text-anchor="middle">بازگشت به پیش‌نویس همیشه ممکن است</text>

  <defs>
    <marker id="a" markerWidth="8" markerHeight="8" refX="6" refY="4" orient="auto">
      <path d="M0 0 L8 4 L0 8 z" fill="${C.muted}"/>
    </marker>
    <marker id="b" markerWidth="8" markerHeight="8" refX="6" refY="4" orient="auto">
      <path d="M0 0 L8 4 L0 8 z" fill="${C.warn}"/>
    </marker>
  </defs>`,
  "جریان انتشار محتوا در پیشداد",
);

/* ── ۳. راست‌به‌چپ ──────────────────────────────────────────────────────── */

/**
 * ⚠️ نسخهٔ اول این تصویر دو ستونِ متراکم داشت و در اندازهٔ واقعیِ سایت
 * ناخوانا بود. حالا یک نمودارِ ساده است: دو کارتِ واقعی در سمت راست (با
 * شمارهٔ فارسی) و یک ستونِ کوتاه از نکته‌ها در سمت چپ.
 */
const rtl = svg(
  960, 400,
  `  <text class="t-lg" x="${960 - pad}" y="52" text-anchor="start">راست‌به‌چپ از روز اول</text>
  <text class="t-sm" x="${960 - pad}" y="80" text-anchor="start">نه یک قابلیت، نه یک وصلهٔ بعدی</text>

  <rect class="box" x="480" y="120" width="456" height="92" rx="12"/>
  <circle class="accent" cx="890" cy="166" r="22"/>
  <text x="890" y="173" text-anchor="middle" font-size="17" font-weight="700" fill="${C.onAccent}">۱</text>
  <text class="t" x="852" y="158" text-anchor="start">ویژگی‌های امکانات</text>
  <text class="t-sm" x="852" y="184" text-anchor="start">آیتم اول سمت راست می‌نشیند</text>

  <rect class="box" x="480" y="228" width="456" height="92" rx="12"/>
  <circle class="cyan" cx="890" cy="274" r="22"/>
  <text x="890" y="281" text-anchor="middle" font-size="17" font-weight="700" fill="${C.onAccent}">۲</text>
  <text class="t" x="852" y="266" text-anchor="start">قیمت‌گذاری</text>
  <text class="t-sm" x="852" y="292" text-anchor="start">و همین ترتیب در فوتر تکرار می‌شود</text>

  <rect class="accent-s" x="456" y="120" width="14" height="200" rx="7"/>

  <text class="t-lg" x="440" y="52" text-anchor="start">چه چیزی از روز اول درست بود</text>

  <g font-size="14" fill="${C.ink}">
    <text x="440" y="120" text-anchor="start">فونت وزیرمتن، محلی و بدون CDN</text>
    <text x="440" y="156" text-anchor="start">ویژگی‌های منطقی CSS به‌جای left/right</text>
    <text x="440" y="192" text-anchor="start">جهت از تنظیمات، نه hardcode</text>
    <text x="440" y="228" text-anchor="start">اعداد فارسی در متن و تاریخ</text>
    <text x="440" y="264" text-anchor="start">جست‌وجوی نرمال‌شدهٔ ی و ک</text>
  </g>

  <rect class="box" x="40" y="300" width="896" height="60" rx="12"/>
  <text class="t-sm" x="${960 - pad}" y="336" text-anchor="start">تعویض به چپ‌چین یک باگ است، نه یک تنظیم</text>`,
  "راست‌به‌چپ بودن رابط پیشداد",
);

/* ── ۴. امنیت ──────────────────────────────────────────────────────────── */

const security = svg(
  960, 400,
  `  <text class="t-lg" x="920" y="56" text-anchor="start">مرزهای امنیتی</text>
  <text class="t-sm" x="920" y="84" text-anchor="start">هر لایه جدا، و هیچ لایه‌ای به لایهٔ قبل اعتماد ندارد</text>

  <g>
    <rect class="box" x="650" y="130" width="270" height="130" rx="14"/>
    <rect class="accent" x="650" y="130" width="270" height="5" rx="3"/>
    <text class="t" x="785" y="176" text-anchor="middle">افزونه</text>
    <text class="t-xs" x="785" y="206" text-anchor="middle">نقشِ دیتابیسِ جدا</text>
    <text class="t-xs" x="785" y="230" text-anchor="middle">فقط به جدول‌های اعلام‌شده</text>
  </g>

  <g>
    <rect class="box" x="345" y="130" width="270" height="130" rx="14"/>
    <rect class="cyan" x="345" y="130" width="270" height="5" rx="3"/>
    <text class="t" x="480" y="176" text-anchor="middle">امضا</text>
    <text class="t-xs" x="480" y="206" text-anchor="middle">هر بسته با کلید ناشر</text>
    <text class="t-xs" x="480" y="230" text-anchor="middle">و مهرِ سیاست‌محور</text>
  </g>

  <g>
    <rect class="box" x="40" y="130" width="270" height="130" rx="14"/>
    <rect class="good" x="40" y="130" width="270" height="5" rx="3"/>
    <text class="t" x="175" y="176" text-anchor="middle">هسته</text>
    <text class="t-xs" x="175" y="206" text-anchor="middle">جدول‌های هسته قابل لمس نیست</text>
    <text class="t-xs" x="175" y="230" text-anchor="middle">حتی توسط افزونه</text>
  </g>

  <path d="M645 195 L620 195" class="rule" stroke-width="2" marker-end="url(#s)"/>
  <path d="M340 195 L315 195" class="rule" stroke-width="2" marker-end="url(#s)"/>

  <rect class="box" x="40" y="300" width="880" height="64" rx="12"/>
  <text class="t-sm" x="480" y="340" text-anchor="middle">پیش‌فرض بسته است: هرچه در فهرست نباشد، رد می‌شود</text>

  <defs>
    <marker id="s" markerWidth="8" markerHeight="8" refX="6" refY="4" orient="auto">
      <path d="M0 0 L8 4 L0 8 z" fill="${C.muted}"/>
    </marker>
  </defs>`,
  "مرزهای امنیتی افزونه‌ها در پیشداد",
);

/* ── نوشتن ─────────────────────────────────────────────────────────────── */

/**
 * ⚠️ هر فایل **دو جا** نوشته می‌شود، و این عمدی است:
 *
 * 1. `frontend/public/images/content/` — تا مستقیم سرو شود (بدون PHP).
 * 2. `backend/database/seeders/assets/content/` — نسخهٔ canonical برای
 *    `PishdadSiteSeeder`.
 *
 * چرا نسخهٔ دوم لازم است: کانتینرِ بک‌اند فقط `backend/` را mount دارد و
 * به `frontend/` دسترسی ندارد، پس seeder نمی‌تواند فایل را از آنجا بخواند.
 * و چون یک فایل از یک تابع ساخته می‌شود، دو نسخه هرگز از هم جدا نمی‌افتند.
 *
 * مسیر دوم هم داخل مخزن است، نه `storage/` — وگرنه بستهٔ خام که
 * `storage/app` را نمی‌فرستد، تصویرها را از دست می‌داد.
 */
const images = {
  "architecture.svg": architecture,
  "publish-flow.svg": publishFlow,
  "rtl.svg": rtl,
  "security.svg": security,
};

const OUT_BACKEND = join(
  ROOT,
  "cms-core",
  "backend",
  "database",
  "seeders",
  "assets",
  "content",
);

mkdirSync(OUT, { recursive: true });
mkdirSync(OUT_BACKEND, { recursive: true });

let total = 0;

for (const [name, content] of Object.entries(images)) {
  const bytes = Buffer.byteLength(content, "utf8");
  writeFileSync(join(OUT, name), content, "utf8");
  writeFileSync(join(OUT_BACKEND, name), content, "utf8");
  total += bytes;
  console.log(`  ${name.padEnd(20)} ${(bytes / 1024).toFixed(1)} KB`);
}

console.log(`\n✔ ${Object.keys(images).length} content images`);
console.log(`  → cms-core/frontend/public/images/content/          (سروِ مستقیم)`);
console.log(`  → cms-core/backend/database/seeders/assets/content/  (canonical برای seeder)`);
console.log(`  ${(total / 1024).toFixed(1)} KB per copy — local SVG, no CDN`);