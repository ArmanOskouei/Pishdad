import { test } from "node:test";
import assert from "node:assert/strict";

import {
  isPublicSitePath,
  splitLocalePrefix,
  resolveLocaleRoute,
  parseLocalesHeader,
  localesForHeader,
} from "./public-routing.ts";
import { localizeHref, publicDir, publicHreflang } from "./i18n/public/index.ts";

/**
 * ECO2 — مسیریابیِ زبانِ سایت (مدل a).
 *
 * این‌ها توابعِ خالص‌اند؛ میدل‌ور (`proxy.ts`) و روت‌ها هر دو از همین‌ها
 * استفاده می‌کنند، پس نگهبان اینجا هم برای میدل‌ور و هم برای صفحه معنا دارد.
 */

test("پنل/API/فایل ثابت زبانِ سایت نمی‌گیرند", () => {
  assert.equal(isPublicSitePath("/admin/dashboard"), false);
  assert.equal(isPublicSitePath("/central"), false);
  assert.equal(isPublicSitePath("/api/revalidate"), false);
  assert.equal(isPublicSitePath("/preview"), false);
  assert.equal(isPublicSitePath("/robots.txt"), false);
  assert.equal(isPublicSitePath("/icons/icon.png"), false);
  assert.equal(isPublicSitePath("/about"), true);
  assert.equal(isPublicSitePath("/"), true);
  assert.equal(isPublicSitePath("/en/about"), true);
});

test("پیشوند زبان از مسیر جدا می‌شود؛ زبانِ پایه پیشوند ندارد", () => {
  assert.deepEqual(splitLocalePrefix("/en/about", "fa"), { locale: "en", bare: "/about", hadPrefix: true });
  assert.deepEqual(splitLocalePrefix("/about", "fa"), { locale: "fa", bare: "/about", hadPrefix: false });
  assert.deepEqual(splitLocalePrefix("/en", "fa"), { locale: "en", bare: "", hadPrefix: true });
  // اسلاگی که واقعاً با «en» شروع شده اما صفحه است، پیشوند نیست چون زبان‌ها فهرست‌اند.
  assert.deepEqual(splitLocalePrefix("/energy", "fa"), { locale: "fa", bare: "/energy", hadPrefix: false });
});

test("زبان پایه می‌تواند en باشد (ریشه انگلیسی، fa زیر /fa)", () => {
  assert.deepEqual(splitLocalePrefix("/about", "en"), { locale: "en", bare: "/about", hadPrefix: false });
  assert.deepEqual(splitLocalePrefix("/fa/about", "en"), { locale: "fa", bare: "/about", hadPrefix: true });
});

test("تصمیمِ مسیر: ریشه rewrite، پیشوندِ پایه redirect، زبانِ دوم serve", () => {
  // مسیرِ بدونِ پیشوند → بازنویسیِ داخلی به درختِ زبانِ پایه (رفعِ سایه‌افتادن ۴۰۴).
  assert.deepEqual(resolveLocaleRoute("/about", ["fa", "en"], "fa"), {
    action: "rewrite",
    locale: "fa",
    path: "/fa/about",
  });
  assert.deepEqual(resolveLocaleRoute("/", ["fa", "en"], "fa"), {
    action: "rewrite",
    locale: "fa",
    path: "/fa",
  });
  // پیشوندِ زبانِ پایه → ۳۰۸ به مسیرِ بدونِ پیشوند (جلوگیری از محتوای تکراری).
  assert.deepEqual(resolveLocaleRoute("/fa/about", ["fa", "en"], "fa"), {
    action: "redirect",
    locale: "fa",
    path: "/about",
  });
  // زبانِ دومِ فعال → همان مسیر.
  assert.deepEqual(resolveLocaleRoute("/en/about", ["fa", "en"], "fa"), { action: "serve", locale: "en" });
  assert.deepEqual(resolveLocaleRoute("/en", ["fa", "en"], "fa"), { action: "serve", locale: "en" });
  // تک‌زبانه: پیشوندِ زبانِ نصب‌نشده به مسیرِ پایه برمی‌گردد، نه ۴۰۴.
  assert.deepEqual(resolveLocaleRoute("/en/about", ["fa"], "fa"), {
    action: "redirect",
    locale: "fa",
    path: "/about",
  });
  // زبانِ پایه en: ریشه انگلیسی، fa پیشوند.
  assert.deepEqual(resolveLocaleRoute("/about", ["en", "fa"], "en"), {
    action: "rewrite",
    locale: "en",
    path: "/en/about",
  });
});

test("هدرِ زبان‌ها فقط اعضای معتبر را برمی‌گرداند", () => {
  assert.deepEqual(parseLocalesHeader("fa,en"), ["fa", "en"]);
  assert.deepEqual(parseLocalesHeader("fa, de, en"), ["fa", "en"]);
  assert.deepEqual(parseLocalesHeader(""), []);
  assert.deepEqual(parseLocalesHeader(null), []);
  assert.equal(localesForHeader(["fa", "en"]), "fa,en");
});

test("localizeHref فقط لینک داخلیِ سایتِ غیرِپیش‌فرض را پیشوند می‌زند", () => {
  // زبان پایه: دست‌نخورده.
  assert.equal(localizeHref("/about", "fa"), "/about");
  assert.equal(localizeHref("/", "fa"), "/");
  // زبان دوم: پیشوند.
  assert.equal(localizeHref("/about", "en"), "/en/about");
  assert.equal(localizeHref("/", "en"), "/en");
  // اگر از قبل پیشوند دارد، دوباره نمی‌خورَد.
  assert.equal(localizeHref("/en/about", "en"), "/en/about");
  // پنل و لینک بیرونی و لنگر: دست‌نخورده.
  assert.equal(localizeHref("/admin/x", "en"), "/admin/x");
  assert.equal(localizeHref("https://example.com", "en"), "https://example.com");
  assert.equal(localizeHref("#section", "en"), "#section");
  assert.equal(localizeHref("mailto:a@b.c", "en"), "mailto:a@b.c");
});

test("localizeHref با زبانِ پایهٔ en: ریشه en، پیشوند fa", () => {
  assert.equal(localizeHref("/about", "en", "en"), "/about");
  assert.equal(localizeHref("/", "en", "en"), "/");
  assert.equal(localizeHref("/about", "fa", "en"), "/fa/about");
  assert.equal(localizeHref("/", "fa", "en"), "/fa");
  assert.equal(localizeHref("/fa/about", "fa", "en"), "/fa/about");
});

test("جهت و hreflang از زبان و ثابت‌اند", () => {
  assert.equal(publicDir("fa"), "rtl");
  assert.equal(publicDir("en"), "ltr");
  assert.equal(publicHreflang("fa"), "fa");
  assert.equal(publicHreflang("en"), "en");
});
