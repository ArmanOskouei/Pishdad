#!/usr/bin/env node
/**
 * اعتبارسنجِ بستهٔ آفلاین.
 *
 * بستهٔ آفلاین فقط وقتی ارزش دارد که **واقعاً** آفلاین باشد. یک ارجاعِ جاافتاده
 * در compose یعنی Docker بی‌سروصدا از رجیستری می‌کشد و کاربری که انتظار داشت
 * هیچ چیزی دانلود نشود، ناگهان به اینترنت نیاز پیدا می‌کند — بدون هیچ خطایی.
 *
 * پس اینجا موارد زیر سنجیده می‌شود:
 *   ۱. هر ایمیجی که compose آفلاین نام می‌برد، یک `.tar` متناظر دارد.
 *   ۲. هر سرویسِ compose آفلاین `pull_policy: never` دارد.
 *   ۳. هیچ `build:` در compose آفلاین نیست (یعنی compose چیزی نمی‌سازد).
 *   ۴. `vendor/` کامل است (autoload.php + پکیج‌های کلیدی).
 *   ۵. نصب‌کننده‌ها و راهنما حاضرند.
 *   ۶. بستهٔ سبک (اسناد + `cms-core`) داخل بسته هست.
 *
 * اجرا: node scripts/verify-offline-bundle.mjs [dist-offline]
 */

import { existsSync, readFileSync, readdirSync, statSync, statSync as st } from "node:fs";
import { join, resolve } from "node:path";

const root = resolve(process.argv[2] ?? "dist-offline");
const problems = [];
const notes = [];

const fail = (m) => problems.push(m);
const note = (m) => notes.push(m);

if (!existsSync(root)) {
  console.error(`✗ not found: ${root}\n  build it first: node scripts/build-offline-bundle.mjs`);
  process.exit(1);
}

console.log(`verify-offline-bundle: ${root}\n`);

/* ── ۱. بستهٔ سبک داخل بسته ───────────────────────────────────────────── */

for (const p of ["README.md", "LICENSE", "cms-core/backend/artisan", "cms-core/frontend/package.json"]) {
  if (!existsSync(join(root, p))) fail(`the lean package is incomplete: ${p} is missing`);
}

/* ── ۲. vendor کامل است ───────────────────────────────────────────────── */

const vendor = join(root, "cms-core", "backend", "vendor");
if (!existsSync(vendor)) {
  fail("vendor/ is missing - the install would have to run composer install, which needs packagist");
} else {
  if (!existsSync(join(vendor, "autoload.php"))) {
    fail("vendor/autoload.php is missing - the app cannot boot from this bundle");
  }
  // چند پکیجِ حیاتی: نبودِ هر کدام یعنی نصبِ نیمه‌کاره
  for (const dep of ["laravel/framework", "laravel/sanctum", "spatie/laravel-permission", "paragonie/sodium_compat"]) {
    if (!existsSync(join(vendor, dep))) fail(`vendor/${dep} is missing - vendor/ is not a complete install`);
  }
}

/* ── ۳. ایمیج‌ها ──────────────────────────────────────────────────────── */

const imagesDir = join(root, "offline", "images");
const tars = new Set();
if (!existsSync(imagesDir)) {
  fail("offline/images/ is missing - there is nothing to load");
} else {
  for (const f of readdirSync(imagesDir)) {
    if (f.endsWith(".tar")) tars.add(f);
  }
  if (tars.size === 0) fail("offline/images/ contains no .tar files");
}

/* ── ۴. composeهای آفلاین: هر ارجاع باید tar داشته باشد ───────────────── */

const COMPOSES = [
  "cms-core/backend/docker-compose.offline.yml",
  "cms-core/frontend/docker-compose.offline.yml",
];

const referenced = new Set();

for (const rel of COMPOSES) {
  const file = join(root, rel);
  if (!existsSync(file)) {
    fail(`${rel} is missing`);
    continue;
  }

  const text = readFileSync(file, "utf8");

  // `build:` یعنی compose چیزی می‌سازد ⇒ به سورس و ابزارِ بیلد نیاز دارد.
  if (/^\s*build:/m.test(text)) {
    fail(`${rel} still contains a \`build:\` - an offline stack must only reference images`);
  }

  // هر سرویس باید `pull_policy: never` داشته باشد.
  const services = text.split(/^\s{2}[a-z0-9_-]+:\s*$/m).slice(1);
  const policyCount = (text.match(/pull_policy:\s*never/g) ?? []).length;
  if (policyCount < services.length) {
    fail(
      `${rel}: ${policyCount} \`pull_policy: never\` for ${services.length} service(s) - ` +
        `a service without it can reach a registry`,
    );
  }

  // ارجاع‌های ایمیج را جمع کن و ببین tar دارند.
  for (const m of text.matchAll(/^\s*image:\s*(\S+)\s*$/gm)) {
    const ref = m[1];
    const name = ref.split("/").pop().split(":")[0];
    referenced.add(name);

    if (!tars.has(`${name}.tar`)) {
      // ایمیجِ ثبت‌شده در رجیستری = دانلودِ هنگامِ نصب.
      fail(`${rel} references \`${ref}\` but offline/images/${name}.tar does not exist`);
    }
  }

  // هر ارجاعی که با رجیستری شروع شود مشکوک است، حتی اگر tar داشته باشد.
  for (const m of text.matchAll(/^\s*image:\s*(.+)$/gm)) {
    const ref = m[1].trim();
    if (/^(docker\.io|ghcr\.io|quay\.io|registry\.|mcr\.microsoft\.com)/.test(ref)) {
      fail(`${rel} references a registry image directly: ${ref} (must be a locally loaded tag)`);
    }
  }
}

/* ── ۵. نصب‌کننده‌ها ──────────────────────────────────────────────────── */

for (const f of ["install.ps1", "install.sh", "README-OFFLINE.md"]) {
  if (!existsSync(join(root, f))) fail(`${f} is missing - the bundle has no installer`);
}

/* ── ۶. tarهای بی‌استفاده (نشانهٔ بی‌نظمی، نه خطا) ─────────────────────── */

for (const t of tars) {
  const name = t.replace(/\.tar$/, "");
  if (!referenced.has(name)) note(`offline/images/${t} is not referenced by any offline compose`);
}

/* ── گزارش ────────────────────────────────────────────────────────────── */

let bytes = 0;
const walk = (dir) => {
  for (const e of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, e.name);
    if (e.isDirectory()) walk(p);
    else if (st(p).isFile()) bytes += st(p).size;
  }
};
walk(root);

console.log(`  images in the bundle : ${tars.size}`);
console.log(`  images referenced    : ${referenced.size}`);
console.log(`  total size           : ${(bytes / 1024 / 1024 / 1024).toFixed(2)} GB`);

if (notes.length) {
  console.log("");
  for (const n of notes) console.log(`  note: ${n}`);
}

if (problems.length) {
  console.error(`\n  FAIL - ${problems.length} problem(s):`);
  for (const p of problems) console.error(`    ✗ ${p}`);
  process.exit(1);
}

console.log("\n  PASS - every referenced image travels in the bundle, no compose can build or pull, vendor/ is complete.");
