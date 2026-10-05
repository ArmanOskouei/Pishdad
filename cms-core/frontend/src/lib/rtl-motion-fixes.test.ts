import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

/**
 * F0-B3..B7 — نگهبان‌های اصلاحاتِ ریز.
 *
 * این پنج تا دقیقاً از آن دسته‌ای هستند که «درست کار می‌کنند ولی در بازآرایی
 * بی‌سروصدا برمی‌گردند»: هیچ‌کدام خطا نمی‌دهند، هر سه به یک خط CSS وابسته‌اند و
 * همه در یک فایل جمع شده‌اند که دستِ هرکسی است.
 */

const HERE = dirname(fileURLToPath(import.meta.url));
const read = (p: string) => readFileSync(resolve(HERE, p), "utf8");

const CSS = read("../app/globals.css");
/** کامنت‌ها را برمی‌دارد تا مستنداتِ CSS به «قاعده» شمرده نشوند. */
const CSS_CODE = CSS.replace(/\/\*[\s\S]*?\*\//g, "");
const SWITCH = CSS_CODE.slice(CSS_CODE.indexOf(".switch {"), CSS_CODE.indexOf(".dot {"));

test("B4 — سوییچ با ویژگیِ فیزیکی جابه‌جا نمی‌شود", () => {
  // `translateX` جهت ندارد. در RTL منفی یعنی چپ (درست)، در LTR یعنی چپ (غلط) —
  // یعنی با انتخابِ انگلیسی (F4.4) کلید به سمتِ مخالفِ خواندن می‌پرید.
  assert.equal(
    /translateX/.test(SWITCH),
    false,
    "`translateX` فیزیکی است — با `dir=ltr` برعکس می‌شود. از `inset-inline-start` استفاده کنید.",
  );
  // ۴۴ عرض، ۳ فاصله، ۱۸ قطر ⇒ ۴۴ − ۳ − ۱۸ = ۲۳ در حالت روشن.
  const on = /aria-checked="true"\]\s*::after\s*\{([^}]*)\}/.exec(SWITCH);
  assert.ok(on, "قاعدهٔ حالتِ روشن پیدا نشد.");
  assert.match(
    on[1]!,
    /inset-inline-start:\s*23px/,
    "موقعیتِ حالتِ روشن باید منطقی و ۲۳ باشد.",
  );
  assert.match(
    CSS_CODE,
    /--primary-text/,
    "رنگِ دستهٔ سوییچ باید از توکن بیاید نه مقدارِ ثابت (پوستهٔ روشن، متنِ سفید = ناخوانا).",
  );
});

test("B5 — `.dot` قاعده دارد و ابعادش صریح است", () => {
  // بدون `inline/block-size` یک `<span>` خالی ارتفاع صفر دارد و نامرئی است.
  const dot = CSS_CODE.slice(CSS_CODE.indexOf(".dot {"), CSS_CODE.indexOf("}", CSS_CODE.indexOf(".dot {")));
  assert.match(dot, /inline-size:\s*\d/, "عرض باید صریح باشد.");
  assert.match(dot, /block-size:\s*\d/, "ارتفاع باید صریح باشد — وگرنه نقطه نامرئی است.");
  assert.match(dot, /border-radius:\s*50%/, "باید گرد باشد.");
  assert.match(dot, /flex:\s*0 0 auto/, "در ردیفِ فِلکس نباید له شود.");
  // رنگ‌ها از توکن، نه هاردکد.
  for (const cls of [".dot.on", ".dot.ok", ".dot.warn", ".dot.err"]) {
    assert.match(CSS_CODE, new RegExp(`${cls.replace(/\./g, "\\.").replace(" ", " ")}\\s*\\{[^}]*var\\(--`), `${cls} باید رنگش از توکن بیاید.`);
  }
});

