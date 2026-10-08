import { test } from "node:test";
import assert from "node:assert/strict";

import { notFoundSuggestions } from "./not-found.ts";
import { publicT } from "./i18n/public/index.ts";

/**
 * WF-M18 — انتخابِ پیوندهای پیشنهادیِ ۴۰۴.
 *
 * تابعِ خالص است و روتِ سرور هم از همان استفاده می‌کند؛ پس این نگهبان رفتارِ
 * واقعیِ صفحه را قفل می‌کند (نه یک کپیِ موازی).
 */

test("خانه همیشه اولین پیوند است و برای زبانِ پایه پیشوند ندارد", () => {
  const out = notFoundSuggestions([], "fa", "fa");
  assert.equal(out[0]?.href, "/");
  assert.equal(out[0]?.label, publicT("fa", "chrome.home"));
});

test("زبانِ دوم پیوندها را پیشونددار می‌کند", () => {
  const out = notFoundSuggestions([{ slug: "about", title: "About" }], "en", "fa");
  assert.equal(out[0]?.href, "/en");
  assert.equal(out[1]?.href, "/en/about");
  assert.equal(out[1]?.label, "About");
});

test("صفحه‌های sitemap پس از خانه می‌آیند و سقف رعایت می‌شود", () => {
  const pages = Array.from({ length: 10 }, (_, i) => ({ slug: `p${i}`, title: `P${i}` }));
  const out = notFoundSuggestions(pages, "fa", "fa", 4);
  assert.equal(out.length, 4);
  assert.deepEqual(
    out.map((s) => s.href),
    ["/", "/p0", "/p1", "/p2"],
  );
});

test("اسلاگِ خالی/تکراری حذف می‌شود", () => {
  const out = notFoundSuggestions(
    [
      { slug: "", title: "خانه" },
      { slug: "   ", title: "خالی" },
      { slug: "about", title: "درباره" },
      { slug: "about", title: "درباره دوباره" },
    ],
    "fa",
    "fa",
  );
  assert.deepEqual(
    out.map((s) => s.href),
    ["/", "/about"],
  );
});

test("در نبودِ صفحه، پشتیبانِ جستجو اضافه می‌شود تا حداقل دو پیوند باشد", () => {
  const out = notFoundSuggestions([], "fa", "fa");
  assert.equal(out.length, 2);
  assert.equal(out[1]?.href, "/search");
  assert.equal(out[1]?.label, publicT("fa", "search.submit"));
});

test("اسلاگ با اسلشِ ابتدایی/انتهایی تمیز می‌شود", () => {
  const out = notFoundSuggestions([{ slug: "/contact/", title: "تماس" }], "fa", "fa");
  assert.equal(out[1]?.href, "/contact");
});
