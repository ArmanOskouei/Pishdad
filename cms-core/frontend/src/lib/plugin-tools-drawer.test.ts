import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

/**
 * K6.2 — نگهبان‌های drawer ابزارها.
 *
 * این تست‌ها روی **کد** نگاه می‌کنند نه روی رفتار رندر، چون رندر در
 * `node --test` ممکن نیست و پروژه کتابخانهٔ تست مرورگر ندارد. هدف، قفل‌کردن
 * سه قاعده‌ای است که به‌سادگی در بازترتیبی از بین می‌روند.
 */

const ROOT = fileURLToPath(new URL("../", import.meta.url));
const read = (p: string) => readFileSync(ROOT + p, "utf8");

const DRAWER = read("components/admin/PluginToolsDrawer.tsx");
const TOPBAR = read("components/layout/Topbar.tsx");
const SHELL = read("components/layout/Shell.tsx");
const REGISTRY_PATH = fileURLToPath(
  new URL(
    "../../../backend/app/Services/Plugins/ManifestRegistry.php",
    import.meta.url,
  ),
);
const REGISTRY = readFileSync(REGISTRY_PATH, "utf8");

test("دکمهٔ ابزارها در هدر سوار شده و فقط یکی است", () => {
  assert.match(TOPBAR, /PluginToolsButton/, "دکمه باید در Topbar باشد.");
  // یک دکمهٔ واحد، نه یکی به‌ازای هر افزونه — وگرنه Topbar به تعداد
  // افزونه‌های نصب‌شده رشد می‌کرد.
  assert.equal(
    (TOPBAR.match(/<PluginToolsButton/g) ?? []).length,
    1,
    "فقط یک دکمهٔ ابزار، نه چندتا.",
  );
});

test("پروفایل برای دروازهٔ پرمیشن تا `Topbar` و `Shell` عبور می‌کند", () => {
  // `user` فقط نام و آواتار دارد؛ اگر `profile` عبور نکند، دروازهٔ پرمیشن
  // همیشه خالی می‌ماند و هیچ ابزاری هرگز دیده نمی‌شود.
  assert.match(TOPBAR, /profile\?:\s*ProfileData\s*\|\s*null/, "Topbar باید profile بگیرد.");
  assert.match(SHELL, /profile\?:\s*ProfileData\s*\|\s*null/, "Shell باید profile بگیرد.");
  assert.match(SHELL, /profile=\{profile\}/, "Shell باید آن را به Topbar بدهد.");
});

test("دروازهٔ پرمیشن ابزارها fail-closed است", () => {
  // نبودن پرمیشن در فهرست یعنی ابزار پنهان است. `!t.permission ||` یعنی
  // ابزار بی‌مجودی که خودش اعلام نکرده باز است — درست، چون قاعدهٔ منو همین
  // است. نکتهٔ مهم این است که ورودی خالی همه را می‌بندد.
  assert.match(
    DRAWER,
    /!t\.permission \|\| granted\.has\(t\.permission\)/,
    "فیلتر باید همین شکل باشد.",
  );
  assert.match(
    DRAWER,
    /new Set\(profile\?\.permissions \?\? \[\]\)/,
    "نبودن پروفایل باید یعنی «هیچ»، نه «همه».",
  );
});

test("بارگذاری در useEffect است، نه شرط در بدنهٔ رندر", () => {
  // قانون پروژه: شرطِ فراخوانی در بدنهٔ رندر یعنی React #301 که کل صفحه را
  // می‌کُشت.
  assert.match(DRAWER, /useEffect\(/, "بارگذاری باید در useEffect باشد.");
  assert.equal(
    /if\s*\(\s*!\s*\w+\s*\)\s*\{?\s*void\s+\w+\(/.test(DRAWER),
    false,
    "شرطِ فراخوانی در بدنهٔ رندر یعنی حلقهٔ رندر.",
  );
});

test("مسیر فقط از رجیستری صفحه‌ها ساخته می‌شود، نه از اعلان افزونه", () => {
  // دلیل `no_href` در قرارداد. اگر این تابع به `tool.href` نگاه کند، یک
  // افزونه می‌تواند به هر مسیری لینک بدهد.
  assert.match(
    DRAWER,
    /notification_id/,
    "تبدیل باید از notification_id باشد.",
  );
  assert.equal(
    /tool\.href|\.href\b(?!\s*:\s*string)/.test(DRAWER.replace(/hrefFor/g, "")),
    false,
    "نباید به href افزونه نگاه کند.",
  );
});

test("ابزار بی‌مقصد کلیک‌پذیر نیست", () => {
  // «کار کرد ولی به جایی نمی‌رسد» همان چیزی است که کل این نقطه می‌خواست از آن
  // جلوگیری کند. یک دکمهٔ بی‌مقصد، کاربر را منتظر نگه می‌دارد.
  assert.match(DRAWER, /aria-disabled/, "کارت بی‌مقصد باید غیرقابل‌کلیک باشد.");
  assert.match(DRAWER, /بدون مقصد/, "باید به کاربر بگوید مقصدی نیست.");
});

test("رجیستری بک‌اند هر دو کانال را می‌خواند", () => {
  assert.match(REGISTRY, /public static function pluginTools/, "تابع باید وجود داشته باشد.");
  assert.match(REGISTRY, /admin\.plugin_tools/, "کانال نقطهٔ اتصال.");
  assert.match(
    REGISTRY,
    /\$manifest\['tools'\]\s*\?\?\s*null/,
    "کانال سطح‌بالا باید خوانده شود.",
  );
  // `href` نباید ساخته شود — قرارداد این نقطه `no_href` دارد.
  const fn = REGISTRY.slice(
    REGISTRY.indexOf("public static function pluginTools"),
    REGISTRY.indexOf("public static function pageTypes"),
  );
  assert.equal(
    /'href'\s*=>|->href/.test(fn),
    false,
    "رجیستری نباید href بسازد — notification_id تنها راه است.",
  );
});
