/**
 * SEC-3 — دروازهٔ ضدّبازگشت.
 *
 * قرارداد: **کدِ کلاینت** هرگز `Authorization: Bearer` نمی‌سازد. Bearer فقط
 * در ماژول‌های سمتِ سرور ساخته می‌شود (Route Handlerها و Server Componentها
 * که کوکی httpOnly را می‌خوانند). این تست سورس را اسکن می‌کند تا اگر کسی
 * دوباره توکن به کلاینت برگرداند، CI قرمز شود — نه کاربر.
 *
 * تشخیصِ «کلاینت»: فایلی که دستور `"use client"` در ابتدای آن است. بنابراین
 * یک Server Component که مشروعاً Bearer می‌سازد مثبتِ کاذب نمی‌دهد.
 */

import test from "node:test";
import assert from "node:assert/strict";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative, sep } from "node:path";
import { fileURLToPath } from "node:url";

const SRC_ROOT = fileURLToPath(new URL("../../", import.meta.url));

/** فایل‌های مجاز: Route Handlerها و ماژول‌های مرزیِ سمت سرور. */
const SERVER_ONLY_PREFIXES = [
  join(SRC_ROOT, "app", "api") + sep,
  join(SRC_ROOT, "lib", "server-api") + ".",
  join(SRC_ROOT, "lib", "client-ip") + ".",
  join(SRC_ROOT, "lib", "no-client-bearer.test") + ".",
];

/** پوشه‌های غیرِ سورس: خروجیِ بیلد و کدِ تولیدشده. */
const IGNORED_PREFIXES = [
  join(SRC_ROOT, ".next") + sep,
  join(SRC_ROOT, "node_modules") + sep,
  join(SRC_ROOT, "scripts", "generated") + sep,
];

/** کامنت‌ها برداشته می‌شوند تا مثبتِ کاذب از توضیحات پاک شود. */
function stripComments(src: string): string {
  return src
    .replace(/\/\*[\s\S]*?\*\//g, "")
    .replace(/(^|[^:])\/\/.*$/gm, "$1");
}

function isClientModule(src: string): boolean {
  return /^\s*["']use client["']/m.test(src);
}

function collect(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      collect(full, out);
    } else if (/\.tsx?$/.test(entry)) {
      out.push(full);
    }
  }
  return out;
}

test("no client-side Bearer construction", () => {
  const files = collect(SRC_ROOT).filter(
    (f) => !SERVER_ONLY_PREFIXES.some((p) => f.startsWith(p)) && !IGNORED_PREFIXES.some((p) => f.startsWith(p)),
  );
  const offenders: string[] = [];

  for (const file of files) {
    const raw = readFileSync(file, "utf8");
    if (!isClientModule(raw)) continue; // فقط کدِ کلاینت ممنوع است.
    const src = stripComments(raw);
    if (/Authorization\s*[:=]\s*[`'"]Bearer/.test(src) || /Bearer \$\{/.test(src) || /headers\s*\[[`'"]Authorization/.test(src)) {
      offenders.push(relative(SRC_ROOT, file));
    }
  }

  assert.deepEqual(offenders, [], `Bearer must only be built server-side; found in client modules: ${offenders.join(", ")}`);
});

test("no browser-side token plumbing in client modules", () => {
  const files = collect(SRC_ROOT).filter(
    (f) => !SERVER_ONLY_PREFIXES.some((p) => f.startsWith(p)) && !IGNORED_PREFIXES.some((p) => f.startsWith(p)),
  );
  const offenders: string[] = [];

  for (const file of files) {
    const raw = readFileSync(file, "utf8");
    if (!isClientModule(raw)) continue;
    const src = stripComments(raw);
    // خواندنِ توکن از localStorage یا نگه‌داشتنش در stateِ کلاینت.
    if (/localStorage\.getItem\(\s*["'`]auth/.test(src) || /Authorization.*Bearer/.test(src)) {
      offenders.push(relative(SRC_ROOT, file));
    }
  }

  assert.deepEqual(offenders, [], `token must not reach client state; found in: ${offenders.join(", ")}`);
});
