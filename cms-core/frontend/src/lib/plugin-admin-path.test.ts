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
    "/central/shop",       // ریشهٔ مرکزی، نه ادمین
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
  assert.throws(() => assertPluginAdminPath("/central/shop"), PluginAdminPathError);
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
  assert.equal(isGuardedAdminPath("/central/shop"), false);
  assert.equal(isGuardedAdminPath("/adminx"), false, "پیشوند باید مرزی باشد");
});

test("هر رشتهٔ پذیرفته‌شده توسط assert در فضای محافظت‌شده می‌افتد", () => {
  // اتصال دو دروازه: چیزی که مانیفست می‌تواند اعلام کند ⇒ حتماً زیر ریشه.
  for (const declared of ["/admin", "/admin/shop", "/admin/plugins/acme/orders"]) {
    assert.equal(isGuardedAdminPath(assertPluginAdminPath(declared)), true);
  }
});

/**
 * قفلِ هم‌بودنِ ریشهٔ پروکسی و ریشهٔ سازندهٔ مسیر.
 *
 * چرا متن فایل خوانده می‌شود و نه `import`: Next فیلد `config.matcher` را
 * **ایستا** پارس می‌کند و هر چیز غیررشته‌ای (از جمله
 * `` `${PLUGIN_ADMIN_ROOT}/:path*` ``) را با خطای build رد می‌کند. یعنی
 * نمی‌شود این دو را با یک ثابت به هم بست. این تست همان رشتهٔ استاتیک را
 * می‌خواند و با ریشهٔ سازنده مقایسه می‌کند ⇒ اگر کسی ریشهٔ matcher یا ریشهٔ
 * سازنده را عوض کند، اینجا می‌افتد و صفحه‌ای بیرون از پوشش پروکسی ساخته
 * نمی‌شود.
 */
test("ریشهٔ پروکسی و ریشهٔ سازندهٔ مسیر یکی است", () => {
  const proxySource = readFileSync(
    fileURLToPath(new URL("../proxy.ts", import.meta.url)),
    "utf8",
  );
  const matcher = /matcher:\s*\[([^\]]*)\]/.exec(proxySource);
  assert.ok(matcher, "باید matcher در proxy.ts تعریف شده باشد");

  const entries = [...matcher[1].matchAll(/["'`]([^"'`]*)["'`]/g)].map((m) => m[1]);
  assert.ok(entries.length > 0, "matcher نباید خالی باشد");

  // ورودیِ محافظت‌شده باید دقیقاً ریشهٔ اجباری + صفر-یا-بیشتر باشد.
  assert.ok(
    entries.includes(`${PLUGIN_ADMIN_ROOT}/:path*`),
    `proxy.ts باید ${PLUGIN_ADMIN_ROOT}/:path* را محافظت کند؛ matcher فعلی: ${JSON.stringify(entries)}`,
  );

  // و هیچ ورودیِ دیگری نباید ریشهٔ ادمین را دوباره تعریف کند.
  for (const entry of entries) {
    const root = entry.split("/:")[0];
    if (root !== PLUGIN_ADMIN_ROOT && root.startsWith("/admin")) {
      throw new Error(`ریشهٔ دوم و مبهم در matcher پروکسی: ${entry}`);
    }
  }
});

