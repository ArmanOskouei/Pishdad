import { test } from "node:test";
import assert from "node:assert/strict";
import { asPublisherDashboard } from "./publisher.ts";

/**
 * WF-H19 — نرمال‌سازی داشبورد ناشر.
 *
 * قفل می‌کند که پاسخِ ناقص، ردیفِ جعلی نمی‌سازد و عددِ نامعتبر به صفر
 * می‌افتد — وگرنه UI دربارهٔ فروش/سهم دروغ می‌گفت.
 */

test("پاسخ خالی/ناقص ساختار امن می‌دهد", () => {
  for (const raw of [null, undefined, {}, { data: null }, "x", 42]) {
    const d = asPublisherDashboard(raw);
    assert.deepEqual(d.publishers, []);
    assert.deepEqual(d.plugins, []);
    assert.deepEqual(d.payouts, []);
    assert.equal(d.sales.publisher_share, 0);
    assert.equal(d.sales.unsettled, 0);
    assert.equal(d.sales.currency, "IRT");
  }
});

test("پاسخ کامل با تبدیل واحد درست نرمال می‌شود", () => {
  const d = asPublisherDashboard({
    data: {
      publishers: [{ id: 3, name: "Acme", slug: "acme", key_fingerprint: "abc", status: "active", verified_at: "2026-01-01T00:00:00+00:00" }],
      plugins: [{ id: 7, name: "P", slug: "p", version: "2.0.0", previous_version: "1.0.0", price: 100, currency: "IRT", review_status: "approved", yanked: false, active: true }],
      sales: { currency: "IRT", gross: 100, platform_fee: 10, publisher_share: 90, payout: 0, unsettled: 90, sales_count: 1 },
      payouts: [{ id: 5, publisher_key_id: 3, amount: 90, currency: "IRT", status: "pending", note: null, created_at: "2026-01-02T00:00:00+00:00" }],
    },
  });

  assert.equal(d.publishers[0].slug, "acme");
  assert.equal(d.plugins[0].review_status, "approved");
  assert.equal(d.plugins[0].previous_version, "1.0.0");
  assert.equal(d.sales.unsettled, 90);
  assert.equal(d.payouts[0].status, "pending");
  assert.equal(d.payouts[0].amount, 90);
});

test("وضعیت ناشناخته و آیتم بی‌شناسه دور ریخته یا امن می‌شوند", () => {
  const d = asPublisherDashboard({
    plugins: [{ id: 1, slug: "ok", review_status: "weird" }, { slug: "no-id" }],
    publishers: [{ name: "no-id" }],
    sales: { gross: "not-a-number" },
  });

  assert.equal(d.plugins.length, 1, "ردیفِ بدون id باید حذف شود.");
  assert.equal(d.plugins[0].review_status, null);
  assert.equal(d.plugins[0].version, "0.0.0");
  assert.deepEqual(d.publishers, []);
  assert.equal(d.sales.gross, 0);
});
