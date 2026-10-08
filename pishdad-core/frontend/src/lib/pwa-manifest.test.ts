import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync, statSync, existsSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

/**
 * F5.2 — نگهبان‌های PWA و «بدون Service Worker».
 *
 * این یکی از آن ردیف‌هایی است که **Feature** نبود بلکه **تصمیم** بود: PWA برای
 * پنل یعنی manifest + آیکون، و نه بیشتر. نگهبانِ نبودِ SW برای این است که کسی
 * در یک بازآرایی خوش‌بینانه `sw.js` اضافه نکند و بی‌سروصدا یک لایهٔ کشِ
 * هرگز‌باطل‌نشونده بسازد.
 */

const HERE = dirname(fileURLToPath(import.meta.url));
const FRONT = resolve(HERE, "../..");
const read = (p: string) => readFileSync(resolve(FRONT, p), "utf8");

const MANIFEST = read("src/app/manifest.ts");
const LAYOUT = read("src/app/layout.tsx");

/** کمکی‌های محلی (بدون import تا `node --test` به resolver نیاز نداشته باشد). */
function readDir(dir: string): string[] {
  try {
    return readdirSync(dir);
  } catch {
    return [];
  }
}
function isDir(p: string): boolean {
  try {
    return statSync(p).isDirectory();
  } catch {
    return false;
  }
}

test("manifest باید حالتِ مستقل داشته باشد و به ریشه اشاره کند", () => {
  // `display: standalone` همان چیزی است که «پنل روی صفحهٔ اصلی» را می‌سازد.
  // `browser` یعنی تبِ مرورگر، که فرقِ اصلی را از بین می‌برد.
  assert.match(MANIFEST, /display:\s*"standalone"/, "نصب روی صفحهٔ اصلی بدون standalone یعنی فقط یک تب.");
  assert.match(MANIFEST, /start_url:\s*"\/"/, "شروع از ریشه — نه `/admin` که بدون نشست به لاگین می‌رود.");
  assert.match(MANIFEST, /scope:\s*"\/"/, "دامنهٔ اپ باید کلِ سایت باشد تا مسیرهای /admin هم باز شوند.");
});

test("آیکون‌ها روی دیسک هستند و در manifest اعلام شده‌اند", () => {
  // اعلامِ آیکونی که فایلش نیست = نصب می‌شود با آیکونِ خالی. و بی‌سروصدا.
  const declared = [...MANIFEST.matchAll(/ICON\((\d+)\)/g)].map((m) => Number(m[1]!));
  assert.ok(declared.includes(128), "یک آیکونِ کوچک لازم است (iOS/فهرست).");
  assert.ok(
    declared.some((s) => s >= 192),
    "بدون آیکونِ ≥۱۹۲، کروم پنل را قابل‌نصب نمی‌داند.",
  );
  for (const size of declared) {
    const p = `public/icons/icon-${size}.png`;
    assert.ok(existsSync(resolve(FRONT, p)), `${p} اعلام شده ولی روی دیسک نیست.`);
  }
});

test("layout به manifest و apple-touch-icon لینک می‌دهد", () => {
  assert.match(LAYOUT, /manifest:\s*"\/manifest\.webmanifest"/, "Next باید manifest را در head بگذارد.");
  assert.match(LAYOUT, /rel="apple-touch-icon"/, "iOS از `metadata.icons` برای این استفاده نمی‌کند؛ لینکِ مستقیم لازم است.");
});

test("⛔ Service Worker فقط برای Web Push است — تصمیم برگشته شد", () => {
  // قاعدهٔ قبلی «هیچ Service Worker ای نیست» عمداً برگردانده شد.
  //
  // دلیل: مرورگر برای اعلانِ محتوای تازه به آن نیاز دارد، پس Web Push می‌ماند.
  //
  // اما با یک نگهبان: `sw.js` نباید کش کند. سرویس‌ورکری که راهبردِ
  // cache-first با کشِ پویا داشته باشد، صفحهٔ کهنه سرو می‌کند و عیبِ
  // تنظیمات را پنهان می‌کند. این سرویس‌ورکر فقط نتیجهٔ push را می‌گیرد و
  // هیچ کشی از محتوا نمی‌سازد.
  const SW = resolve(FRONT, "public/sw.js");

  assert.ok(existsSync(SW), "public/sw.js باید وجود داشته باشد؛ بدون آن push کار نمی‌کند.");

  const src = readFileSync(SW, "utf8");

  assert.match(src, /addEventListener\(\s*["']push["']/, "سرویس‌ورکر باید رویداد push را بگیرد.");
  assert.match(src, /showNotification/, "سرویس‌ورکر باید اعلان را نشان دهد.");
  assert.match(
    src,
    /addEventListener\(\s*["']notificationclick["']/,
    "کلیک روی اعلان باید به صفحهٔ مقصد برود.",
  );

  // نگهبانِ سوم: هیچ کشِ محتوایی در سرویس‌ورکر نباید باشد. عمدی است و دلیلش بالا آمد.
  assert.doesNotMatch(
    src,
    /caches\.open\(\s*["'][^"']+["']\s*,\s*\{[\s\S]*?cache\s*:/,
    "سرویس‌ورکر نباید کشِ محتوا باز کند — فقط اعلان را مدیریت می‌کند.",
  );
});
