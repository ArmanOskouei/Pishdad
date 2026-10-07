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

import { execFileSync } from "node:child_process";
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

/**
 * E44 — بستهٔ تک‌بخش فقط همان بخش را دارد. بخش‌ها از محتوای خودِ بسته
 * تشخیص داده می‌شوند، نه از نام پوشه (نام قابل تغییر است با آرگومان).
 */
const hasBackend = existsSync(join(root, "cms-core/backend/artisan"));
const hasFrontend = existsSync(join(root, "cms-core/frontend/package.json"));

if (!hasBackend && !hasFrontend) {
  fail("neither a backend nor a frontend lean package is inside - this bundle cannot install anything");
}

for (const p of ["README.md", "LICENSE"]) {
  if (!existsSync(join(root, p))) fail(`the lean package is incomplete: ${p} is missing`);
}

/* ── ۲. vendor به‌صورت tar ─────────────────────────────────────────────── */

// `vendor/` به‌صورت tar.gz سفر می‌کند و اسکریپتِ نصب بازش می‌کند. اینجا فقط
// حضورش و اینکه خالی نیست سنجیده می‌شود؛ بازکردنش کارِ نصب‌کننده است.
const vendorTar = join(root, "offline", "vendor.tar.gz");
// E44 — vendor فقط وقتی لازم است که بک‌اند در بسته باشد.
if (!hasBackend) {
  notes.push("no backend in this bundle (frontend-only) - vendor.tar.gz not required");
} else if (!existsSync(vendorTar)) {
  fail("offline/vendor.tar.gz is missing - the install would have to run composer install, which needs packagist");
} else {
  const size = statSync(vendorTar).size;
  // یک vendorِ واقعی لاراول چند ده مگابایت است؛ tarِ چند کیلوبایتی یعنی
  // نصب نیمه‌کاره یا شکست‌خورده.
  if (size < 5 * 1024 * 1024) {
    fail(`offline/vendor.tar.gz is only ${(size / 1024 / 1024).toFixed(1)} MB - that is not a complete Laravel vendor tree`);
  }
  notes.push(`vendor.tar.gz: ${(size / 1024 / 1024).toFixed(1)} MB`);

/*
 * 🛡️ E50 — autoloader قابلِ حمل است؟
 *
 * فقط همین دو فایل از داخلِ tar باز می‌شوند، چون این خطا در جای دیگری دیده
 * نمی‌شود: اندازهٔ tar درست است، ایمیج‌ها سرِ جایشان‌اند و بسته سالم به‌نظر
 * می‌رسد — ولی سرِ نصبِ کاربر روی `AppServiceProvider` می‌میرد، آن هم در
 * نصبِ آفلاین که شبکه نیست تا خودش را درمان کند.
 *
 * الگو عمداً `=> '/` است: حالتِ سالم `=> __DIR__ . '/../..' . '/app/…'` است و
 * حالتِ خراب `=> '/app' . '/app/…'` — فقط دومی با این الگو می‌خورد.
 */
for (const rel of ["./composer/autoload_classmap.php", "./composer/autoload_static.php"]) {
  let body = "";
  try {
    body = execFileSync("tar", ["-xzOf", vendorTar, rel], { encoding: "utf8", maxBuffer: 64 * 1024 * 1024 });
  } catch {
    fail(`could not read ${rel} out of vendor.tar.gz - the autoloader cannot be checked`);
    continue;
  }

  const absolute = body.split("\n").filter((line) => /=> '\//.test(line));

  if (absolute.length > 0) {
    fail(
      `vendor.tar.gz carries ${absolute.length} ABSOLUTE build paths in ${rel} (E50) - ` +
        `the install would die with "Failed to open stream". First: ${absolute[0].trim().slice(0, 88)}`,
    );
  }
}
}

/* ── ۳. ایمیج‌ها ──────────────────────────────────────────────────────── */

const imagesDir = join(root, "offline", "images");
const tars = new Set();
const tarSizes = new Map();
if (!existsSync(imagesDir)) {
  fail("offline/images/ is missing - there is nothing to load");
} else {
  for (const f of readdirSync(imagesDir)) {
    if (!f.endsWith(".tar")) continue;
    tars.add(f);
    tarSizes.set(f, statSync(join(imagesDir, f)).size);
  }
  if (tars.size === 0) fail("offline/images/ contains no .tar files");

  // یک tarِ خالی نشانهٔ شکستِ خاموشِ `docker save` است (ایمیجِ کشیده‌شده با
  // digest در snapshotterِ containerd). وجودِ فایل کافی نیست — باید حجم داشته باشد.
  for (const [f, size] of tarSizes) {
    if (size < 1024 * 1024) fail(`offline/images/${f} is only ${size} bytes - docker save wrote an empty archive`);
  }
}

/* ── ۴. composeهای آفلاین: هر ارجاع باید tar داشته باشد ───────────────── */

const COMPOSES = [
  "cms-core/backend/docker-compose.offline.yml",
  "cms-core/frontend/docker-compose.offline.yml",
].filter((rel) => existsSync(join(root, rel)));

// E44 — در بستهٔ تک‌بخش فقط compose همان بخش هست؛ نبودِ هیچ‌کدام یعنی بسته خراب.
if (COMPOSES.length === 0) {
  fail("no offline compose file at all - neither backend nor frontend can start");
}

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

  // هر سرویسِ دارای ایمیج باید `pull_policy: never` داشته باشد.
  //
  // شمارش با `image:` انجام می‌شود نه با تقسیم بر نامِ سرویس‌ها: آن روش بلوکِ
  // `volumes:` را هم «سرویس» می‌شمرد و مثبتِ کاذب می‌داد.
  const imageCount = (text.match(/^\s*image:/gm) ?? []).length;
  const policyCount = (text.match(/pull_policy:\s*never/g) ?? []).length;
  if (policyCount < imageCount) {
    fail(
      `${rel}: ${policyCount} \`pull_policy: never\` for ${imageCount} image(s) - ` +
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

console.log("\n  PASS - every referenced image travels in the bundle, no compose can build or pull, vendor.tar.gz is present.");
