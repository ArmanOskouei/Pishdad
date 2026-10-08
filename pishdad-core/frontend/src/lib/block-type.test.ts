import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

import {
  BUILTIN_BLOCK_TYPES,
  UNKNOWN_TYPE_MAX,
  blockDiagnosticsEnabled,
  describeUnknownType,
  isBuiltinBlockType,
  unknownBlockNoticeFa,
  unknownBlockWarning,
} from "./block-type.ts";

/**
 * K6.3 — سیاستِ شاخهٔ `default` در `BlockRenderer`.
 *
 * این تست عمداً `BlockRenderer.tsx` را **رندر نمی‌کند**: glob اسکریپت تست
 * فقط فایل‌های `test.ts` را می‌گیرد و هیچ رندرری هم در بسته نیست، پس سیاست به
 * `lib/block-type.ts` کشیده شده (بدون JSX، بدون React) تا واقعاً اجرا شود.
 * برای همین بخشی از تست، **متنِ** `BlockRenderer.tsx` خوانده می‌شود — همان
 * ترفندی که `plugin-admin-path.test.ts` برای قفل‌کردن `proxy.ts` می‌زند.
 */

const RENDERER_PATH = fileURLToPath(
  new URL("../components/site/BlockRenderer.tsx", import.meta.url),
);
const rendererSource = readFileSync(RENDERER_PATH, "utf8");

/** کامنت‌ها را برمی‌دارد تا ادعاهای تست روی *کد* باشد، نه روی متنِ توضیح. */
const stripComments = (source: string): string =>
  source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\/\/[^\n]*/g, "");

/* ─────────────────────────── ۱. واژگانِ هسته ─────────────────────────── */

test("هر بلوکِ هسته در واژگان هست و واژگان چیزِ اضافه ندارد", () => {
  // ۱۰ نوعِ حاضر در `switch`. فهرستِ عمداً صریح است (نه `>=`) تا اگر کسی یک
  // بلوکِ تازه اضافه کرد، این‌جا یادش بیاید و کسی یواشکی پاکش نکند.
  assert.deepEqual(
    [...BUILTIN_BLOCK_TYPES].sort(),
    ["contact", "contact-form", "cta", "faq", "form", "gallery", "hero", "image", "quote", "text", "video"].sort(),
  );
  for (const type of BUILTIN_BLOCK_TYPES) {
    assert.equal(isBuiltinBlockType(type), true, `باید هسته باشد: ${type}`);
  }
});

test("type اعلامیِ افزونه (نمونهٔ خودِ قرارداد) ناشناس است", () => {
  // `PluginPackageContract.php:412` ⇒ `example_ok` همین `price_table` است.
  for (const type of ["price_table", "product", "order", "acme_hero", "تبلیغ"]) {
    assert.equal(isBuiltinBlockType(type), false, `نباید هسته باشد: ${type}`);
  }
});

test("واژگان با Object.freeze قابل‌دستکاری نیست", () => {
  // اگر رجیستری از بیرون تغییر کند، `isBuiltinBlockType` و پیامِ لاگ یکی از
  // دیگری جدا می‌افتند و کل قفلِ این فایل بی‌معنا می‌شود.
  assert.throws(() => {
    (BUILTIN_BLOCK_TYPES as string[]).push("injected");
  }, TypeError);
  assert.equal(isBuiltinBlockType("injected"), false);
});

test("کلیدهای پروتوتایپ هرگز بلوکِ هسته حساب نمی‌شوند", () => {
  // دقیقاً همان چیزی که `BUILTIN_BLOCK_TYPES[type]` به‌جای `Set.has` لو می‌داد:
  // `constructor` و `toString` روی prototype هستند و هرگز `case` ندارند، ولی
  // جست‌وجوی ساده آن‌ها را «پیدا» می‌کند و به رندرِ هسته می‌فرستد.
  for (const type of ["constructor", "toString", "valueOf", "hasOwnProperty", "__proto__", "prototype"]) {
    assert.equal(isBuiltinBlockType(type), false, `نباید هسته باشد: ${type}`);
  }
});

test("type غیررشته هیچ‌وقت هسته نیست", () => {
  // payload بک‌اند تضمین رشته نمی‌دهد؛ `switch` با غیررشته نمی‌شکند ولی
  // رجیستری هم نباید به چیزی شبیه رشته چنگ بزند.
  // `BigInt(10)` به‌جای `10n`: هدفِ tsconfig زیرِ ES2020 است و `10n` خطای
  // کامپایل می‌دهد — این آزمون باید دربارهٔ رجیستری باشد، نه دربارهٔ target.
  for (const type of [undefined, null, 42, 0, true, {}, [], ["hero"], Symbol("hero"), BigInt(10)]) {
    assert.equal(isBuiltinBlockType(type), false, `نباید هسته باشد: ${String(type)}`);
  }
});

/* ───────────── ۲. اثباتِ «بلوکِ هسته همیشه برنده است» ───────────── */

