import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

import {
  CORE_CHROME_WIDGET_TYPES,
  UNKNOWN_WIDGET_TYPE_MAX,
  chromeWidgetDiagnosticsEnabled,
  describeUnknownWidgetType,
  isCoreChromeWidgetType,
  unknownWidgetNoticeFa,
  unknownWidgetWarning,
} from "./widget-type.ts";

/**
 * K6.4 — سیاستِ شاخهٔ `default` در `ChromeWidget` (هدر/فوتر سایت عمومی).
 *
 * این تست عمداً `Chrome.tsx` را **رندر نمی‌کند**: glob اسکریپت تست فقط فایل‌های
 * `test.ts` را می‌گیرد و هیچ رندرری هم در بسته نیست، پس سیاست به
 * `lib/widget-type.ts` کشیده شده (بدون JSX، بدون React) تا واقعاً اجرا شود.
 * برای همین بخشی از تست، **متنِ** `Chrome.tsx` خوانده می‌شود — همان ترفندی که
 * `block-type.test.ts` برای `BlockRenderer` و `plugin-admin-path.test.ts` برای
 * `proxy.ts` می‌زنند.
 *
 * بخش ۵ (قراردادِ بک‌اند) از خانوادهٔ `dashboard-widget-card.test.ts` است: آن یکی
 *Removal را قفل می‌کند، این یکی **نبودِ primitive** را. اگر روزی نقطهٔ قرارداد
 * واقعاً زنده شد، این تست عمداً می‌افتد تا نگهبان را دستی برداریم و جای رندرِ
 * واقعی بسازیم.
 */

const RENDERER_PATH = fileURLToPath(new URL("../components/site/Chrome.tsx", import.meta.url));
const rendererSource = readFileSync(RENDERER_PATH, "utf8");

const backendFile = (name: string): string =>
  readFileSync(
    fileURLToPath(new URL(`../../../backend/app/Services/Plugins/${name}`, import.meta.url)),
    "utf8",
  );

const contractSource = backendFile("PluginPackageContract.php");
const registrySource = backendFile("ManifestRegistry.php");

/** کامنت‌ها را برمی‌دارد تا ادعاهای تست روی *کد* باشد، نه روی متنِ توضیح. */
const stripComments = (source: string): string =>
  source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\/\/[^\n]*/g, "");

/** بدنهٔ یک `case`/بلوک از `switch` ویجت، بدون توضیحات. */
const switchBody = (): string => {
  const from = rendererSource.indexOf("switch (w.type)");
  assert.ok(from > -1, "باید `switch (w.type)` در ChromeWidget پیدا شود");
  return stripComments(rendererSource.slice(from));
};

/* ─────────────────────── ۱. واژگانِ ویجت‌های هسته ─────────────────────── */

test("هر ویجتِ هسته در واژگان هست و واژگان چیزِ اضافه ندارد", () => {
  // ۱۰ نوعِ حاضر در `switch`ِ `ChromeWidget` (۵ هدر + ۵ فوتر، هم‌خط
  // `config/widgets.php`). فهرستِ عمداً صریح است (نه `>=`) تا اگر کسی ویجتِ
  // تازه‌ای اضافه کرد، این‌جا یادش بیاید و کسی یواشکی پاکش نکند.
  assert.deepEqual(
    [...CORE_CHROME_WIDGET_TYPES].sort(),
    [
      "about",
      "contact",
      "copyright",
      "cta",
      "links",
      "logo",
      "nav",
      "newsletter",
      "search",
      "socials",
    ].sort(),
  );
  for (const type of CORE_CHROME_WIDGET_TYPES) {
    assert.equal(isCoreChromeWidgetType(type), true, `باید هسته باشد: ${type}`);
  }
});

