import { test } from "node:test";
import assert from "node:assert/strict";

import { buildBreadcrumbList, buildBreadcrumbTrail, slugSegments } from "./public-json-ld.ts";

/**
 * WF-M3 — BreadcrumbList در JSON-LD صفحاتِ عمومی.
 *
 * این تابع خالص است و `[...path]/page.tsx` آن را در `@graph` می‌نشاند؛ پس
 * نگهبان اینجا شکل/موقعیت/URL آیتم‌ها را برای گوگل قفل می‌کند.
 */

test("اسلاگِ چندبخشی: خانه + هر سگمنت با position و URLِ مطلق (زبان پایه)", () => {
  const ld = buildBreadcrumbList({ base: "https://ex.com", slug: "about/team", locale: "fa", primary: "fa" });
  assert.equal(ld["@type"], "BreadcrumbList");
  assert.deepEqual(ld.itemListElement, [
    { "@type": "ListItem", position: 1, name: "خانه", item: "https://ex.com/" },
    { "@type": "ListItem", position: 2, name: "about", item: "https://ex.com/about" },
    { "@type": "ListItem", position: 3, name: "team", item: "https://ex.com/about/team" },
  ]);
});

test("اسلاگِ تک‌بخشی: خانه + یک سگمنت", () => {
  const ld = buildBreadcrumbList({ base: "https://ex.com", slug: "contact", locale: "fa", primary: "fa" });
  assert.deepEqual(ld.itemListElement, [
    { "@type": "ListItem", position: 1, name: "خانه", item: "https://ex.com/" },
    { "@type": "ListItem", position: 2, name: "contact", item: "https://ex.com/contact" },
  ]);
});

test("زبان دوم: پیشوند در URLِ خانه و سگمنت‌ها + برچسبِ انگلیسی", () => {
  const ld = buildBreadcrumbList({ base: "https://ex.com", slug: "about/team", locale: "en", primary: "fa" });
  assert.deepEqual(ld.itemListElement, [
    { "@type": "ListItem", position: 1, name: "Home", item: "https://ex.com/en" },
    { "@type": "ListItem", position: 2, name: "about", item: "https://ex.com/en/about" },
    { "@type": "ListItem", position: 3, name: "team", item: "https://ex.com/en/about/team" },
  ]);
});

test("بدون base: آیتم‌ها item ندارند ولی position/name می‌مانند", () => {
  const ld = buildBreadcrumbList({ base: null, slug: "a/b", locale: "fa", primary: "fa" });
  assert.deepEqual(ld.itemListElement, [
    { "@type": "ListItem", position: 1, name: "خانه" },
    { "@type": "ListItem", position: 2, name: "a" },
    { "@type": "ListItem", position: 3, name: "b" },
  ]);
});

test("برچسبِ سفارشی خانه و سگمنت، و نرمال‌سازیِ اسلش‌ها", () => {
  const ld = buildBreadcrumbList({
    base: "https://ex.com/",
    slug: "/about/team/",
    locale: "fa",
    primary: "fa",
    homeLabel: "صفحهٔ اصلی",
    labels: { team: "تیم ما" },
  });
  assert.equal(ld.itemListElement[0]!.name, "صفحهٔ اصلی");
  assert.equal(ld.itemListElement[2]!.name, "تیم ما");
  assert.equal(ld.itemListElement[2]!.item, "https://ex.com/about/team");
});

test("slugSegments بخش‌های خالی را حذف می‌کند", () => {
  assert.deepEqual(slugSegments("/a//b/"), ["a", "b"]);
  assert.deepEqual(slugSegments(""), []);
});

/**
 * WF-L3 — مسیر راهنمای دیداری: همان ساختارِ JSON-LD ولی با href نسبی و
 * آخرین آیتمِ بدون لینک.
 */
test("مسیر راهنما: خانه + سگمنت‌ها با href نسبی و آخرین آیتمِ جاری", () => {
  const trail = buildBreadcrumbTrail({ slug: "about/team", locale: "fa", primary: "fa" });
  assert.deepEqual(trail, [
    { label: "خانه", href: "/", current: false },
    { label: "about", href: "/about", current: false },
    { label: "team", current: true },
  ]);
});

test("مسیر راهنما در زبان دوم پیشوند می‌گیرد", () => {
  const trail = buildBreadcrumbTrail({ slug: "about/team", locale: "en", primary: "fa" });
  assert.deepEqual(trail, [
    { label: "Home", href: "/en", current: false },
    { label: "about", href: "/en/about", current: false },
    { label: "team", current: true },
  ]);
});

test("مسیر راهنما: اسلاگ خالی ⇒ هیچ آیتمی (صفحهٔ خانه)", () => {
  assert.deepEqual(buildBreadcrumbTrail({ slug: "/", locale: "fa", primary: "fa" }), []);
  assert.deepEqual(buildBreadcrumbTrail({ slug: "", locale: "en", primary: "fa" }), []);
});

test("مسیر راهنما: برچسب سفارشی سگمنت و آخرین آیتم، و نرمال‌سازی اسلش", () => {
  const trail = buildBreadcrumbTrail({
    slug: "/about/team/",
    locale: "fa",
    primary: "fa",
    labels: { team: "تیم ما" },
    lastLabel: "تیم ما",
  });
  assert.equal(trail[1]!.label, "about");
  assert.equal(trail[1]!.href, "/about");
  assert.equal(trail[2]!.label, "تیم ما");
  assert.equal(trail[2]!.href, undefined);
  assert.equal(trail[2]!.current, true);
});
