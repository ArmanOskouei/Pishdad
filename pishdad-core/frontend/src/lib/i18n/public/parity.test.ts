import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

import { PUBLIC_LOCALES, publicDir, publicT, isPublicLocale } from "./index.ts";

/**
 * ECO2 — نگهبانِ تقارنِ فرهنگِ لغتِ سایتِ عمومی.
 *
 * همان الگوی `../i18n-parity.test.ts`: بدون import (فقط متن)، چون `node --test`
 * specifierهای نسبیِ بدون پسوند را resolve نمی‌کند. اگر نحوِ فایل عوض شود و
 * الگو چیزی پیدا نکند، تست **قرمز** می‌شود نه سبزِ ساکت.
 */

const HERE = dirname(fileURLToPath(import.meta.url));
const read = (p: string) => readFileSync(resolve(HERE, p), "utf8");

function keysOf(src: string): string[] {
  const out: string[] = [];
  for (const m of src.matchAll(/^\s{2}"([a-z][\w.]*)":/gm)) out.push(m[1]!);
  assert.ok(out.length > 0, "هیچ کلیدی پیدا نشد — نحوِ فایل فرهنگ عوض شده؟ این تست باید صدا بزند.");
  return out;
}

const faKeys = keysOf(read("./fa.ts"));
const enKeys = keysOf(read("./en.ts"));

test("هر کلیدِ فارسیِ سایت ترجمهٔ انگلیسی دارد", () => {
  const missing = faKeys.filter((k) => !enKeys.includes(k));
  assert.deepEqual(missing, [], `کلیدهای بی‌ترجمه: ${missing.join("، ")}`);
});

test("ترجمهٔ انگلیسیِ سایت کلیدِ یتیم ندارد", () => {
  const orphans = enKeys.filter((k) => !faKeys.includes(k));
  assert.deepEqual(orphans, [], `کلیدهای یتیم در en.ts: ${orphans.join("، ")}`);
});

test("ترجمهٔ انگلیسیِ سایت خالی نیست", () => {
  const src = read("./en.ts");
  const empty = enKeys.filter((k) => {
    const m = new RegExp(`^\\s{2}"${k.replace(/\./g, "\\.")}":\\s*"([^"]*)"`, "m").exec(src);
    return !m || m[1]!.trim() === "";
  });
  assert.deepEqual(empty, [], `مقدارِ خالی یعنی ترجمه‌نشده: ${empty.join("، ")}`);
});

test("جهت از زبان می‌آید و دو زبان پشتیبانی می‌شود", () => {
  assert.deepEqual([...PUBLIC_LOCALES], ["fa", "en"]);
  assert.equal(publicDir("fa"), "rtl");
  assert.equal(publicDir("en"), "ltr");
  assert.equal(isPublicLocale("fa"), true);
  assert.equal(isPublicLocale("en"), true);
  assert.equal(isPublicLocale("de"), false);
  assert.equal(isPublicLocale(undefined), false);
});

test("کلیدِ ناشناخته به خودِ کلید می‌رسد، نه رشتهٔ خالی", () => {
  const out = publicT("en", "does.not.exist" as never);
  assert.equal(out, "does.not.exist");
});

test("اعلانِ بلوکِ ناشناس در دو زبان و با نامِ پاک‌شده برمی‌گردد", () => {
  assert.ok(publicT("fa", "block.unknownNotice", { name: "price_table" }).includes("price_table"));
  assert.ok(publicT("fa", "block.unknownNotice", { name: "price_table" }).includes("رندر نشد"));
  assert.ok(publicT("en", "block.unknownNotice", { name: "price_table" }).includes("price_table"));
  assert.ok(publicT("en", "block.unknownNotice", { name: "price_table" }).includes("not rendered"));
});