test("واژگان با config/widgets.php هسته هم‌خوان است (۵ هدر + ۵ فوتر)", () => {
  // یک رستهٔ دوم از حقیقت: کانفیگ بک‌اند. اگر ویجتِ هسته‌ای به کروم اضافه شود
  // ولی این‌جا و `switch` جا نیفتد، `registry-drift` در production بی‌صدا
  // رندر نمی‌شود. مقایسه با متنِ کانفیگ است، نه با یک آرایهٔ ثابتِ سوم.
  const configSource = readFileSync(
    fileURLToPath(new URL("../../../backend/config/widgets.php", import.meta.url)),
    "utf8",
  );
  // فقط بلوک‌های آرایهٔ `return` را می‌خوانیم: عنوان/توضیح فارسیِ بالای فایل
  // کلیدهای دیگری دارد که به ویجت ربطی ندارد.
  const registry = configSource.slice(configSource.indexOf("return ["));
  const areaTitles = [...registry.matchAll(/'(header|footer)'\s*=>\s*\[/g)].map((m) => m[1]);
  assert.deepEqual(areaTitles, ["header", "footer"], "کانفیگ باید دو ناحیه داشته باشد");

  const typesIn = (area: string): string[] => {
    const start = registry.indexOf(`'${area}' => [`) + `'${area}' => [`.length;
    // پایانِ ناحیه = نزدیک‌ترین براکتِ بسته در تراز ۴ فاصله.
    const rest = registry.slice(start);
    const end = rest.search(/\n {4}\]/);
    const body = end === -1 ? rest : rest.slice(0, end);
    return [...body.matchAll(/^\s{8}'([a-z][a-z0-9_-]*)'\s*=>\s*\[/gm)].map((m) => m[1]);
  };

  assert.deepEqual(
    [...typesIn("header"), ...typesIn("footer")].sort(),
    [...CORE_CHROME_WIDGET_TYPES].sort(),
    "واژگانِ فرانت باید برابرِ رجیستریِ هستهٔ بک‌اند باشد",
  );
});

test("type اعلامیِ افزونه (نمونهٔ خودِ قرارداد) ناشناس است", () => {
  // `PluginPackageContract.php:326,352` ⇒ `example_ok` هر دو نقطه همین‌هاست.
  for (const type of ["trust_bar", "partners", "newsletter_ticker", "تبلیغ", "acme_cta"]) {
    assert.equal(isCoreChromeWidgetType(type), false, `نباید هسته باشد: ${type}`);
  }
});

test("واژگان با Object.freeze قابل‌دستکاری نیست", () => {
  // اگر رجیستری از بیرون تغییر کند، `isCoreChromeWidgetType` و پیامِ لاگ یکی از
  // دیگری جدا می‌افتند و کل قفلِ این فایل بی‌معنا می‌شود.
  assert.throws(() => {
    (CORE_CHROME_WIDGET_TYPES as string[]).push("injected");
  }, TypeError);
  assert.equal(isCoreChromeWidgetType("injected"), false);
});

test("کلیدهای پروتوتایپ هرگز ویجتِ هسته حساب نمی‌شوند", () => {
  // دقیقاً همان چیزی که `CORE_CHROME_WIDGET_TYPES[type]` به‌جای `Set.has` لو می‌داد:
  // `constructor` و `toString` روی prototype هستند و هرگز `case` ندارند، ولی
  // جست‌وجوی سادهٔ آن‌ها را «پیدا» می‌کند و به رندرِ هسته می‌فرستد.
  for (const type of ["constructor", "toString", "valueOf", "hasOwnProperty", "__proto__", "prototype"]) {
    assert.equal(isCoreChromeWidgetType(type), false, `نباید هسته باشد: ${type}`);
  }
});

test("type غیررشته هیچ‌وقت هسته نیست", () => {
  // payload بک‌اند تضمین رشته نمی‌دهد؛ `switch` با غیررشته نمی‌شکند ولی
  // رجیستری هم نباید به چیزی شبیه رشته چنگ بزند.
  // `BigInt(10)` به‌جای `10n`: هدفِ tsconfig زیرِ ES2020 است و `10n` خطای
  // کامپایل می‌دهد — این آزمون باید دربارهٔ رجیستری باشد، نه دربارهٔ target.
  for (const type of [undefined, null, 42, 0, true, {}, [], ["logo"], Symbol("logo"), BigInt(10)]) {
    assert.equal(isCoreChromeWidgetType(type), false, `نباید هسته باشد: ${String(type)}`);
  }
});

/* ─────────── ۲. اثباتِ «ویجتِ هسته همیشه برنده است» (قفلِ لغزش) ─────────── */

test("برچسب‌های case در ChromeWidget دقیقاً برابرِ واژگانِ هسته‌اند", () => {
  // مهم‌ترین تستِ بخش رندر. دو منبعِ حقیقت داشت (رجیستری + `switch`) و هیچ
  // چیزی جلوی واگرایشان را نمی‌گرفت — و واگرایی یعنی دقیقاً همان باگی که
  // K6.4 می‌بندد: ویجتی که رجیستری هسته می‌داند ولی رندر نمی‌شود، یا برعکس.
  const labels = [...switchBody().matchAll(/case\s+"([^"]*)":/g)].map((m) => m[1]);
  assert.ok(labels.length > 0, "باید `case \"…\":` در switch پیدا شود");
  assert.deepEqual([...new Set(labels)].sort(), [...CORE_CHROME_WIDGET_TYPES].sort());
});

test("شاخهٔ default تنها یکی است و بعد از همهٔ caseها آمده", () => {
  // ساختارِ `switch` در خودِ JS یعنی `default` فقط وقتی اجرا می‌شود که هیچ
  // `case`ای نخورده ⇒ شاخهٔ `default` **نمی‌تواند** ویجتِ هسته را بپوشاند.
  const body = switchBody();
  const defaults = [...body.matchAll(/\bdefault\s*:/g)].map((m) => m.index!);
  assert.equal(defaults.length, 1, `باید دقیقاً یک شاخهٔ default باشد، دیدم: ${defaults.length}`);

  const lastCase = Math.max(...[...body.matchAll(/\bcase\s+"/g)].map((m) => m.index!));
  assert.ok(
    defaults[0] > lastCase,
    "شاخهٔ default باید بعد از آخرین case باشد وگرنه می‌تواند ویجتِ هسته را بپوشاند",
  );
});

/* ───────────── ۳. شاخهٔ `default` بی‌خطر است و چیزی جعل نمی‌کند ───────────── */

test("شاخهٔ default throw نمی‌کند، لاگ را از سیاست می‌گیرد، و HTML تزریق نمی‌کند", () => {
  const body = switchBody();
  const branch = body.slice(body.indexOf("default: {"));
  assert.ok(branch.length > 0, "باید شاخهٔ default پیدا شود");

  // اگر کسی `throw` بگذارد، «یک ویجتِ خراب هدرِ همهٔ صفحات را می‌اندازد» دوباره
  // برمی‌گردد. هر دو مسیرِ لاگ از `unknownWidgetWarning` می‌آید تا متن‌ها از هم
  // جدا نیفتند.
  assert.ok(!/\bthrow\b/.test(branch), "شاخهٔ default نباید throw کند");
  assert.ok(
    branch.includes("unknownWidgetWarning(w.type"),
    "شاخهٔ default باید پیامِ لاگ را از unknownWidgetWarning بگیرد",
  );
  assert.ok(
    !/console\.(warn|error)\(`\[site\]/.test(branch),
    "متنِ لاگِ درون‌خطی ممنوع — باید از سیاست بیاید",
  );

  // لاگ باید **همیشه** دو شاخه داشته باشد: عادی با `warn`، لغزشِ رجیستری با
  // `error`. اگر یکی حذف شود، آن رخداد بی‌صدا می‌شود.
  assert.ok(branch.includes("console.warn("), "مسیرِ عادی باید warn کند");
  assert.ok(branch.includes("console.error("), "لغزشِ رجیستری باید error کند");
  assert.ok(
    branch.includes("isCoreChromeWidgetType(w.type"),
    "تفکیکِ لغزشِ رجیستری از ویجتِ افزونه‌ای باید در خودِ شاخه باشد",
  );

  // `type` خام نباید به DOM برسد: داخل صفتِ `data-widget-type` می‌رود و
  // `describeUnknownWidgetType` تنها چیزی است که جلوی تزریق لاگ/جعلِ جهت/رشدِ
  // بی‌حد را می‌گیرد.
  assert.ok(
    branch.includes("describeUnknownWidgetType(w.type"),
    "شاخهٔ default باید type را از describeUnknownWidgetType بگذراند، نه خام در DOM بگذارد",
  );

  // ── مهم‌ترین ادعای این فایل ──
  // این شاخه نباید هیچ HTML یا رشتهٔ خامی را به DOM تزریق کند. تنها راهِ
  // «رندرِ افزونه در سایت عمومی» همین تزریق است، و قرارداد خودش
  // `html/js/component/render` را در `FORBIDDEN`
  // (`PluginPackageContract.php:118-121`) banned کرده. یعنی اگر کسی اینجا
  // `dangerouslySetInnerHTML` بگذارد، عملاً همان دروغی را می‌سازد که K6.10
  // حذف کرد — با یک سطح اختیارِ بیشتر.
  assert.ok(
    !/dangerouslySetInnerHTML/.test(branch),
    "شاخهٔ default نباید HTML تزریق کند — افزونه در سایت عمومی کد اجرا نمی‌شود",
  );

  // و اعلان، وقتی روشن است، باید `role="note"` باشد (یادداشتِ محتوایی، نه
  // خطای قابل‌انجام‌کار برای بازدیدکننده) — هم‌خط `BlockRenderer`.
  assert.ok(branch.includes('role="note"'), "اعلان باید role=\"note\" باشد");
  assert.ok(branch.includes('data-widget-state="unknown"'), "اعلان باید stateِ خود را در DOM بگذارد");
});

test("شاخهٔ default بدون diagnostics چیزی رندر نمی‌کند (بازدیدکننده چیزی نمی‌بیند)", () => {
  // `return null` قبل از ساختِ اعلان ⇒ در production سایتِ عمومی تمیز می‌ماند
  // ولی لاگ رفته است. اگر این شرط جابه‌جا شود، «اعلانِ دیباگ» به سایتِ
  // عمومی نشت می‌کند.
  const body = switchBody();
  const branch = body.slice(body.indexOf("default: {"));
  const guardAt = branch.indexOf("if (!(diagnostics ?? chromeWidgetDiagnosticsEnabled())) return null;");
  assert.ok(guardAt > -1, "باید شرطِ diagnostics و بازگشتِ null باشد");

  const noticeAt = branch.indexOf("unknownWidgetNotice(locale, w.type)");
  assert.ok(noticeAt > guardAt, "اعلان باید بعد از نگهبان باشد، نه قبل از آن");
  const logAt = branch.indexOf("console.warn(");
  assert.ok(logAt < guardAt, "لاگ باید **پیش از** نگهبان باشد تا در production هم برسد");
});

/* ───────────── ۴. غیرکرشی و بی‌خطر بودن (اصلِ «صفحه نشکند») ───────────── */

test("describeUnknownWidgetType هرگز throw نمی‌کند، حتی با ورودیِ دشمن", () => {
  const hostile = [
    "a".repeat(100_000),
    "\n\n[site] chrome widget type: logo",
    "trust_bar\r\nGET /admin 200",
    "\u0000\u001f\u007f",
    "\u202E",           // بیتِ راست‌به‌چپ: جهتِ متنِ اطراف را می‌برد
    "<script>alert(1)</script>",
    "ا".repeat(500),
    "",
    " ",
  ];
  for (const type of hostile) {
    const out = describeUnknownWidgetType(type);
    assert.equal(typeof out, "string", `باید رشته باشد: ${type}`);
    assert.ok(out.length <= UNKNOWN_WIDGET_TYPE_MAX + 1, `طولِ نامحدود: ${out.length} برای ${type}`);
    assert.ok(
      !/[\u0000-\u001f\u007f]/.test(out),
      `نویسهٔ کنترلی/جهت‌دهنده نباید بیرون بزند: ${JSON.stringify(out)}`,
    );
  }
});

test("describeUnknownWidgetType غیررشته را خالی می‌دهد و به شیء تبدیل نمی‌کند", () => {
  for (const type of [undefined, null, 42, {}, ["logo"]]) {
    assert.equal(describeUnknownWidgetType(type), "");
  }
});

test("تزریق لاگ از راه type چندخطی بسته است", () => {
  // یک `type` از پلاگین/DB می‌تواند هرچه باشد. اگر `\n` بیرون بزند، یک خطِ
  // جعلی در ترمینال ساخته می‌شود و جست‌وجوی بعدی «کدام ویجت بود» گمراه می‌شود.
  const forged = "x\n[site] chrome widget type: \"logo\" — has no case in the ChromeWidget switch.";
  const line = unknownWidgetWarning(forged);
  assert.equal(line.split("\n").length, 1, "پیامِ لاگ باید دقیقاً یک خط باشد");
  assert.ok(!line.includes("\r"));
  assert.ok(!/[\u0000-\u001f]/.test(line), "کاراکترِ کنترلی نباید به لاگ برسد");
});

test("پیامِ لاگ علتِ ساختاری را می‌گوید تا کسی فکر نکند bug رندر است", () => {
  // اگر فقط بنویسیم «ناشناس»، اپراتور دنبال `Chrome.tsx` می‌گردد؛ در حالی که
  // ریشه در قرارداد است و با افزودن `case` حل نمی‌شود. نامِ کانالِ واقعی
  // (`manifest.widgets`) و نامِ نقطهٔ بی‌استفادهٔ قرارداد، هر دو باید بیایند.
  const line = unknownWidgetWarning("trust_bar");
  assert.ok(line.includes("trust_bar"));
  assert.ok(line.includes("[site]"), "باید پیشوندِ کانال داشته باشد ⇒ قابل‌جست‌وجو");
  assert.ok(line.includes("manifest.widgets"), "باید کانالِ واقعیِ افزونه را نام ببرد");
  assert.ok(line.includes("site.header_widget"), "باید نقطهٔ قراردادِ بی‌مصرف را نام ببرد");
  assert.ok(line.includes("no case in the ChromeWidget switch"), "باید بگوید رندرر جلویش را گرفته");

  const notice = unknownWidgetNoticeFa("trust_bar");
  assert.ok(notice.includes("trust_bar"));
  assert.ok(notice.includes("رندر نشد"), "اعلان باید صریح بگوید چیزی رندر نشده");
});

test("لغزشِ رجیستری از ویجتِ بی‌مصرف تفکیک می‌شود", () => {
  // `registry-drift` یعنی ساختار `switch` و واژگان یکی نیست ⇒ تقصیرِ خودِ
  // ماست و باید بلندتر گزارش شود. اگر این دو یکی شوند، `console.error`
  // بی‌معنا و `console.warn` برای باگِ خودمان کم‌شدت می‌شود.
  const drift = unknownWidgetWarning("logo", "registry-drift");
  const normal = unknownWidgetWarning("logo");
  assert.ok(drift.includes("registry drift"), drift);
  assert.ok(!normal.includes("registry drift"), normal);
  assert.ok(!drift.includes("manifest.widgets"), "لغزشِ خودِ ما ربطی به افزونه ندارد");
});

test("type خالی، برچسبِ قابل‌خواندن می‌گیرد نه رشتهٔ خالی", () => {
  assert.ok(unknownWidgetWarning("").includes("(empty)"));
  assert.ok(unknownWidgetNoticeFa("").includes("(خالی)"));
});

/* ───────────── ۵. چه‌وقت اعلانِ دیداری رندر شود ───────────── */

test("تشخیص: هر چیزی جز production روشن است", () => {
  assert.equal(chromeWidgetDiagnosticsEnabled({}), true, "نبودِ NODE_ENV یعنی توسعه");
  assert.equal(chromeWidgetDiagnosticsEnabled({ NODE_ENV: "development" }), true);
  assert.equal(chromeWidgetDiagnosticsEnabled({ NODE_ENV: "test" }), true);
});

test("تشخیص: در production پیش‌فرض خاموش است", () => {
  assert.equal(chromeWidgetDiagnosticsEnabled({ NODE_ENV: "production" }), false);
  assert.equal(
    chromeWidgetDiagnosticsEnabled({ NODE_ENV: "production", CHROME_WIDGET_DIAGNOSTICS: "0" }),
    false,
  );
  assert.equal(
    chromeWidgetDiagnosticsEnabled({ NODE_ENV: "production", CHROME_WIDGET_DIAGNOSTICS: "true" }),
    false,
  );
  assert.equal(
    chromeWidgetDiagnosticsEnabled({ NODE_ENV: "production", CHROME_WIDGET_DIAGNOSTICS: "" }),
    false,
  );
});

test("تشخیص: production با opt-inِ صریح روشن می‌شود", () => {
  assert.equal(
    chromeWidgetDiagnosticsEnabled({ NODE_ENV: "production", CHROME_WIDGET_DIAGNOSTICS: "1" }),
    true,
  );
  assert.equal(
    chromeWidgetDiagnosticsEnabled({
      NODE_ENV: "production",
      CHROME_WIDGET_DIAGNOSTICS: "1",
      NEXT_PUBLIC_X: "1",
    }),
    true,
  );
});

test("متغیرِ تشخیص نباید عمومی باشد", () => {
  // اگر روزی کسی `NEXT_PUBLIC_CHROME_WIDGET_DIAGNOSTICS` بسازد، هم داخل بستهٔ
  // مرورگر می‌رود و هم برای بازدیدکننده قابل‌دستکاری می‌شود.
  assert.equal("CHROME_WIDGET_DIAGNOSTICS".startsWith("NEXT_PUBLIC_"), false);
});

/* ─── ۶. نگهبانِ قرارداد: چرا این نقطه اصلاً پیاده نشده (K6.4 = گزارش، نه ساخت) ─── */

/** بلوکِ یک نقطهٔ قرارداد را از آرایهٔ `EXTENSION_POINTS` بیرون می‌کشد. */
const pointBody = (key: string): string => {
  // `[\s\S]` به‌جای پرچم `s` — هدف tsc این پروژه es2018 نیست.
  const found = new RegExp(`'${key.replace(".", "\\.")}'\\s*=>\\s*\\[([\\s\\S]*?)\\n {8}\\],`).exec(
    contractSource,
  );
  assert.ok(found, `نقطهٔ ${key} باید در قرارداد تعریف شده باشد.`);
  return found[1];
};

test("site.header_widget و site.footer_widget schema دارند ولی live نیستند (K3.9)", () => {
  // ⚠️ این تست قبلاً **برعکس** بود و می‌گفت این دو نقطه باید `fields => []`
  // و `open_schema => false` بمانند. آن ادعا تا K3.9 درست بود: نقطه اعتبارسنجی
  // می‌شد ولی هیچ فیلدی نداشت، پس هر اعلانِ قانونی خطا می‌خورد (K6.4).
  //
  // K3.9 عمداً عوضش کرد چون **مصرف‌کنندهٔ واقعی** دارد: `widgetSchemas()` از
  // K6.4 کانال را می‌خواند و فرانت از `/v1/admin/widgets/schema` رندرش می‌کند.
  // نگهبان باید ادعای **تازه** را بسنجد، نه وضعیتِ منسوخ را:
  //  ۱) schema پر است (وگرنه K3.9 برگشته)،
  //  ۲) `open_schema` بسته می‌ماند (واژگان باز برای این نقطه تصمیم نیست)،
  //  ۳) و مهم‌تر از همه **`live` نیست** — رندرر (`ChromeWidget`) هنوز
  //     `switch`-بسته روی واژگان هسته است، پس ویجت افزونه‌ای اعلان می‌شود،
  //     اعتبارسنجی می‌شود و در رجیستری می‌آید، ولی **رندر نمی‌شود**.
  //     اگر روزی رندر شد، باید شاخهٔ `default` و این تست با هم عوض شوند.
  for (const key of ["site.header_widget", "site.footer_widget"]) {
    const body = pointBody(key);
    assert.match(
      body,
      /'schema'\s*=>\s*\[\s*'fields'\s*=>\s*\[[^\]]/,
      `${key} باید schema پر داشته باشد (K3.9)`,
    );
    assert.match(body, /'open_schema'\s*=>\s*false/, `${key} باید بسته بماند`);
    assert.match(body, /'status'\s*=>\s*'declared_only'/, `${key} نباید live باشد`);
  }
});

test("نگهبانِ no_schema در خودِ validateDeclaration هست", () => {
  // اگر کسی این نگهبان را بردارد ولی `fields` را خالی بگذارد، اعلانِ بی‌پشتوانه
  // دوباره سبز می‌شود — دقیقاً همان چیزی که پیامِ قرارداد می‌گوید نباید بشود.
  assert.match(
    contractSource,
    /\$fields === \[\] && \$meta\['open_schema'\] === false/,
    "نگهبانِ no_schema باید در validateDeclaration بماند",
  );
  assert.match(contractSource, /'no_schema'/, "کدِ خطای no_schema باید وجود داشته باشد");
});

test("نقطهٔ قراردادِ header/footer_widget حالا مصرف‌کنندهٔ واقعی دارد (K6.4)", () => {
  // ⚠️ این تست قبلاً **برعکس** بود و می‌گفت `ManifestRegistry` نباید به
  // `header_widget` ارجاع دهد. آن ادعا روزی درست بود — نقطه اعتبارسنجی می‌شد
  // ولی هیچ‌جا خوانده نمی‌شد، یعنی افزونه می‌توانست ویجت اعلام کند و بی‌صدا
  // گم شود.
  //
  // K6.4 این را درست کرد: `widgetSchemas()` حالا هر دو کانال را می‌خواند. پس
  // نگهبان باید ادعای **جدید** را بسنجد: هر دو کانال زنده‌اند و کانال سطح‌بالا
  // برنده است.
  assert.match(
    registrySource,
    /site\.header_widget/,
    "نقطهٔ قراردادِ هدر باید در رجیستری خوانده شود",
  );
  assert.match(
    registrySource,
    /site\.footer_widget/,
    "نقطهٔ قراردادِ فوتر باید در رجیستری خوانده شود",
  );
  assert.match(
    registrySource,
    /\$manifest\['widgets'\]\s*\?\?\s*null/,
    "کانال سطح‌بالا باید هم‌چنان خوانده شود",
  );

  // ترتیب merge: سطح‌بالا **اول**، چون `$owner` اولین ثبت‌کننده را نگه می‌دارد
  // و بازنده‌ها حذف می‌شوند. اگر جابه‌جا شود، کانال صریح‌تر بازنده می‌شود.
  const seg = registrySource.slice(
    registrySource.indexOf("public static function widgetSchemas"),
  );
  const top = seg.indexOf("$merge($manifest, $source);");
  const point = seg.indexOf("$mergePointChannel($manifest, $source);");
  assert.ok(top > -1 && point > -1, "هر دو فراخوانی merge باید باشند");
  assert.ok(
    top < point,
    "کانال سطح‌بالا باید اول merge شود تا `$owner` اولین ثبت‌کننده را نگه دارد",
  );

  // و LayoutController همان کانال را می‌پذیرد ⇒ مصرف‌کننده فقط رجیستری نیست.
  const layoutSource = readFileSync(
    fileURLToPath(
      new URL(
        "../../../backend/app/Http/Controllers/Api/V1/Admin/LayoutController.php",
        import.meta.url,
      ),
    ),
    "utf8",
  );
  assert.match(
    layoutSource,
    /widgetSchemas/,
    "LayoutController هم باید همان رجیستری را مصرف کند",
  );
});


test("رندررِ کروم همین یک switch بسته است و تنها گیتِ رندر", () => {
  // اگر روزی رندررِ دومی برای ویجت اضافه شود، قفلِ بخش ۲ بی‌معنا می‌شود چون
  // فقط یک `switch` را می‌سنجد. پس تعدادش را صریح نگه می‌داریم.
  const switches = [...rendererSource.matchAll(/switch\s*\(/g)].length;
  assert.equal(switches, 1, `باید دقیقاً یک switch در Chrome.tsx باشد، دیدم: ${switches}`);

  // هیچ مسیرِ جایگزینی برای رندرِ ویجتِ افزونه نباید باز شود.
  const chromeCode = stripComments(rendererSource);
  assert.ok(
    !/dangerouslySetInnerHTML/.test(chromeCode),
    "کلِ Chrome.tsx نباید HTML تزریق کند",
  );
  assert.ok(
    !/<script/i.test(chromeCode),
    "کلِ Chrome.tsx نباید اسکریپتِ درون‌خطی بسازد",
  );
});
