import { test } from "node:test";
import assert from "node:assert/strict";

import {
  asInvoices,
  hasUnpaidInvoice,
  LEGACY_SUBSCRIPTION_API,
  parseSuccessorVersion,
  SUBSCRIPTION_API,
  subscriptionSummaryBlocks,
} from "./subscription.ts";
import { isBuiltinBlockType } from "./block-type.ts";

/**
 * L-B12 — «اشتراک من» اعلانی: بلوک‌ها فقط واژگان هسته، مسیرها نو-اول.
 */

const INPUT = {
  planName: "استاندارد",
  tierFa: "استاندارد",
  statusFa: "فعال",
  dueFa: "۱۴۰۵/۰۷/۰۸",
  daysFa: "۱۲ روز مانده",
  unpaidFa: "۲",
  suspended: false,
  unpaidCount: 2,
};

test("summary emits only core-vocabulary block types", () => {
  for (const suspended of [false, true]) {
    const blocks = subscriptionSummaryBlocks({ ...INPUT, suspended });
    assert.ok(blocks.length > 0);
    for (const b of blocks) {
      assert.ok(
        isBuiltinBlockType(b.type),
        `«${b.type}» در BlockRenderer رندر نمی‌شود — فقط واژگان هسته.`,
      );
      assert.equal(typeof b.data, "object");
    }
  }
});

test("a suspended subscription gains a lock quote, an active one does not", () => {
  const active = subscriptionSummaryBlocks({ ...INPUT, suspended: false });
  const locked = subscriptionSummaryBlocks({ ...INPUT, suspended: true });

  assert.equal(active.some((b) => b.type === "quote"), false);
  assert.equal(locked.some((b) => b.type === "quote"), true);
  assert.equal(locked.length, active.length + 1);
});

test("the hero carries the plan name — the K7.18/K7.19 <h1> pattern needs it", () => {
  const [hero] = subscriptionSummaryBlocks(INPUT);
  assert.equal(hero?.type, "hero");
  assert.equal((hero?.data as Record<string, unknown>).title, "استاندارد");
});

test("new plugin routes come first, legacy routes are the fallback", () => {
  assert.ok(SUBSCRIPTION_API.show.startsWith("/v1/p/subscription/"));
  assert.ok(LEGACY_SUBSCRIPTION_API.show.startsWith("/v1/admin/subscription"));
  assert.equal(SUBSCRIPTION_API.pay(12), "/v1/p/subscription/invoices/12/pay");
  assert.equal(LEGACY_SUBSCRIPTION_API.pay(12), "/v1/admin/subscription/invoices/12/pay");
});

test("parseSuccessorVersion reads the backend Link header", () => {
  assert.equal(
    parseSuccessorVersion('</api/v1/p/subscription/show>; rel="successor-version"'),
    "/v1/p/subscription/show",
  );
  assert.equal(parseSuccessorVersion(null), null);
  assert.equal(parseSuccessorVersion(""), null);
  assert.equal(parseSuccessorVersion('</api/v1/admin/other>; rel="successor-version"'), null);
  assert.equal(parseSuccessorVersion('<https://evil.example/x>; rel="successor-version"'), null);
});

test("hasUnpaidInvoice / asInvoices tolerate both envelope shapes", () => {
  assert.equal(hasUnpaidInvoice([{ status: "paid" }]), false);
  assert.equal(hasUnpaidInvoice([{ status: "pending" }]), true);
  assert.deepEqual(asInvoices([{ id: 1 }]), [{ id: 1 }]);
  assert.deepEqual(asInvoices({ data: [{ id: 2 }] }).length, 1);
  assert.deepEqual(asInvoices({ data: { data: [{ id: 3 }] } }).length, 1);
  assert.deepEqual(asInvoices(null), []);
  assert.deepEqual(asInvoices("junk"), []);
});
