import { test } from "node:test";
import assert from "node:assert/strict";
import { ALLOWED_LOCAL_TAGS, REVALIDATE_LEEWAY, isAllowedLocalTag } from "./revalidate.ts";

/**
 * F3 / L-B8 — allowlistِ مسیر محلی revalidate.
 *
 * رگرسیونِ ثبت‌شده: `site-chrome` در `ALLOWED_LOCAL_TAGS` نبود، پس دکمهٔ
 * «فعال‌سازی قالب/کروم» از راه مسیر محلی کشِ عمومی را پاک نمی‌کرد و تا انقضای
 * ISR (۳۰۰ثانیه) کهنه می‌ماند. تست به‌صورت صریح همان تگ را می‌خواهد، پس حذفش
 * دوباره این تست را قرمز می‌کند (mutation-detecting).
 */

/**
 * مستقیماً عضویت در **مجموعه** را می‌سنجد، نه فقط نتیجهٔ `isAllowedLocalTag`:
 * قاعدهٔ prefixِ `site-` هم وجود دارد و اگر کسی `site-chrome` را از مجموعه
 * حذف کند، رفتار همچنان سبز می‌ماند ولی «رفعِ صریحِ L-B8» برمی‌گردد. این
 * assertion دقیقاً همان عقب‌نشینی را می‌گیرد.
 */
test("تگ‌های حیاتیِ کروم/وضعیت/خانه صریحاً در allowlist هستند", () => {
  for (const tag of [
    "site-chrome",
    "site-theme",
    "site-status",
    "site-homepage",
    "pages",
    "theme",
  ]) {
    assert.equal(ALLOWED_LOCAL_TAGS.has(tag), true, `${tag} باید در مجموعه باشد`);
    assert.equal(isAllowedLocalTag(tag), true, `${tag} باید مجاز باشد`);
  }
});

test("تگ‌های page:{slug} با prefix مجازند", () => {
  assert.equal(isAllowedLocalTag("page:home"), true);
  assert.equal(isAllowedLocalTag("page:about-us"), true);
});

test("تگ‌های site-* آینده با prefix مجازند", () => {
  assert.equal(isAllowedLocalTag("site-header-v2"), true);
});

test("تگ‌های نامرتبط رد می‌شوند", () => {
  assert.equal(isAllowedLocalTag("evil"), false);
  assert.equal(isAllowedLocalTag(""), false);
  assert.equal(isAllowedLocalTag("page:"), false);
  assert.equal(isAllowedLocalTag("site-"), false);
});

test("پنجرهٔ تازگی همان ۳۰۰ ثانیهٔ قرارداد است", () => {
  assert.equal(REVALIDATE_LEEWAY, 300);
});