test("B6 — هر سه حالتِ گم‌شدهٔ Stepper قاعده و کلاس دارند", () => {
  for (const st of ["failed", "locked", "skipped"]) {
    assert.match(CSS_CODE, new RegExp(`\\.step\\.${st}`), `قاعدهٔ .step.${st} وجود ندارد — یعنی کلاس در JSX بی‌اثر است.`);
    assert.match(CSS_CODE, new RegExp(`\\.step-line\\.${st}`), `قاعدهٔ .step-line.${st} وجود ندارد.`);
  }
  // «قفل» نباید فقط کم‌رنگ باشد؛ `Stepper` باید `aria-disabled` هم بدهد.
  const stepper = read("../components/ui/primitives.tsx");
  assert.match(stepper, /aria-disabled/, "حالتِ قفل باید برای صفحه‌خوان اعلام شود، نه فقط با رنگ.");
  assert.match(stepper, /"failed"/, "فاز خطا باید به وضعیتِ مرحله نگاشت شود.");

  // و منبعِ وضعیت باید `phase` باشد، نه شمارندهٔ موازی — وگرنه دو نگاه از
  // وضعیت داریم و یکی از آن‌ها کهنه می‌ماند.
  const confirm = read("../components/ui/ConfirmStepper.tsx");
  assert.match(confirm, /states=\{states\}/, "ConfirmStepper باید وضعیتِ مرحله را بدهد، نه شماره.");
  assert.match(confirm, /return "failed";/, "فاز خطا باید «شکست‌خورده» بدهد.");
});

test("B7 — پوششِ `prefers-reduced-motion` کلِ انیمیشن‌ها را می‌گیرد", () => {
  const rm = CSS_CODE.slice(CSS_CODE.indexOf("@media (prefers-reduced-motion: reduce)"));
  assert.match(rm, /animation-duration:\s*0\.01ms\s*!/, "بدون `!important` برنده به ترتیب فایل بستگی دارد — یعنی به شانس.");
  assert.match(rm, /transition-duration:\s*0\.01ms\s*!/, "ترنزیشن‌ها هم باید خاموش شوند.");
  assert.match(rm, /scroll-behavior:\s*auto\s*!/, "پیمایشِ نرم خودش یک نوع حرکت است.");

  // و هر انیمیشن/ترنزیشنِ پروژه باید زیر همین media query بی‌اثر شود.
  // اگر قاعدهٔ تازه‌ای بیرون اضافه شد که پوشش داده نشده، این تست صدا می‌زند.
  const animated = [...CSS_CODE.matchAll(/^\s*([.#][\w-]+)[^{]*\{[^}]*(?:animation:|transition:)/gm)].map((m) => m[1]!);
  for (const sel of animated) {
    const bare = sel.replace(/^([.#])/, "").replace(/[^\w-]/g, "");
    const covered =
      new RegExp(`${bare.replace(/[-]/g, "\\-")}\\s*\\{[^}]*transition:\\s*none`).test(rm) ||
      new RegExp(`\\*\\s*,[\\s\\S]*?\\*::after\\s*\\{[\\s\\S]*?transition-duration`).test(rm);
    assert.ok(covered, `«${sel}» انیمیشن دارد ولی زیر prefers-reduced-motion خاموش نشده.`);
  }
});

test("B3 — `relativeFa` دقتِ روز را صریح گفته", () => {
  const fa = read("./fa.ts");
  const doc = fa.slice(fa.lastIndexOf("/**", fa.indexOf("export function relativeFa")));
  assert.match(doc, /روز/, "دقتِ روز باید در مستند آمده باشد — وگرنه کسی «۳ ساعت پیش» را «امروز» می‌بیند و فکر می‌کند باگ است.");
  assert.match(doc, /jalali\(\)/, "باید بگوید برای «دقیقاً کِی» از `jalali()` استفاده کن.");
  // گرد کردن فقط یک‌بار اعمال شود — دو بار یعنی خطای نیم‌روز.
  const body = fa.slice(fa.indexOf("export function relativeFa")).replace(/\/\/.*$/gm, "");
  assert.equal(
    (body.match(/Math\.round/g) ?? []).length,
    1,
    "یک گرد کردن، نه دو تا.",
  );

  // و فیدِ اعلان باید نسخهٔ دقیق‌تر را داشته باشد، وگرنه «۵ دقیقه پیش» و
  // «۲۰ ساعت پیش» هر دو «امروز» می‌شوند — همان شکایتی که B3 را ساخت.
  assert.match(fa, /export function relativeFaFine/, "برای فید اعلان باید تابعِ دقیق (دقیقه/ساعت) وجود داشته باشد.");
  const fine = fa.slice(fa.indexOf("export function relativeFaFine"));
  assert.match(fine, /دقیقه/, "نسخهٔ دقیق باید دقیقه بشمارد.");
  assert.match(fine, /ساعت/, "نسخهٔ دقیق باید ساعت بشمارد.");
  const inbox = read("../app/(client)/admin/notifications/NotificationInbox.tsx");
  assert.match(inbox, /relativeFaFine\(/, "فید اعلان باید از نسخهٔ دقیق استفاده کند.");
});
