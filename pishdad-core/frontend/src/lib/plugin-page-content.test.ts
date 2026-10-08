import { test } from "node:test";
import assert from "node:assert/strict";

import {
  MAX_PLUGIN_PAGE_BLOCKS,
  normalizePluginPageBlocks,
  normalizePluginPages,
} from "./plugin-page-registry.ts";
import { BUILTIN_BLOCK_TYPES } from "./block-type.ts";

/**
 * K7.18/K7.19 — لایهٔ fail-closed فرانت.
 *
 * این فایل عمداً همان فیلتر را که بک‌اند می‌زند تکرار می‌کند. دلیلش در
 * `plugin-page-registry.ts` نوشته شده: داده از شبکه می‌آید، پس «بک‌اند قبلاً
 * پاکش کرده» دلیل کافی نیست.
 *
 * تستی که نتواند قرمز شود بی‌ارزش است، پس هر قاعده یک تستِ «ورودی بد رد
 * می‌شود» دارد — اگر فیلتر را حذف کنیم باید قرمز شود.
 */

// --------------------------------------------------------------------
// فیلتر بلوک — امنیتی‌ترین بخش
// --------------------------------------------------------------------

test("a plugin-supplied block type is dropped — the core vocabulary is closed", () => {
  const out = normalizePluginPageBlocks([
    { type: "evil_widget", data: {} },
  ]);

  assert.deepEqual(out, [], "نوع ناشناس نباید رندر شود.");
});

test("every built-in block type survives", () => {
  const input = BUILTIN_BLOCK_TYPES.map((type) => ({ type, data: {} }));

  assert.equal(normalizePluginPageBlocks(input).length, BUILTIN_BLOCK_TYPES.length);
});

test("a built-in block with a bad shape is dropped, valid siblings survive", () => {
  const out = normalizePluginPageBlocks([
    { type: "text", data: { body: "خوب" } },
    { type: "text", data: "رشته" },
    { type: "text" },
    null,
    "string",
    { data: {} },
  ]);

  assert.equal(out.length, 1);
  assert.equal(out[0]?.type, "text");
  assert.deepEqual(out[0]?.data, { body: "خوب" });
});

test("data must be a plain object, not an array", () => {
  const out = normalizePluginPageBlocks([{ type: "text", data: ["a"] }]);

  assert.deepEqual(out, []);
});

test("an empty data object is valid", () => {
  const out = normalizePluginPageBlocks([{ type: "text", data: {} }]);

  assert.equal(out.length, 1, "بلوک بدون هیچ فیلدی معتبر است.");
});

test("only a literal false disables a block", () => {
  const out = normalizePluginPageBlocks([
    { type: "text", data: {}, _enabled: false },
    { type: "text", data: {}, _enabled: "false" },
    { type: "text", data: {}, _enabled: 0 },
  ]);

  assert.equal(out.length, 3, "فقط false واقعی باید رندر را بکند.");
  assert.equal(out[0]?._enabled, false);
  assert.equal(out[1]?._enabled, undefined);
  assert.equal(out[2]?._enabled, undefined);
});

test("the block cap is enforced on the client too", () => {
  const input = Array.from({ length: MAX_PLUGIN_PAGE_BLOCKS + 10 }, () => ({
    type: "text",
    data: {},
  }));

  assert.equal(normalizePluginPageBlocks(input).length, MAX_PLUGIN_PAGE_BLOCKS);
});

test("a non-array is not a block list", () => {
  assert.deepEqual(normalizePluginPageBlocks(undefined), []);
  assert.deepEqual(normalizePluginPageBlocks(null), []);
  assert.deepEqual(normalizePluginPageBlocks({}), []);
});

// --------------------------------------------------------------------
// یکپارچگی با نرمالایزر صفحه
// --------------------------------------------------------------------

test("normalizePluginPages carries the blocks through, already filtered", () => {
  const pages = normalizePluginPages([
    {
      slug: "demo",
      path: "/admin/billing",
      title_fa: "صورتحساب",
      blocks: [
        { type: "text", data: { body: "خوب" } },
        { type: "evil", data: {} },
      ],
    },
  ]);

  assert.equal(pages.length, 1);
  assert.equal(pages[0]?.blocks.length, 1);
  assert.equal(pages[0]?.blocks[0]?.type, "text");
});

test("a page with no blocks key yields an empty list, not undefined", () => {
  const pages = normalizePluginPages([
    { slug: "demo", path: "/admin/billing", title_fa: "صورتحساب" },
  ]);

  assert.deepEqual(pages[0]?.blocks, []);
});

test("a hostile blocks payload cannot smuggle a component reference through", () => {
  const out = normalizePluginPageBlocks([
    { type: "text", data: { body: "ok" }, component: "../../evil" },
    { type: "text", data: { body: "ok" }, import: "node:fs" },
  ]);

  // The whole entry is rebuilt field by field, so unknown keys are dropped by
  // construction rather than filtered out.
  assert.deepEqual(Object.keys(out[0] ?? {}).sort(), ["data", "type"]);
});
