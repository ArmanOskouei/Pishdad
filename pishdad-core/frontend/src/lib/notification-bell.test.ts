import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

/**
 * K5.8 — نگهبان‌های زنگ اعلان.
 *
 * این‌ها روی **کد** نگاه می‌کنند، چون رندر در `node --test` ممکن نیست. هدف
 * قفل‌کردن قواعدی است که در بازترتیبی به‌سادگی از بین می‌روند.
 */

const ROOT = fileURLToPath(new URL("../", import.meta.url));
const read = (p: string) => readFileSync(ROOT + p, "utf8");

const BELL = read("components/admin/NotificationBell.tsx");
const INBOX = read("app/(client)/admin/notifications/NotificationInbox.tsx");
const PREFS = read("app/(client)/admin/notifications/NotificationPreferences.tsx");
const TOPBAR = read("components/layout/Topbar.tsx");
const CSS = read("app/globals.css");

/**
 * ⭐ چرا کامنت‌ها پاک می‌شوند.
 *
 * این فایل‌ها دربارهٔ تصمیم‌ها توضیح می‌دهند و طبیعتاً **نامِ همان چیزی را که
 * ممنوع کرده‌اند** داخل کامنت می‌آورند («`router.push` نه»، «ایموجی `🔔` بود»).
 * بدون پاک‌کردن، نگهبان یک کامنت را جرم می‌گیرد — و بدترین حالت این است که
 * کسی برای سبزشدن تست، توضیح را پاک کند.
 *
 * پاک‌سازی محافظه‌کارانه است: فقط بلوک‌های کامنتِ چندخطی و خطوطی که با
 * `//` شروع می‌شوند. `//` وسطِ رشته (مثل URL) دست‌نخورده می‌ماند.
 */
