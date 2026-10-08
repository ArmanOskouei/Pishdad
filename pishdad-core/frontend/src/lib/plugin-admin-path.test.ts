import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

import {
  PLUGIN_ADMIN_ROOT,
  PluginAdminPathError,
  assertPluginAdminPath,
  buildPluginAdminPath,
  isGuardedAdminPath,
  isPluginAdminPath,
} from "./plugin-admin-path.ts";

/**
 * K6.6 — قید: مسیر صفحهٔ پلاگین باید زیر `/admin/` باشد.
 *
 * این تست عمداً «الگوی راست» بک‌اند (`PluginPackageContract.php:178`) را
 * تکرار می‌کند تا اگر آن قاعده عوض شد، این هم لنگ بیفتد و اختلاف بی‌صدا
 * بین دو لایه ساخته نشود.
 */

test("قید: مسیر معتبر زیر /admin/ پذیرفته می‌شود", () => {
  for (const good of [
    "/admin",
    "/admin/shop",
    "/admin/shop/orders",
    "/admin/plugins/acme/orders",
    "/admin/a.b_c-d",
  ]) {
    assert.equal(isPluginAdminPath(good), true, `باید معتبر باشد: ${good}`);
  }
});

test("قید: مسیر بیرون از /admin/ رد می‌شود", () => {
  for (const bad of [
    "/sampleplug/shop",       // ریشهٔ مرکزی، نه ادمین
    "/shop/orders",        // بدون ریشه
    "/api/admin/shop",     // زیر api
    "/login",
    "/",
  ]) {
    assert.equal(isPluginAdminPath(bad), false, `باید رد شود: ${bad}`);
  }
});

test("قید: مسیر با پیشوند دیگری رد می‌شود", () => {
  for (const bad of ["/adminx", "/adminshop", "/admin2"]) {
    assert.equal(isPluginAdminPath(bad), false, `باید رد شود: ${bad}`);
  }
});

test("قید: نشانی بیرونی و اسکریپت درون‌خطی رد می‌شود", () => {
  for (const bad of [
    "http://evil.com",        // مطلق
    "//evil.com",             // protocol-relative ⇒ open redirect
    "javascript:alert(1)",
    "data:text/html,x",
    "vbscript:msgbox",
    "  /admin/shop",          // فاصلهٔ ابتدا (بعد از trim می‌شود ولی نباید بپذیرد)
  ]) {
    assert.equal(isPluginAdminPath(bad), false, `باید رد شود: ${bad}`);
  }
});

test("قید: traversal رد می‌شود حتی اگر الگوی نویسه‌ای آن را بپذیرد", () => {
  // `[a-z0-9._-]` نقطه را می‌پذیرد، پس `..` از الگو رد نمی‌شود — مثل
  // قاعدهٔ `is_path` در PluginPackageContract.php:1208 که صریح می‌گیرد.
  for (const bad of ["/admin/../x", "/admin/..", "/admin/a/../b", "/admin/../admin"]) {
    assert.equal(isPluginAdminPath(bad), false, `باید رد شود: ${bad}`);
  }
});

test("قید: مقدار غیررشته و طول خارج از بازه رد می‌شود", () => {
  assert.equal(isPluginAdminPath(undefined), false);
  assert.equal(isPluginAdminPath(null), false);
  assert.equal(isPluginAdminPath(42), false);
  assert.equal(isPluginAdminPath({}), false);
  assert.equal(isPluginAdminPath(["/admin/shop"]), false);
  assert.equal(isPluginAdminPath(""), false);
  assert.equal(isPluginAdminPath(`/admin/${"a".repeat(400)}`), false);
});

test("assertPluginAdminPath رشتهٔ معتبر را عیناً برمی‌گرداند", () => {
  assert.equal(assertPluginAdminPath("/admin/shop/orders"), "/admin/shop/orders");
});

test("assertPluginAdminPath به‌جای نرمال‌سازی پنهان، خطا می‌دهد", () => {
  // اگر این به‌جای throw مقدارِ اصلاح‌شده برمی‌گرداند، مسیر اعلام‌شده بی‌صدا
  // عوض می‌شد و خطای اصلی از دست می‌رفت.
  assert.throws(() => assertPluginAdminPath("/sampleplug/shop"), PluginAdminPathError);
  assert.throws(() => assertPluginAdminPath("//evil.com"), PluginAdminPathError);
  assert.throws(() => assertPluginAdminPath("/admin/../x"), PluginAdminPathError);
});

test("buildPluginAdminPath همیشه زیر /admin/ می‌سازد", () => {
  assert.equal(buildPluginAdminPath("shop"), "/admin/shop");
  assert.equal(buildPluginAdminPath("plugins", "acme", "orders"), "/admin/plugins/acme/orders");
});

test("buildPluginAdminPath نتواند از ریشه بیرون بزند", () => {
  assert.throws(() => buildPluginAdminPath(".."), PluginAdminPathError);
  assert.throws(() => buildPluginAdminPath("."), PluginAdminPathError);
  assert.throws(() => buildPluginAdminPath(""), PluginAdminPathError);
  assert.throws(() => buildPluginAdminPath("Shop"), PluginAdminPathError);   // حرف بزرگ
  assert.throws(() => buildPluginAdminPath("-shop"), PluginAdminPathError);  // با خط تیره شروع می‌شود
  assert.throws(() => buildPluginAdminPath("shop/orders"), PluginAdminPathError); // اسلش در segment
  assert.throws(() => buildPluginAdminPath(), PluginAdminPathError);
});

