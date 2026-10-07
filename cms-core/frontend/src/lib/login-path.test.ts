import { test } from "node:test";
import assert from "node:assert/strict";

import { isLoginPath, LOGIN_PATH } from "./login-path.ts";

/**
 * رگرسیونِ «صفحهٔ ورود به خودش ریدایرکت می‌شود».
 *
 * ## چرا این خطرناک بود
 *
 * لاگین از `/login` به `/admin/login` منتقل شد — یعنی **داخل** ریشه‌ای که
 * `proxy.ts` می‌بندد. بدون استثنا:
 *
 *     /admin/dashboard → /admin/login → /admin/login → … → بی‌نهایت
 *
 * یعنی هیچ‌کس هرگز نمی‌توانست وارد پنل شود — و هیچ تستی هم نمی‌دید، چون
 * مسیر فقط در سرور دنبال می‌شود.
 */

test("the login path is the agreed address", () => {
  assert.equal(LOGIN_PATH, "/admin/login");
});

test("the login page itself is recognised", () => {
  assert.equal(isLoginPath("/admin/login"), true);
});

test("a trailing slash is still the login page", () => {
  // مرورگر و لینک‌های دستی گاهی اسلشِ انتهایی می‌گذارند؛ اگر این نشناخته
  // شود، حلقهٔ ریدایرکت ساخته می‌شود.
  assert.equal(isLoginPath("/admin/login/"), true);
  assert.equal(isLoginPath("/admin/login///"), true);
});

test("protected panel pages are NOT the login page", () => {
  // هر یک از این‌ها اگر اشتباهی login تشخیص داده شود، دروازهٔ پنل باز می‌ماند.
  for (const p of [
    "/admin",
    "/admin/dashboard",
    "/admin/plugins",
    "/admin/settings",
    "/admin/login-history",
  ]) {
    assert.equal(isLoginPath(p), false, `${p} نباید صفحهٔ ورود باشد`);
  }
});

test("a similarly-named path does not slip through the exception", () => {
  // ⭐ `startsWith` اینجا یک سوراخ امنیتی می‌ساخت: `/admin/login-help` یک
  // صفحهٔ واقعیِ پنل است که بدون دروازه باز می‌ماند.
  assert.equal(isLoginPath("/admin/login-help"), false);
  assert.equal(isLoginPath("/admin/loginx"), false);
  assert.equal(isLoginPath("/admin/login-help/"), false);
});

test("unrelated paths are not the login page", () => {
  for (const p of ["/", "/about", "/search", "/sampleplug/login", "/login"]) {
    assert.equal(isLoginPath(p), false, `${p} نباید صفحهٔ ورود باشد`);
  }
});

test("the root path normalises safely instead of becoming empty", () => {
  // `"/".replace(/\/+$/, "")` می‌شود `""` و `|| "/"` آن را نجات می‌دهد.
  assert.equal(isLoginPath("/"), false);
});
