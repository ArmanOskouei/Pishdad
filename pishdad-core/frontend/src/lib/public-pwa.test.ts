import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync, existsSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

/**
 * F5.1 — PWA برای **سایتِ عمومی**.
 *
 * دامنهٔ تصمیم‌گرفته‌شده: manifest + آیکونِ صفحهٔ اصلی + `apple-touch-icon`.
 * **بدون Service Worker** — نگهبانش در `pwa-manifest.test.ts` است و دست‌نخورده
 * گذاشته شده، چون آن یکی از همان قاعده‌هایی است که یک بازآرایی خوش‌بینانه
 * بی‌سروصدا برمی‌گرداند.
 *
 * چرا این یکی جدا از `pwa-manifest.test.ts` است: آن نگهبان می‌پرسد «آیا نصب
 * ممکن است؟» (ساختار). این می‌پرسد «آیا چیزی که نصب می‌شود **همان سایت** است؟»
 * (هویت) — و این دقیقاً همان‌جایی بود که از قلم افتاده بود.
 */

const HERE = dirname(fileURLToPath(import.meta.url));
const FRONT = resolve(HERE, "../..");
const read = (p: string) => readFileSync(resolve(FRONT, p), "utf8");

const MANIFEST_SRC = read("src/app/manifest.ts");
const LAYOUT = read("src/app/layout.tsx");

/**
 * بدون کامنت‌ها. لازم است: همین فایل در مستنداتش از `name: "پنل مدیریت"` نام
 * می‌برد و یک جست‌وجوی ساده روی متنِ خام، **آن** را برمی‌گرداند — یعنی تست
 * دربارهٔ کامنتِ خودش حرف می‌زد و از فیلدِ واقعی رد می‌شد.
 */
const MANIFEST = MANIFEST_SRC
  .replace(/\/\*[\s\S]*?\*\//g, "")
  .split("\n")
  .filter((line) => !/^\s*\/\//.test(line))
  .join("\n");

/** مقدارِ یک رشته در بلوکِ manifest. */
function field(name: string): string {
  const m = new RegExp(`${name}:\\s*"([^"]*)"`).exec(MANIFEST);
  assert.ok(m, "فیلدِ " + name + " در manifest پیدا نشد.");
  return m[1]!;
}

test("نصب روی صفحهٔ اصلی، سایتِ عمومی را باز می‌کند نه پنل", () => {
  // `/admin` به‌عنوان مقصد یعنی نصبِ سایت، مدیر را به لاگین می‌اندازد.
  assert.equal(field("start_url"), "/", "مقصدِ نصب باید ریشهٔ سایتِ عمومی باشد.");
  assert.equal(field("scope"), "/", "دامنهٔ اپ باید کلِ سایت باشد.");
  assert.equal(field("display"), "standalone", "بدون standalone فقط یک تبِ مرورگر است.");
});

test("هویتِ نصب، هویتِ سایت است نه پنل", () => {
  // رگرسیونِ مشخص: `name`/`short_name` «پنل مدیریت» بودند، پس بازدیدکننده
  // آیکونی با برچسبِ «پنل» روی صفحهٔ اصلی‌اش می‌دید که سایتِ عمومی را باز می‌کرد.
  for (const key of ["name", "short_name", "description"]) {
    assert.doesNotMatch(
      field(key),
      /پنل|مدیریت|administrator|admin panel/i,
      `«${key}» نباید خود را پنل معرفی کند — نصب، سایتِ عمومی را باز می‌کند.`,
    );
  }
  // برچسبِ صفحهٔ اصلی در iOS از `appleWebApp.title` می‌آید، نه از manifest.
  const apple = /appleWebApp:\s*\{[^}]*title:\s*"([^"]*)"/.exec(LAYOUT);
  assert.ok(apple, "`appleWebApp.title` پیدا نشد — بدون آن iOS برچسبِ پیش‌فرضِ URL را می‌گذارد.");
  assert.doesNotMatch(apple[1]!, /پنل|مدیریت|admin/i, "برچسبِ آیکونِ iOS هم نباید «پنل» باشد.");
});

test("آیکون‌های اعلام‌شده روی دیسک هستند — شامل آنچه iOS می‌خواهد", () => {
  // iOS آیکونِ خودش را انتخاب می‌کند و manifest را برای این نمی‌خواند؛
  // `apple-touch-icon` باید فایلِ ۱۸۰+ داشته باشد، وگرنه iOS از اسکرین‌شات
  // صفحه یک مربعِ سفید می‌سازد.
  const apple = /rel="apple-touch-icon"\s+href="([^"]+)"/.exec(LAYOUT);
  assert.ok(apple, "لینکِ صریحِ apple-touch-icon لازم است.");
  // مسیرِ وب `/icons/x.png` ⇒ فایلِ `public/icons/x.png`.
  const applePath = resolve(FRONT, "public" + apple[1]!);
  assert.ok(existsSync(applePath), `${apple[1]} اعلام شده ولی روی دیسک نیست.`);

  for (const size of [...MANIFEST.matchAll(/ICON\((\d+)\)/g)].map((m) => Number(m[1]!))) {
    assert.ok(existsSync(resolve(FRONT, `public/icons/icon-${size}.png`)), `آیکونِ ${size} اعلام شده ولی روی دیسک نیست.`);
  }
});

test("رنگِ manifest با پایهٔ خودِ سایت یکی است، نه با تمِ پنل", () => {
  // `theme_color`/`background_color` در تب/اسپلش iOS دیده می‌شود. اگر با تمِ
  // پنل هم‌خوانی نداشته باشد، سایت در `#0b0f17` باز می‌شود ولی نوارِ سیستم
  // رنگِ دیگری دارد — یعنی همان نشتِ توکن، این‌بار بیرون از مرورگر.
  const siteBg = /--site-bg:\s*([^;]+);/.exec(read("src/app/globals.css"));
  assert.ok(siteBg, "پایهٔ `--site-bg` پیدا نشد.");
  const site = siteBg[1]!.trim();
  assert.equal(field("background_color"), site, "`background_color` باید پایهٔ سایت باشد.");
  assert.equal(field("theme_color"), site, "`theme_color` باید پایهٔ سایت باشد.");
});