import { test } from "node:test";
import assert from "node:assert/strict";
import {
  asStoreItem,
  asStoreList,
  storeAction,
  formatPrice,
  type StoreItem,
} from "./store.ts";

/**
 * ECO6 — منطق فروشگاه.
 *
 * این تست مهم است چون سه واقعیت را قفل می‌کند که در UI می‌توانند بی‌سروصدا
 * دروغ بگویند: (۱) تحویل یک‌باره ⇒ بعد از تحویل، دکمهٔ دانلود دیگر نباید باشد؛
 * (۲) پاسخ ناقص نباید آیتم را با مقادیر جعلی نشان دهد؛ (۳) «رایگان» از «صفر
 * واقعی» تمیز باشد.
 */

const base: StoreItem = {
  id: 1, name: "A", slug: "a", version: "1.0.0", description: null,
  price: 50000, currency: "IRT",
  delivery: { owned: false, delivered: false, deliverable: false, checksum: null },
};

test("پاسخ ناقص آیتم نمی‌سازد", () => {
  assert.equal(asStoreItem(null), null);
  assert.equal(asStoreItem({ name: "x" }), null, "بدون id/slug نباید آیتم بسازد.");
  assert.equal(asStoreItem({ id: 1 }), null);
});

test("پاسخ کامل با پیش‌فرض‌های محافظه‌کارانه ساخته می‌شود", () => {
  const item = asStoreItem({ id: 7, slug: "s", name: "N", version: "2", price: 100, currency: "IRT" });
  assert.ok(item);
  // `delivery` غایب ⇒ همه false، نه undefined — وگرنه UI می‌توانست
  // دکمهٔ دانلود را روی undefined رندر کند.
  assert.deepEqual(item.delivery, { owned: false, delivered: false, deliverable: false, checksum: null });
});

test("تحویل یک‌باره: بعد از تحویل، دکمهٔ دانلود نیست", () => {
  const delivered = { ...base, delivery: { owned: true, delivered: true, deliverable: false, checksum: "abc" } };
  assert.equal(storeAction(delivered), "already_delivered");
});

test("تحویل‌پذیر ⇒ دانلود", () => {
  const ready = { ...base, delivery: { owned: true, delivered: false, deliverable: true, checksum: null } };
  assert.equal(storeAction(ready), "download");
});

test("پولی و نخریده ⇒ خرید؛ رایگان و نخریده ⇒ دریافت", () => {
  assert.equal(storeAction(base), "buy");
  assert.equal(storeAction({ ...base, price: 0 }), "get");
});

test("قیمت صفر «رایگان» می‌شود، نه ۰ تومان", () => {
  assert.equal(formatPrice(0, "IRT"), "رایگان");
  assert.equal(formatPrice(-5, "IRT"), "رایگان");
  assert.match(formatPrice(50000, "IRT"), /تومان/);
});

test("asStoreList هم آرایهٔ خام و هم بستهٔ data را می‌خواند", () => {
  assert.equal(asStoreList([{ id: 1, slug: "a" }]).length, 1);
  assert.equal(asStoreList({ data: [{ id: 1, slug: "a" }] }).length, 1);
  assert.equal(asStoreList(null).length, 0);
});