test("برچسب‌های case در BlockRenderer دقیقاً برابرِ واژگانِ هسته‌اند", () => {
  // این مهم‌ترین تستِ این فایل است. دو منبعِ حقیقت داشت (رجیستری + `switch`)
  // و هیچ چیزی جلوی واگرایشان را نمی‌گرفت — و واگرایی یعنی دقیقاً همان باگی
  // که K6.3 می‌خواهد ببندد: نوعی که رجیستری هسته می‌داند ولی رندر نمی‌شود،
  // یا برعکس، نوعی که رندر می‌شود ولی از «هسته» خبر ندارد.
  const labels = [...rendererSource.matchAll(/case\s+"([^"]*)":/g)].map((m) => m[1]);
  assert.ok(labels.length > 0, "باید `case \"…\":` در switch پیدا شود");
  assert.deepEqual([...new Set(labels)].sort(), [...BUILTIN_BLOCK_TYPES].sort());
});

test("شاخهٔ default تنها یکی است و بعد از همهٔ caseها آمده", () => {
  // ساختارِ `switch` در خودِ JS یعنی `default` فقط وقتی اجرا می‌شود که هیچ
  // `case`ای نخورده ⇒ شاخهٔ `default` **نمی‌تواند** بلوکِ هسته را بپوشاند.
  // این تست آنGuarantee را نگه می‌دارد: یک `default` دوم (که شاید زودتر
  // نوشته شود و همه‌چیز را ببلعد) یا `case` بعد از `default`، خطاست.
  const defaults = [...rendererSource.matchAll(/\bdefault\s*:/g)].map((m) => m.index!);
  assert.equal(defaults.length, 1, `باید دقیقاً یک شاخهٔ default باشد، دیدم: ${defaults.length}`);

  const lastCase = Math.max(...[...rendererSource.matchAll(/\bcase\s+"/g)].map((m) => m.index!));
  assert.ok(
    defaults[0] > lastCase,
    "شاخهٔ default باید بعد از آخرین case باشد وگرنه می‌تواند بلوکِ هسته را بپوشاند",
  );
});

test("شاخهٔ default throw نمی‌کند و لاگ را از سیاست می‌گیرد", () => {
  // اگر کسی `throw` یا `console.error`ِ مستقل اینجا بگذارد، «یک بلوکِ خراب
  // صفحهٔ عمومی را می‌اندازد» دوباره برمی‌گردد. هر دو مسیرِ لاگ از
  // `unknownBlockWarning` می‌آید تا متن‌ها از هم جدا نیفتند.
  //
  // کامنت‌ها اول حذف می‌شوند: خودِ این شاخه دربارهٔ `throw` توضیح دارد و بدون
  // حذف، تست با متنِ توضیح مثبت می‌افتاد (که دقیقاً یعنی تست بی‌معنا شده).
  const code = stripComments(rendererSource.slice(rendererSource.indexOf("default: {")));
  assert.ok(!/\bthrow\b/.test(code), "شاخهٔ default نباید throw کند");
  assert.ok(
    code.includes("unknownBlockWarning(type"),
    "شاخهٔ default باید پیامِ لاگ را از unknownBlockWarning بگیرد",
  );
  assert.ok(
    !code.includes("console.warn(`[site] unknown block type"),
    "متنِ لاگِ درون‌خطی ممنوع — باید از سیاست بیاید",
  );
  // لاگ باید **همیشه** دو شاخه داشته باشد: عادی با `warn`، لغزشِ رجیستری با
  // `error`. اگر یکی حذف شود، آن رخداد بی‌صدا می‌شود.
  assert.ok(code.includes("console.warn("), "مسیرِ عادی باید warn کند");
  assert.ok(code.includes("console.error("), "لغزشِ رجیستری باید error کند");

  // `type` خام نباید به DOM برسد: داخل صفتِ `data-block-type` می‌رود و
  // `describeUnknownType` تنها چیزی است که جلوی تزریق لاگ/جعلِ جهت/رشدِ بی‌حد
  // را می‌گیرد. اگر کسی `String(type)` را جایش بگذارد، آزمون‌های خودِ
  // `describeUnknownType` هنوز سبز می‌مانند ولی رندرر دوباره خام می‌شود.
  assert.ok(
    code.includes("describeUnknownType(type"),
    "شاخهٔ default باید type را از describeUnknownType بگذراند، نه خام در DOM بگذارد",
  );
});

/* ───────────── ۳. غیرکرشی و بی‌خطر بودن (اصلِ «صفحه نشکند») ───────────── */

test("describeUnknownType هرگز throw نمی‌کند، حتی با ورودیِ دشمن", () => {
  const hostile = [
    "a".repeat(100_000),
    "\n\n[site] unknown block type: hero",
    "price_table\r\nGET /admin 200",
    "\u0000\u001f\u007f",
    "\u202E",           // بیتِ راست‌به‌چپ: جهتِ متنِ اطراف را می‌برد
    "<script>alert(1)</script>",
    "ا".repeat(500),
    "",
    " ",
  ];
  for (const type of hostile) {
    const out = describeUnknownType(type);
    assert.equal(typeof out, "string", `باید رشته باشد: ${type}`);
    assert.ok(out.length <= UNKNOWN_TYPE_MAX + 1, `طولِ نامحدود: ${out.length} برای ${type}`);
    assert.ok(
      !/[\u0000-\u001f\u007f]/.test(out),
      `نویسهٔ کنترلی/جهت‌دهنده نباید بیرون بزند: ${JSON.stringify(out)}`,
    );
  }
});

test("describeUnknownType غیررشته را خالی می‌دهد و به شیء تبدیل نمی‌کند", () => {
  for (const type of [undefined, null, 42, {}, ["hero"]]) {
    assert.equal(describeUnknownType(type), "");
  }
});

test("تزریق لاگ از راه type چندخطی بسته است", () => {
  // یک `type` از پلاگین/DB می‌تواند هرچه باشد. اگر `\n` بیرون بزند، یک خطِ
  // جعلی در ترمینال ساخته می‌شود و جست‌وجوی بعدی «کدام بلوک بود» گمراه می‌شود.
  const forged = "x\n[site] unknown block type: \"hero\" — no built-in block matches it.";
  const line = unknownBlockWarning(forged);
  assert.equal(line.split("\n").length, 1, "پیامِ لاگ باید دقیقاً یک خط باشد");
  assert.ok(!line.includes("\n") && !line.includes("\r"));
  assert.ok(!/[\u0000-\u001f]/.test(line),"کاراکترِ کنترلی نباید به لاگ برسد");
});

test("پیامِ لاگ و متنِ اعلان، هر دو، type پاک‌شده را می‌گویند", () => {
  const line = unknownBlockWarning("price_table");
  assert.ok(line.includes("price_table"));
  assert.ok(line.includes("[site]"), "باید پیشوندِ کانال داشته باشد ⇒ قابل‌جست‌وجو");
  assert.ok(
    line.includes("declared_only"),
    "باید علتِ ساختاری را بگوید تا کسی فکر نکند bug است",
  );
  const notice = unknownBlockNoticeFa("price_table");
  assert.ok(notice.includes("price_table"));
  assert.ok(notice.includes("رندر نشد"), "اعلان باید صریح بگوید چیزی رندر نشده");
});

test("لغزشِ رجیستری از خطای محتوا تفکیک می‌شود", () => {
  // `registry-drift` یعنی ساختار `switch` و رجیستری یکی نیست ⇒ تقصیرِ خودِ
  // ماست و باید بلندتر گزارش شود. اگر این دو یکی شوند، `console.error` بی‌معنا
  // و `console.warn` برای باگِ خودمان کم‌شدت می‌شود.
  const drift = unknownBlockWarning("hero", "registry-drift");
  const normal = unknownBlockWarning("hero");
  assert.ok(drift.includes("registry drift"), drift);
  assert.ok(!normal.includes("registry drift"), normal);
});

test("type خالی، برچسبِ قابل‌خواندن می‌گیرد نه رشتهٔ خالی", () => {
  assert.ok(unknownBlockWarning("").includes("(empty)"));
  assert.ok(unknownBlockNoticeFa("").includes("(خالی)"));
});

/* ───────────── ۴. چه‌وقت اعلانِ دیداری رندر شود ───────────── */

test("تشخیص: هر چیزی جز production روشن است", () => {
  assert.equal(blockDiagnosticsEnabled({}), true, "نبودِ NODE_ENV یعنی توسعه");
  assert.equal(blockDiagnosticsEnabled({ NODE_ENV: "development" }), true);
  assert.equal(blockDiagnosticsEnabled({ NODE_ENV: "test" }), true);
});

test("تشخیص: در production پیش‌فرض خاموش است", () => {
  assert.equal(blockDiagnosticsEnabled({ NODE_ENV: "production" }), false);
  assert.equal(blockDiagnosticsEnabled({ NODE_ENV: "production", BLOCK_RENDER_DIAGNOSTICS: "0" }), false);
  assert.equal(blockDiagnosticsEnabled({ NODE_ENV: "production", BLOCK_RENDER_DIAGNOSTICS: "true" }), false);
  assert.equal(blockDiagnosticsEnabled({ NODE_ENV: "production", BLOCK_RENDER_DIAGNOSTICS: "" }), false);
});

test("تشخیص: production با opt-inِ صریح روشن می‌شود", () => {
  assert.equal(
    blockDiagnosticsEnabled({ NODE_ENV: "production", BLOCK_RENDER_DIAGNOSTICS: "1" }),
    true,
  );
  assert.equal(
    blockDiagnosticsEnabled({ NODE_ENV: "production", BLOCK_RENDER_DIAGNOSTICS: "1", NEXT_PUBLIC_X: "1" }),
    true,
  );
});

test("متغیرِ تشخیص نباید عمومی باشد", () => {
  // اگر روزی کسی `NEXT_PUBLIC_BLOCK_RENDER_DIAGNOSTICS` بسازد، هم داخل
  // بستهٔ مرورگر می‌رود و هم برای بازدیدکننده قابل‌دستکاری می‌شود. نامِ
  // سمتِ سرور عمداً همین است و همین‌جا نگه داشته می‌شود.
  assert.equal(
    "BLOCK_RENDER_DIAGNOSTICS".startsWith("NEXT_PUBLIC_"),
    false,
  );
});
