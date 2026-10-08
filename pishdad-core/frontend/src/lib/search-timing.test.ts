import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { SEARCH_DEBOUNCE_MS, SEARCH_MIN_LENGTH, searchReady } from "./search-timing.ts";

/** E78 — آستانهٔ شروع و دبونسِ جستجوی زنده در یک ماژول قفل می‌شود. */

test("حداقل طول ۳ است", () => {
  assert.equal(SEARCH_MIN_LENGTH, 3);
  assert.equal(searchReady(""), false);
  assert.equal(searchReady("  "), false);
  assert.equal(searchReady("ab"), false);
  assert.equal(searchReady("abc"), true);
  assert.equal(searchReady("  abc  "), true, "فاصله‌های دور trim می‌شوند");
  assert.equal(searchReady("دو"), false, "دو حرف فارسی هم کم است");
  assert.equal(searchReady("تست"), true);
  assert.equal(searchReady(null), false);
  assert.equal(searchReady(42), false);
});

test("دبونس ۳۰۰ms است (اجماعِ صنعت، نه حدس)", () => {
  assert.equal(SEARCH_DEBOUNCE_MS, 300);
});

const HERE = dirname(fileURLToPath(import.meta.url));
const read = (p: string) => readFileSync(join(HERE, p), "utf8");

/**
 * گیتِ سطحِ موتور: هر دو موتور باید از همین `searchReady` بخوانند تا هیچ
 * مصرف‌کننده‌ای (حتی آینده) نتواند با کوئریِ کوتاه ریکوئست بزند.
 */
test("هر دو موتور از گیتِ مشترک می‌خوانند", () => {
  for (const f of ["./site-search.ts", "./search-registry.ts"]) {
    const src = read(f);
    assert.match(src, /searchReady\(/, `${f} باید از گیتِ مشترک بخواند.`);
  }
  assert.equal(
    /query\.length < 2/.test(read("./site-search.ts")),
    false,
    "آستانهٔ قدیمیِ ۲ حرف نباید بماند.",
  );
  assert.equal(
    /rawQuery\.trim\(\)\.length >= 2/.test(read("./search-registry.ts")),
    false,
    "آستانهٔ قدیمیِ ۲ حرف نباید بماند.",
  );
});

/** هر پنج نقطهٔ مصرف باید ثابتِ مشترک را بخوانند، نه عددِ دستی. */
test("هر پنج مصرف‌کننده ثابتِ مشترک را می‌خوانند", () => {
  const users = [
    "components/search/AdminSearch.tsx",
    "components/search/SearchResults.tsx",
    "components/site/SiteSearchBox.tsx",
    "components/site/SiteSearchResults.tsx",
    // E85 — انتخاب‌گر صفحه در هدر/فوتر هم هر حرف یک ریکوئست می‌زد.
    "components/admin/LinkListEditor.tsx",
  ];
  for (const f of users) {
    const src = read(`../${f}`);
    assert.match(src, /SEARCH_DEBOUNCE_MS/, `${f} باید دبونسِ مشترک را بخواند.`);
    assert.match(src, /searchReady\(/, `${f} باید گیتِ مشترک را بخواند.`);
    assert.equal(
      /DEBOUNCE_MS = 1[05]0|}, 1[05]0\)|}, 200\)/.test(src),
      false,
      `${f} نباید عددِ دبونسِ دستی داشته باشد.`,
    );
  }
});
