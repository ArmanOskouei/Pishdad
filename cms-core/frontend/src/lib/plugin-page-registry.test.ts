import test from "node:test";
import assert from "node:assert/strict";

import {
  decidePluginPage,
  matchPluginPage,
  normalizePluginPages,
  type PluginPageEntry,
} from "./plugin-page-registry.ts";
import type { ProfileData } from "./domain.ts";

const entry = (path: string, permission: string | null = null): PluginPageEntry => ({
  slug: "shop",
  path,
  title_fa: "تیتر",
  permission,
  layout: "default",
  // K7.19: صفحه‌ای که محتوا اعلام نکرده بلوک خالی دارد — نه `undefined`.
  // رندرر روی `length` شرط می‌گذارد، پس `undefined` یعنی دسترسی به خصوصیت
  // یک مقدار غایب در runtime.
  blocks: [],
});

const profile = (permissions?: string[]): ProfileData =>
  ({ permissions } as unknown as ProfileData);

test("K6.5 — normalizePluginPages", async (t) => {
  await t.test("reads a well-formed response", () => {
    const out = normalizePluginPages([
      { slug: "shop", path: "/admin/shop/orders", title_fa: "سفارش‌ها" },
    ]);
    assert.equal(out.length, 1);
    assert.equal(out[0]!.path, "/admin/shop/orders");
    assert.equal(out[0]!.layout, "default");
  });

  await t.test("treats a missing list as empty, not as everything", () => {
    // «نامعلوم» باید «هیچ» باشد. اگر `undefined` را خالی نمی‌گرفتیم، یک
    // پاسخ ناقص همهٔ صفحه‌ها را باز می‌کرد.
    assert.deepEqual(normalizePluginPages(null), []);
    assert.deepEqual(normalizePluginPages(undefined), []);
    assert.deepEqual(normalizePluginPages({ items: [] }), []);
  });

  await t.test("rejects paths outside /admin/", () => {
    // بدون این قاعده یک افزونه می‌توانست /sampleplug/ را ثبت کند و جای پنل
    // مرکزی بنشیند.
    const out = normalizePluginPages([
      { slug: "evil", path: "/sampleplug/steal" },
      { slug: "evil", path: "/admin/../sampleplug/steal" },
      { slug: "evil", path: "admin/relative" },
    ]);
    assert.deepEqual(out, []);
  });

  await t.test("rejects entries without a slug or with a non-string path", () => {
    const out = normalizePluginPages([
      { slug: "", path: "/admin/a" },
      { slug: "x", path: 42 },
      null,
      "string",
    ]);
    assert.deepEqual(out, []);
  });

  await t.test("collapses duplicate paths so one URL has one owner", () => {
    const out = normalizePluginPages([
      { slug: "a", path: "/admin/x", title_fa: "الف" },
      { slug: "b", path: "/admin/x", title_fa: "ب" },
    ]);
    assert.equal(out.length, 1);
  });

  await t.test("normalises a trailing slash away", () => {
    // /admin/a و /admin/a/ باید یک مسیر باشند، وگرنه Next.js دو رندر برای یک
    // صفحه می‌ساخت.
    const out = normalizePluginPages([{ slug: "x", path: "/admin/a/" }]);
    assert.equal(out[0]!.path, "/admin/a");
  });

  await t.test("falls back to the path when title_fa is empty", () => {
    const out = normalizePluginPages([{ slug: "x", path: "/admin/a", title_fa: "" }]);
    assert.equal(out[0]!.title_fa, "/admin/a");
  });
});

test("K6.5 — matchPluginPage", async (t) => {
  const pages = [entry("/admin/shop"), entry("/admin/shop/orders")];

  await t.test("matches an exact path", () => {
    assert.equal(matchPluginPage(pages, "/admin/shop")?.path, "/admin/shop");
  });

  await t.test("prefers the longest match, not the first", () => {
    // نکتهٔ اصلی: اگر ترتیب ورودی مبنا بود، /admin/shop برندهٔ
    // /admin/shop/orders می‌شد و مسیر بلندتر هیچ‌وقت رندر نمی‌شد.
    const reversed = [entry("/admin/shop/orders"), entry("/admin/shop")];
    assert.equal(matchPluginPage(reversed, "/admin/shop/orders")?.path, "/admin/shop/orders");
  });

  await t.test("matches a deeper path against its registered parent", () => {
    assert.equal(
      matchPluginPage([entry("/admin/shop")], "/admin/shop/orders/42")?.path,
      "/admin/shop",
    );
  });

  await t.test("does not match a sibling with a shared prefix", () => {
    // /admin/shop2 نباید زیرمسیر /admin/shop حساب شود.
    assert.equal(matchPluginPage([entry("/admin/shop")], "/admin/shop2"), null);
  });

  await t.test("returns null for an unregistered path", () => {
    assert.equal(matchPluginPage(pages, "/admin/unknown"), null);
  });

  await t.test("ignores a trailing slash on the incoming pathname", () => {
    assert.equal(matchPluginPage(pages, "/admin/shop/")?.path, "/admin/shop");
  });
});

test("K6.5 — decidePluginPage", async (t) => {
  const pages = [entry("/admin/shop"), entry("/admin/billing", "shop:billing.view")];

  await t.test("reports not-registered for an unknown path", () => {
    assert.equal(decidePluginPage(pages, "/admin/nope", null).kind, "not-registered");
  });

  await t.test("renders an open page without needing permissions", () => {
    // نبودن permission یعنی «برای هر مدیر وارد‌شده باز» — عمداً برعکس منو،
    // چون دروازهٔ منو روی خودِ آیتم است و اینجا روی نبودِ پرمیشن نیست.
    assert.equal(decidePluginPage(pages, "/admin/shop", profile([])).kind, "render");
  });

  await t.test("blocks a guarded page when the permission is absent", () => {
    assert.equal(decidePluginPage(pages, "/admin/billing", profile([])).kind, "forbidden");
  });

  await t.test("allows a guarded page when the permission is present", () => {
    assert.equal(
      decidePluginPage(pages, "/admin/billing", profile(["shop:billing.view"])).kind,
      "render",
    );
  });

  await t.test("blocks a guarded page when the profile failed to load", () => {
    // fail-closed: نبودن پروفایل یعنی نامعلوم، و نامعلوم یعنی بسته.
    assert.equal(decidePluginPage(pages, "/admin/billing", null).kind, "forbidden");
  });

  await t.test("keeps forbidden distinct from not-registered", () => {
    // قاطی‌کردنشان وجود صفحه‌ای را که کاربر نباید از آن خبر داشته باشد لو می‌دهد.
    const d = decidePluginPage(pages, "/admin/billing", profile([]));
    assert.notEqual(d.kind, "not-registered");
  });
});
