import { test } from "node:test";
import assert from "node:assert/strict";
import {
  asStoreCategories,
  asStoreDetail,
  asStoreItem,
  asStoreList,
  asStoreReviews,
  asStoreVersions,
  storeAction,
  formatPrice,
  formatRating,
  filledStars,
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
  price: 50000, currency: "IRT", active: false, review_status: "approved",
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
  // وضعیت نصب/بازبینی نامعلوم ⇒ محافظه‌کارانه: غیرفعال و بدون بازبینی.
  assert.equal(item.active, false);
  assert.equal(item.review_status, null);
});

test("وضعیت فعال/بازبینی از پاسخ خوانده می‌شود", () => {
  const active = asStoreItem({ id: 3, slug: "a", active: true, review_status: "approved" });
  assert.equal(active?.active, true);
  assert.equal(active?.review_status, "approved");

  // مقدار ناشناختهٔ بازبینی نباید به UI برود.
  const weird = asStoreItem({ id: 4, slug: "b", active: 1, review_status: "bogus" });
  assert.equal(weird?.active, true);
  assert.equal(weird?.review_status, null);
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

// ── WF-H16 — فیلدهای غنی + صفحهٔ جزئیات ──

test("متادیتای غنی مانیفست خوانده می‌شود و در نبودش جعلی نمی‌شود", () => {
  const rich = asStoreItem({
    id: 9, slug: "s", category: "analytics",
    screenshots: ["https://x/a.png", 42, "https://x/b.png"],
    long_description: "توضیح کامل", changelog: "1.2.3 — تغییر",
  });
  assert.ok(rich);
  assert.equal(rich.category, "analytics");
  assert.deepEqual(rich.screenshots, ["https://x/a.png", "https://x/b.png"]);
  assert.equal(rich.long_description, "توضیح کامل");
  assert.equal(rich.changelog, "1.2.3 — تغییر");

  const bare = asStoreItem({ id: 10, slug: "t" });
  assert.ok(bare);
  assert.equal(bare.category, null);
  assert.deepEqual(bare.screenshots, []);
  assert.equal(bare.long_description, null);
  assert.equal(bare.changelog, null);
});

test("asStoreVersions نسخه‌های ناقص را دور می‌ریزد", () => {
  const versions = asStoreVersions([
    { version: "1.2.3", changelog: "تغییر", released_at: "2026-10-01T00:00:00Z", yanked: false },
    { changelog: "بدون نسخه" },
    null,
    "not-an-object",
  ]);
  assert.equal(versions.length, 1);
  assert.equal(versions[0]!.version, "1.2.3");
  assert.equal(versions[0]!.yanked, false);
  assert.deepEqual(asStoreVersions(undefined), []);
});

test("asStoreDetail جزئیات را با نسخه‌ها می‌سازد و پاسخ بد را رد می‌کند", () => {
  const detail = asStoreDetail({ id: 5, slug: "d", versions: [{ version: "2.0.0" }] });
  assert.ok(detail);
  assert.equal(detail.versions.length, 1);
  assert.equal(asStoreDetail({ name: "no id" }), null);
});

test("asStoreCategories فقط رشته‌های non-empty را می‌خواند", () => {
  assert.deepEqual(asStoreCategories({ meta: { categories: ["a", "", "b", 3] } }), ["a", "b"]);
  assert.deepEqual(asStoreCategories({ meta: {} }), []);
  assert.deepEqual(asStoreCategories(null), []);
});

// ── WF-H18 — امتیاز/نظرات ──

test("میانگین/شمار/نصب فعال با پیش‌فرض محافظه‌کارانه خوانده می‌شوند", () => {
  const rich = asStoreItem({ id: 1, slug: "s", rating: 4.5, rating_count: 12, active_installs: 7 });
  assert.equal(rich?.rating, 4.5);
  assert.equal(rich?.rating_count, 12);
  assert.equal(rich?.active_installs, 7);

  const bare = asStoreItem({ id: 2, slug: "t" });
  assert.equal(bare?.rating, null);
  assert.equal(bare?.rating_count, 0);
  assert.equal(bare?.active_installs, 0);
});

test("asStoreReviews نظرهای ناقص/نامعتبر را دور می‌ریزد", () => {
  const reviews = asStoreReviews([
    { id: 1, rating: 5, comment: "عالی", author: "الف", created_at: "2026-10-01T00:00:00Z" },
    { id: 2, rating: 9 },
    { id: 3, comment: "بدون امتیاز" },
    null,
    "not-an-object",
  ]);
  assert.equal(reviews.length, 1);
  assert.equal(reviews[0]!.rating, 5);
  assert.equal(reviews[0]!.author, "الف");
  assert.deepEqual(asStoreReviews(undefined), []);
});

test("asStoreDetail نظرها و وضعیت ثبت نظر را می‌خواند", () => {
  const detail = asStoreDetail({
    id: 5, slug: "d", versions: [{ version: "2.0.0" }],
    reviews: [{ id: 1, rating: 3 }], can_review: true, has_reviewed: false,
  });
  assert.ok(detail);
  assert.equal(detail.reviews.length, 1);
  assert.equal(detail.can_review, true);
  assert.equal(detail.has_reviewed, false);
});

test("نمایش امتیاز: نبود ⇒ خط تیره، نه صفرِ گمراه‌کننده", () => {
  assert.equal(formatRating(null), "—");
  assert.equal(formatRating(undefined), "—");
  assert.match(formatRating(4.5), /[۰-۹]/);
  assert.equal(filledStars(4.4), 4);
  assert.equal(filledStars(4.6), 5);
  assert.equal(filledStars(null), 0);
});