test("سازنده و نگهبان یک فضا دارند: هر مسیر ساخته‌شده زیر ریشهٔ محافظت‌شده است", () => {
  // این همان چیزی است که K6.6 را «ساختاری» می‌کند: `proxy.ts` matcher را از
  // `PLUGIN_ADMIN_ROOT` می‌سازد، پس اگر این تست بیفتد یعنی جایی مسیر ساخته
  // می‌شود که پروکسی محافظش نیست.
  for (const segments of [["shop"], ["plugins", "acme"], ["a", "b", "c"]]) {
    const built = buildPluginAdminPath(...segments);
    assert.equal(isGuardedAdminPath(built), true, `محافظت‌نشده: ${built}`);
  }
  assert.equal(isGuardedAdminPath(PLUGIN_ADMIN_ROOT), true, "خودِ ریشه هم باید محافظت شود");
  assert.equal(isGuardedAdminPath("/sampleplug/shop"), false);
  assert.equal(isGuardedAdminPath("/adminx"), false, "پیشوند باید مرزی باشد");
});

test("هر رشتهٔ پذیرفته‌شده توسط assert در فضای محافظت‌شده می‌افتد", () => {
  // اتصال دو دروازه: چیزی که مانیفست می‌تواند اعلام کند ⇒ حتماً زیر ریشه.
  for (const declared of ["/admin", "/admin/shop", "/admin/plugins/acme/orders"]) {
    assert.equal(isGuardedAdminPath(assertPluginAdminPath(declared)), true);
  }
});

/**
 * قفلِ پوششِ پروکسی برای ریشهٔ سازندهٔ مسیر.
 *
 * چرا متن فایل خوانده می‌شود و نه `import`: Next فیلد `config.matcher` را
 * **ایستا** پارس می‌کند و هر چیز غیررشته‌ای را با خطای build رد می‌کند.
 *
 * ## L-B12 — چرا این قفل عوض شد
 *
 * قبلاً شرط این بود که matcher **دقیقاً** شامل `/admin/:path*` باشد. آن زمان
 * matcher ایستا بود و همین تست می‌شد با `import` هم نوشت.
 *
 * حالا فهرستِ ریشه‌های محافظت‌شده از محیط می‌آید
 * (`NEXT_PUBLIC_PANEL_ROOTS`) تا هستهٔ منتشرشده نامِ افزونهٔ خصوصی را در خود
 * نداشته باشد. نتیجه: `matcher` باید `"/:path*"` باشد — یک wildcard که همه‌چیز
 * را می‌گیرد — و تصمیم به تابع `proxy()` منتقل شده.
 *
 * ⛔ **بزرگ‌ترین خطرِ این تغییر** این است که کسی wildcard را بگذارد ولی
 * تابع، ریشهٔ ادمین را از قلم بیندازد. آن‌وقت `/admin/*` بدون دروازهٔ کوکی
 * سرو می‌شود. برای همین این تست حالا هر دو چیز را می‌سنجد: wildcard هست، **و**
 * تابع، `admin` را در مجموعهٔ ریشه‌ها نگه می‌دارد.
 */
test("قفل: پروکسی هر مسیری را می‌گیرد و ریشهٔ ادمین را در دروازه نگه می‌دارد", () => {
  const proxySource = readFileSync(
    fileURLToPath(new URL("../proxy.ts", import.meta.url)),
    "utf8",
  );
  const matcher = /matcher:\s*\[([^\]]*)\]/.exec(proxySource);
  assert.ok(matcher, "باید matcher در proxy.ts تعریف شده باشد");

  const entries = [...matcher[1].matchAll(/["'`]([^"'`]*)["'`]/g)].map((m) => m[1]);
  assert.ok(entries.length > 0, "matcher نباید خالی باشد");

  // wildcard باید همه‌چیز را بگیرد، وگرنه ریشه‌های محیطی اصلاً به پروکسی نمی‌رسند.
  assert.ok(
    entries.includes("/:path*"),
    `matcher باید wildcard باشد تا ریشه‌های اعلام‌شده در محیط پوشش داده شوند؛ فعلی: ${JSON.stringify(entries)}`,
  );

  // ⚠️ بخش حیاتی: اگر ریشه‌های محیطی اضافه شدند ولی `admin` از قلم افتاد،
  // پنل مشتری بی‌دروازه سرو می‌شود. باید صریحاً نگه داشته شود.
  assert.match(
    proxySource,
    /new Set\(\[\s*PLUGIN_ADMIN_ROOT|"admin"/,
    "تابع پروکسی باید ریشهٔ ادمین را در مجموعهٔ ریشه‌های محافظت‌شده نگه دارد.",
  );

  // و تصمیم باید **پیش از** بررسی کوکی گرفته شود، وگرنه مسیرهای غیرپنلی هم
  // ریدایرکت می‌شوند.
  //
  // ⚠️ فقط **بدنهٔ تابع** بررسی می‌شود، نه کل فایل: `inspectSessionToken` در
  // خطِ import بالای فایل می‌آید، پس جست‌وجوی سراسری همیشه جواب غلط می‌داد.
  const bodyStart = proxySource.search(/export (?:async )?function proxy/);
  const body = proxySource.slice(bodyStart);
  const guardAt = body.search(/panelRoots\(\)\.has|panelRoots\(\)/);
  const cookieAt = body.indexOf("inspectSessionToken");
  assert.ok(guardAt !== -1, "شرطِ ریشه باید در بدنهٔ proxy باشد");
  assert.ok(cookieAt !== -1, "بررسیِ کوکی باید در بدنهٔ proxy باشد");
  assert.ok(
    guardAt < cookieAt,
    "بررسی ریشه باید پیش از بررسی کوکی باشد وگرنه صفحات عمومی هم ریدایرکت می‌شوند.",
  );
});

