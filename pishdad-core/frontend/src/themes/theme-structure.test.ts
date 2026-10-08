import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

/**
 * ECO1/F4.1 — نگهبانِ «سه قالب سایت واقعاً متفاوت‌اند».
 *
 * باگِ قبلی این بود که قالب‌ها فقط توکنِ رنگ عوض می‌کردند و هیچ CSS ساختاری
 * نداشتند؛ هر سه یکسان دیده می‌شدند. این تست تضمین می‌کند هر قالب نشانه‌های
 * ساختاریِ مخصوص خودش را داشته باشد، نه فقط رنگ.
 */
const HERE = dirname(fileURLToPath(import.meta.url));
const CSS = readFileSync(resolve(HERE, "../app/globals.css"), "utf8");

test("هر سه قالب سایت بلوک CSS اختصاصی دارند", () => {
  for (const v of ["minimal", "editorial", "commerce"]) {
    assert.match(CSS, new RegExp(`\\.site\\.theme-${v}\\b`), `قالب ${v} هیچ قاعدهٔ ساختاری ندارد`);
  }
});

test("هر قالب امضای ساختاریِ متفاوتی دارد (نه فقط رنگ)", () => {
  // مینیمال: هدرِ وسط‌چینِ عمودی + عرض باریک.
  assert.match(CSS, /\.site\.theme-minimal \.site-header-inner[\s\S]*?flex-direction:\s*column/);
  // تحریری: rule دوبل + drop-cap + خط بالای h2.
  assert.match(CSS, /\.site\.theme-editorial \.site-header\s*\{[^}]*double/);
  assert.match(CSS, /\.site\.theme-editorial[\s\S]*?::first-letter/);
  // فروشگاهی: هدرِ چسبان + کارت‌های hover-lift.
  assert.match(CSS, /\.site\.theme-commerce \.site-header\s*\{[^}]*position:\s*sticky/);
  assert.match(CSS, /\.site\.theme-commerce \.card:hover[\s\S]*?translateY/);
});

test("سه قالب عرضِ بدنهٔ متفاوت دارند", () => {
  const widthOf = (v: string) => {
    const m = new RegExp(`\\.site\\.theme-${v} \\.site-body\\s*\\{[^}]*max-inline-size:\\s*(\\d+)px`).exec(CSS);
    return m ? Number(m[1]) : null;
  };
  const minimal = widthOf("minimal");
  const editorial = widthOf("editorial");
  const commerce = widthOf("commerce");
  assert.ok(minimal && editorial && commerce, "هر قالب باید عرض بدنهٔ صریح داشته باشد");
  assert.equal(new Set([minimal, editorial, commerce]).size, 3, "سه عرض باید متمایز باشند");
  assert.ok(commerce > editorial && editorial > minimal, "ترتیب عرض: مینیمال < تحریری < فروشگاهی");
});
