import { strict as assert } from "node:assert";
import { test } from "node:test";

import {
  distributeFooter,
  flattenFooter,
  footerColumnCount,
  footerGridWidgets,
  interleaveColumns,
  normalizeFooterPlace,
} from "./footer-layout.ts";

type W = { type: string; place?: unknown; id?: string };

/**
 * E66 — جای ویجت‌های فوتر: بالاتر از ستون‌ها / ستون‌ها / پایین‌تر از ستون‌ها.
 *
 * این ماژول **دو** مصرف‌کننده دارد (بومِ پنل و رندرِ سایتِ عمومی). اگر منطقش
 * بشکند، پنل یک چیز نشان می‌دهد و سایت چیز دیگری — دقیقاً همان هشداری که در
 * تسک آمده بود. پس اینجا رفتارِ قراردادی قفل می‌شود.
 */

test("place ناشناس/غایب ⇒ grid (سازگاری عقب‌رو)", () => {
  assert.equal(normalizeFooterPlace(undefined), "grid");
  assert.equal(normalizeFooterPlace(null), "grid");
  assert.equal(normalizeFooterPlace("COLUMN:2"), "grid");
  assert.equal(normalizeFooterPlace("above"), "above");
  assert.equal(normalizeFooterPlace("below"), "below");
});

test("ستون مؤثر: همان قاعدهٔ بک‌اند", () => {
  assert.equal(footerColumnCount(3, 0), 3, "layout معتبر برنده است");
  assert.equal(footerColumnCount(undefined, 2), 2, "وگرنه تعداد links");
  assert.equal(footerColumnCount(undefined, 0), 1);
  assert.equal(footerColumnCount(undefined, 9), 4, "سقفِ ۴");
  assert.equal(footerColumnCount(7, 2), 2, "columns نامعتبر نادیده می‌رود");
  assert.equal(footerColumnCount(0, 1), 1);
});

test("توزیعِ سه‌ناحیه‌ای + کپی‌رایتِ همیشه-پایین‌تر", () => {
  const items: W[] = [
    { type: "links", id: "l1" },
    { type: "about", place: "above", id: "a1" },
    { type: "links", id: "l2" },
    { type: "newsletter", place: "below", id: "n1" },
    { type: "copyright", id: "c1" },
    { type: "links", id: "l3" },
  ];

  const zones = distributeFooter(items, 2);

  assert.deepEqual(zones.above.map((w) => w.id), ["a1"]);
  assert.deepEqual(zones.columns.map((c) => c.map((w) => w.id)), [["l1", "l3"], ["l2"]]);
  assert.deepEqual(zones.below.map((w) => w.id), ["n1", "c1"], "ناحیهٔ پایین به ترتیبِ آرایه");

  assert.deepEqual(footerGridWidgets(items).map((w) => w.id), ["l1", "l2", "l3"]);
});

test("آرایهٔ صاف با ترتیبِ DOM یکی است (رفت‌وبرگشت اتحاد)", () => {
  const items: W[] = [
    { type: "links", id: "l1" },
    { type: "about", place: "above", id: "a1" },
    { type: "links", id: "l2" },
    { type: "links", id: "l3" },
    { type: "links", id: "l4" },
    { type: "newsletter", place: "below", id: "n1" },
    { type: "copyright", id: "c1" },
  ];

  const zones = distributeFooter(items, 2);
  const flat = flattenFooter(zones);

  // ترتیبِ DOM: بالا → ستون‌ها سطربه‌سطر → پایین.
  assert.deepEqual(flat.map((w) => w.id), ["a1", "l1", "l2", "l3", "l4", "n1", "c1"]);
  assert.deepEqual(distributeFooter(flat, 2), zones, "توزیعِ آرایهٔ صاف همان نواحی را می‌دهد");
});

test("ستون‌ها سطربه‌سطر درهم بافته می‌شوند", () => {
  assert.deepEqual(interleaveColumns([["a", "c", "e"], ["b", "d"]]), ["a", "b", "c", "d", "e"]);
  assert.deepEqual(interleaveColumns([[], []]), []);
});

test("دادهٔ قدیمیِ بدون place مانند قبل در ستون‌ها می‌نشیند", () => {
  const legacy: W[] = [{ type: "about" }, { type: "links" }, { type: "links" }, { type: "copyright" }];
  const zones = distributeFooter(legacy, 2);

  assert.deepEqual(zones.above, []);
  assert.deepEqual(zones.below.map((w) => w.type), ["copyright"]);
  assert.deepEqual(zones.columns.map((c) => c.length), [2, 1]);
});
