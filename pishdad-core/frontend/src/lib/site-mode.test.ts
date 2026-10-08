import { test } from "node:test";
import assert from "node:assert/strict";

import {
  SITE_MODE_STORAGE_KEY,
  isSiteMode,
  parseStoredSiteMode,
  resolveSiteMode,
  siteModeBootstrapJs,
  toggleSiteMode,
} from "./site-mode.ts";

/**
 * WF-L3 — حلِ حالتِ روشن/تیرهٔ سایتِ عمومی.
 *
 * این تابعِ خالص هم سوییچِ کلاینتی و هم اسکریپتِ ضد-FOUC را تغذیه می‌کند؛ پس
 * اولویت‌ها (انتخاب کاربر ← تنظیمِ سایت/قالب ← پیش‌فرض) اینجا قفل می‌شوند.
 */

test("انتخاب کاربر بر تنظیم سایت اولویت دارد", () => {
  assert.equal(resolveSiteMode("dark", "light"), "dark");
  assert.equal(resolveSiteMode("light", "dark"), "light");
});

test("در نبود انتخاب، تنظیم سایت/قالب اعمال می‌شود", () => {
  assert.equal(resolveSiteMode(null, "light"), "light");
  assert.equal(resolveSiteMode(undefined, "dark"), "dark");
});

test("system بر اساس ترجیح مرورگر به روشن/تیره نگاشت می‌شود", () => {
  assert.equal(resolveSiteMode(null, "system", true), "light");
  assert.equal(resolveSiteMode(null, "system", false), "dark");
});

test("مقدار نامعتبر یا تنظیم ناشناخته به تیره می‌افتد", () => {
  assert.equal(resolveSiteMode("weird", "nonsense"), "dark");
  assert.equal(resolveSiteMode("", null), "dark");
});

test("parseStoredSiteMode فقط مقادیر معتبر را برمی‌گرداند", () => {
  assert.equal(parseStoredSiteMode("light"), "light");
  assert.equal(parseStoredSiteMode("dark"), "dark");
  assert.equal(parseStoredSiteMode("auto"), null);
  assert.equal(parseStoredSiteMode(42), null);
  assert.equal(isSiteMode("dark"), true);
  assert.equal(isSiteMode("system"), false);
});

test("toggle حالت را جابه‌جا می‌کند", () => {
  assert.equal(toggleSiteMode("dark"), "light");
  assert.equal(toggleSiteMode("light"), "dark");
});

test("اسکریپت ضد-FOUC کلید ذخیره‌سازی را دارد و state/نظرِ خودسر نمی‌سازد", () => {
  const js = siteModeBootstrapJs();
  assert.ok(js.includes(SITE_MODE_STORAGE_KEY), "کلید localStorage باید در اسکریپت باشد");
  assert.ok(js.includes("data-mode"), "اسکریپت باید data-mode را ست کند");
  assert.ok(js.includes("currentScript"), "اسکریپت باید روی همان عنصر .site اجرا شود");
});