function stripComments(src: string): string {
  return src
    .replace(/\/\*[\s\S]*?\*\//g, "")
    .split("\n")
    .filter((l) => !/^\s*(\/\/|\*)/.test(l))
    .join("\n");
}

const BELL_CODE = stripComments(BELL);
const INBOX_CODE = stripComments(INBOX);
const PREFS_CODE = stripComments(PREFS);
const CONTROLLER = readFileSync(
  fileURLToPath(
    new URL(
      "../../../backend/app/Http/Controllers/Api/V1/Admin/NotificationController.php",
      import.meta.url,
    ),
  ),
  "utf8",
);

test("زنگ در هدر سوار شده و فقط یکی است", () => {
  assert.match(TOPBAR, /NotificationBell/, "زنگ باید در Topbar باشد.");
  assert.equal(
    (TOPBAR.match(/<NotificationBell/g) ?? []).length,
    1,
    "فقط یک زنگ اعلان.",
  );
});

test("بارگذاری در useEffect است، نه شرط در بدنهٔ رندر", () => {
  // قانون پروژه: شرطِ فراخوانی در بدنهٔ رندر یعنی React #301.
  assert.match(BELL, /useEffect\(/, "بارگذاری باید در useEffect باشد.");
  assert.equal(
    /if\s*\(\s*!\s*\w+\s*\)\s*\{?\s*void\s+\w+\(/.test(BELL),
    false,
    "شرطِ فراخوانی در بدنهٔ رندر یعنی حلقهٔ رندر.",
  );
});

test("polling فقط وقتی drawer بسته است و timer پاک می‌شود", () => {
  // اگر در حال خواندن، polling ادامه پیدا کند، لیست زیر انگشت کاربر عوض
  // می‌شود. و اگر timer پاک نشود، بعد از هر باز کردن یک تایمر می‌ماند.
  assert.match(
    BELL,
    /if\s*\(open\)\s*return;/,
    "وقتی drawer باز است نباید polling کند.",
  );
  assert.match(BELL, /clearInterval\(timer\.current\)/, "timer باید پاک شود.");
  assert.match(
    BELL,
    /setInterval/,
    "polling باید با setInterval باشد — الگوی همین پروژه.",
  );
});

test("فاصلهٔ polling گیت‌شدنی است و حداقل ۳۰ ثانیه", () => {
  // polling پرتکرار یعنی هر مدیر چند بار در ساعت یک درخواست بی‌دلیل. عدد زیر
  // ۳۰ ثانیه عملاً یک بار در هر بازدید صفحه است.
  const m = /const POLL_MS = ([\d_]+);/.exec(BELL);
  assert.ok(m, "POLL_MS باید تعریف‌شده باشد.");
  const ms = Number(m![1]!.replace(/_/g, ""));
  assert.ok(
    ms >= 30_000,
    `فاصلهٔ polling نباید زیر ۳۰ ثانیه باشد (الان ${ms}ms است).`,
  );
});

test("زنگ هیچ URL حدسی نمی‌سازد", () => {
  // `notification_id` فقط وقتی مسیر می‌شود که آن مسیر در `pageRegistry()`
  // ثبت شده باشد. این کامپوننت رجیستری را ندارد، پس ساختن لینک با URL
  // حدسی دقیقاً همان کاری است که این سرویس برای جلوگیری از آن ساخته شد.
  //
  // ⭐ F4.2.F استثنا را **محدود** کرد: `action_href` حالا از کاتالوگ می‌آید و
  // سرور آن را از allowlist رد کرده، پس `<a href={…}>` مجاز است — ولی فقط
  // همین یک منبع. هر چیز دیگری (به‌ویژه `router.push`) هنوز ممنوع است.
  assert.equal(
    /router\.push/.test(BELL_CODE),
    false,
    "زنگ نباید مسیر بسازد — `<a>` کنترلِ مرورگر را می‌دهد و history را درست نگه می‌دارد.",
  );
  const hrefs = [...BELL_CODE.matchAll(/href=\{([^}]+)\}/g)].map((m) => m[1]!.trim());
  assert.ok(hrefs.length > 0, "لینکِ اقدامِ کاتالوگ باید رندر شود.");
  for (const h of hrefs) {
    assert.match(
      h,
      /^(href|n\.action_href|"\/admin\/notifications")$/,
      `href فقط از منبعِ قابلِ اعتماد می‌آید؛ «${h}» از کجا آمده؟`,
    );
  }
  assert.match(BELL_CODE, /safeActionHref\(/, "لینکِ کاتالوگ هم باید از allowlistِ فرانت رد شود.");
});

test("متنِ اعلان هرگز HTML نیست", () => {
  // بک‌اند `strip_tags` می‌کند، ولی دفاعِ چهارم در فرانت است: اگر روزی یک
  // مسیرِ تازه بدون `plain()` اضافه شود، همین‌جا رندر می‌شکند نه اینکه
  // XSS خاموش بماند.
  for (const [name, src] of [["زنگ", BELL_CODE], ["صندوق", INBOX_CODE]] as const) {
    assert.equal(
      /dangerouslySetInnerHTML/.test(src),
      false,
      `${name} نباید HTML تزریق کند.`,
    );
  }
  assert.match(BELL_CODE, /\{n\.title\}/, "عنوان باید به‌عنوان متنِ ری‌اکت رندر شود.");
  assert.match(BELL_CODE, /\{n\.body\}/, "بدنه باید به‌عنوان متنِ ری‌اکت رندر شود.");
});

test("زنگ از مسیرِ صندوقِ کاربر می‌خواند، نه سرویسِ افزونه", () => {
  // `POST /v1/admin/notifications` رزروِ `K5.8` است (سرویسِ افزونه) و
  // `GET` روی همان مسیر عنوانِ قابل‌نمایش ندارد — فقط `type` خام.
  assert.match(
    BELL,
    /\/v1\/admin\/notification-inbox/,
    "باید از `notification-inbox` بخواند.",
  );
  assert.equal(
    /authed[^\n]*"\/v1\/admin\/notifications"/.test(BELL),
    false,
    "`/v1/admin/notifications` متعلق به K5.8 است.",
  );
});

test("زنگ به‌جای ایموجی، SVG درون‌خطی دارد", () => {
  // ایموجی `🔔` روی هر سیستم‌عامل یک شکلِ متفاوت است و با Vazirmatn می‌جنگد.
  assert.match(BELL_CODE, /<svg[\s\S]*?aria-hidden/, "آیکون باید SVG درون‌خطی و تزئینی باشد.");
  assert.equal(/🔔/.test(BELL_CODE), false, "ایموجی جای SVG را نگیرد.");
});

test("درختِ DOM اعلان در گیتِ تست هم هست", () => {
  // مسیرهایی که پرکننده‌ای هستند باید صفحهٔ واقعی داشته باشند، وگرنه لینکِ
  // «همهٔ اعلان‌ها» به ۴۰۴ می‌رود.
  for (const p of ["app/(client)/admin/notifications/page.tsx", "app/(client)/admin/notifications/preferences/page.tsx"]) {
    assert.ok(read(p).length > 0, `${p} باید وجود داشته باشد.`);
  }
  const menu = read("lib/menu.ts");
  assert.match(menu, /href: "\/admin\/notifications"/, "صندوق باید در منو باشد — زنگ فقط ۸ ردیف آخر را نشان می‌دهد.");
});

test("هم بارگذاری در صندوق و هم در ترجیحات در useEffect است", () => {
  // قانون پروژه: شرطِ فراخوانی در بدنهٔ رندر یعنی React #301 و کرشِ کل صفحه.
  for (const [name, src] of [["صندوق", INBOX], ["ترجیحات", PREFS]] as const) {
    assert.match(src, /useEffect\(/, `${name}: بارگذاری باید در useEffect باشد.`);
    assert.equal(
      /if\s*\(\s*!?\s*\w+\s*\)\s*\{?\s*void\s+\w+\(/.test(src),
      false,
      `${name}: شرطِ فراخوانی در بدنهٔ رندر یعنی حلقهٔ رندر.`,
    );
  }
});

test("کلیدِ مادر سه‌حالته است", () => {
  // «بعضی روشن» وضعیتِ سوم است. با `role="switch"` بیان نمی‌شود (ARIA آن را رد
  // می‌کند) و در نتیجه فقط با رنگ گفته می‌شد — که برای صفحه‌خوان یعنی هیچ.
  assert.match(PREFS, /role="checkbox"/, "کلیدِ مادر باید checkbox باشد.");
  assert.match(
    PREFS,
    /aria-checked=\{mixed \? "mixed" : on\}/,
    "حالتِ «بعضی روشن» باید اعلام شود.",
  );
  // و از داده مشتق می‌شود، نه از stateِ جدا که با DB ناهماهنگ شود.
  assert.match(PREFS, /rows\.every\(\(r\) => r\.on === r\.total\)/, "روشنیِ مادر از ماتریس می‌آید.");
});

test("ترجیحات هر کارت را از پاسخِ سرور می‌گیرد، نه از حدسِ محلی", () => {
  // اگر state محلی نگه داشته شود، دو کلیدِ پشت‌سرهم (کاربر سریع می‌زند) با هم
  // می‌جنگند و یکی بی‌صدا گم می‌شود.
  assert.match(PREFS, /if \(json\?\.matrix\) setMatrix\(json\.matrix\);/, "پاسخ، منبعِ حقیقت است.");
  assert.match(PREFS, /method: "PUT"/, "نوشتن باید PUT باشد — سطر (کاربر، گروه، کانال) یک تک‌مورد است.");
});

test("کارت‌ها و کانال‌ها از بک‌اند می‌آیند، نه از فهرستِ ثابت", () => {
  // `Catalog::GROUPS` و `Catalog::CHANNELS` در بک‌اند تعریف می‌شوند و با هر
  // نسخه عوض می‌شوند. فهرستِ ثابت در فرانت یعنی کارتِ جاافتاده یا کانالِ
  // غیرقابل‌خاموش‌کردن.
  assert.match(PREFS, /json\?\.groups/, "برچسبِ گروه باید از بک‌اند بیاید.");
  assert.match(PREFS, /json\?\.channels/, "کانال‌ها باید از بک‌اند بیایند.");
  assert.match(PREFS, /tGroup\(g, labels\[g\] \?\? g\)/, "ترجمهٔ گروه باید از کلید بخواند و به برچسبِ بک‌اند بیفتد.");
});

test("شمار خوانده‌نشده با ویژگی‌های منطقی می‌نشیند", () => {
  // `right` در RTL یعنی چپ. یعنی تعویض جهت، نشانگر را جابه‌جا می‌کند.
  const seg = CSS.slice(CSS.indexOf(".bell-count"));
  assert.match(seg, /inset-inline-end/, "نشانگر باید منطقی باشد.");
  assert.equal(
    /(^|[\s;])right\s*:/.test(seg.slice(0, seg.indexOf("}"))),
    false,
    "نباید از ویژگی فیزیکی استفاده شود.",
  );
  assert.match(CSS, /--danger/, "رنگ نشانگر باید از توکن بیاید نه مقدار ثابت.",
  );
});

test("کنترلر بک‌اند مقصد اعلان را تعیین نمی‌کند", () => {
  // اعلان فقط برای خودِ فراخوان است. اگر `user_id` از ورودی خوانده شود،
  // افزونه می‌تواند برای مدیر دیگر پیام جعلی بفرستد.
  const fn = CONTROLLER.slice(CONTROLLER.indexOf("public function store"));
  const body = fn.slice(0, fn.indexOf("public function readAll"));
  assert.equal(
    /request->input\(['"]user_id|['"]user_id['"]\s*=>/.test(body),
    false,
    "ورودی نباید بتواند مقصد اعلان را تعیین کند.",
  );
  assert.match(body, /\$request->user\(\)/, "مقصد باید کاربر جاری باشد.");
});

test("پاک‌سازی فقط اعلان‌های خوانده‌شده را حذف می‌کند", () => {
  // اعلان خوانده‌نشده یعنی کاربر هنوز ندیده. حذفش یعنی پیامی که هرگز نرسید.
  const fn = CONTROLLER.slice(CONTROLLER.indexOf("private function prune"));
  assert.match(
    fn,
    /whereNotNull\('read_at'\)/,
    "فقط خوانده‌شده‌ها پاک شوند.",
  );
});

test("پاک‌سازی در مسیر خواندن است، نه command جدا", () => {
  // یک command یعنی نیاز به cron که در این پروژه تضمین‌شده نیست، و آن وقت
  // جدول باز هم بی‌مهار رشد می‌کند.
  assert.match(
    CONTROLLER,
    /public function index[\s\S]{0,900}?\$this->prune/,
    "index باید پاک‌سازی را صدا بزند.",
  );
});

test("کارتِ ربات از مسیرِ خودش می‌خواند و توکنِ کامل هرگز رندر نمی‌شود (E73)", () => {
  // توکنِ ربات پیش‌تر فقط در `.env` بود و پنل هیچ راهی نداشت؛ حالا سه مسیر
  // هست و هر سه باید از همین‌جا صدا زده شوند، نه از مسیرِ حدسی.
  for (const p of [
    "/v1/admin/notification-preferences/telegram-bot",
    "/v1/admin/notification-preferences/telegram-bot/test",
  ]) {
    assert.ok(PREFS.includes(p), `مسیر «${p}» باید از کارتِ ربات صدا زده شود.`);
  }
  assert.match(PREFS, /masked/, "فقط شکلِ ماسک‌شده نمایش داده می‌شود.");
  // ورودی `password` است و هیچ‌جا مقدارِ توکن به‌عنوان متن رندر نمی‌شود.
  assert.match(PREFS, /type="password"/, "فیلدِ توکن باید password باشد.");
  assert.equal(/\{botToken\}[^<]*<\/(div|span|p|b)>/.test(PREFS), false, "توکن نباید به‌عنوان متن رندر شود.");
});

test("کارتِ ربات بدون پرمیشن پنهان می‌شود، نه خراب (E73)", () => {
  // رازِ نصب پشت `settings.edit` است؛ ۴۰۳ یعنی کارت نیست، نه خطای قرمز.
  assert.match(PREFS, /ApiError/, "خطای ۴۰۳ باید از بدنه قابلِ تشخیص باشد.");
  assert.match(PREFS, /status === 403/, "فقط ۴۰۳ پنهان می‌کند، نه هر خطایی.");
  assert.match(PREFS, /botHidden/, "وضعیتِ پنهانی باید صریح باشد.");
});

test("پاپ‌اور از جدِ برنده فرار می‌کند (E72)", () => {
  // ریشهٔ باگ: popover قبلاً absolute داخل bell-wrap بود ولی topbar-actions
  // (جدِ آن) overflow-x:auto دارد و هر overflow غیرvisible نوادگانِ مطلق
  // را می‌بُرد — در DOM باز می‌شد ولی هیچ‌چیز دیده نمی‌شد.
  assert.match(BELL, /getBoundingClientRect\(\)/, "مختصات باید در لحظهٔ بازشدن از خودِ دکمه خوانده شود.");
  assert.match(BELL, /bell-pop-fixed/, "پاپ‌اور باید کلاسِ fixed داشته باشد.");
  const seg = CSS.slice(CSS.indexOf(".bell-pop-fixed"));
  assert.match(seg.slice(0, seg.indexOf("}")), /position:\s*fixed/, "کلاس باید fixed باشد تا از overflow جد فرار کند.");
});

test("مختصاتِ پاپ‌اور جهت‌آگاه است و کهنه نمی‌ماند (E72)", () => {
  assert.match(BELL, /getComputedStyle\([^)]*\)\.direction/, "جهت باید از دکمه خوانده شود، نه حدس زده.");
  assert.match(BELL, /window\.addEventListener\("scroll"/, "با اسکرول باید بسته شود تا مختصاتِ کهنه نماند.");
  assert.match(BELL, /window\.addEventListener\("resize"/, "با ری‌سایز باید بسته شود.");
});

test("اسکرولِ داخلِ خودِ پاپ‌اور آن را نمی‌بندد (E72)", () => {
  // فهرستِ بلند اسکرولِ خودش را دارد. `scroll` حباب نمی‌شود ولی listenerِ
  // capture روی window آن را از هر نواده می‌گیرد — پس مبدأ باید چک شود وگرنه
  // کاربر هرگز به ردیف‌های پایینِ اعلان‌ها نمی‌رسد.
  // دقیقاً در هندلرِ اسکرول، نه هندلرِ pointerdown (آن هم contains دارد).
  const scrollBlock = BELL.slice(
    BELL.indexOf("const onScroll"),
    BELL.indexOf('addEventListener("scroll"'),
  );
  assert.match(
    scrollBlock,
    /root\.contains\(e\.target\)/,
    "اسکرولِ برخاسته از داخلِ popover نباید ببندد.",
  );
});
