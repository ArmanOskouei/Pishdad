#!/usr/bin/env node
/**
 * ساخت بستهٔ توزیع خام (R1.2).
 *
 * ## چرا از `git archive` و نه از دیسک
 *
 * روی این نصب، `storage/app/private` چند هزار فایل zip بازماندهٔ تست
 * دارد، `.env`ها حاضرند و پوشه‌های build چند گیگابایت‌اند. کپی کردن از
 * دیسک یعنی هر بار باید دستی لیست «چیزهایی که نباید بروند» را به‌روز
 * کنی — و یک مورد جاافتاده یعنی لو رفتن یک secret.
 *
 * ولی `git archive` فقط **فایل‌های tracked** را می‌دهد. یعنی هر چیزی که
 * به‌درستی gitignore شده باشد (و `.env`، `vendor/`، `node_modules/`،
 * `storage/`، `.next/` و کل پوشهٔ افزونهٔ مرکزی همه هستند) **ساختاراً
 * غیرممکن** است وارد بسته شود. فهرست سیاه جای فهرست سفید را می‌گیرد.
 *
 * ## اجرا
 *
 *     node scripts/build-distribution.mjs                 # نوشتن بسته
 *     node scripts/build-distribution.mjs --out dist-v2   # مسیر دلخواه
 *
 * سپس `scripts/verify-distribution.mjs` را روی خروجی اجرا کنید.
 *
 * @see verify-distribution.mjs
 */

import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { existsSync, mkdirSync, writeFileSync, rmSync, cpSync, readFileSync, readdirSync, statSync } from "node:fs";
import { dirname, resolve, join, relative, sep } from "node:path";
import { fileURLToPath } from "node:url";

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, "..");

const argOut = process.argv.indexOf("--out");
const OUT_NAME = argOut > -1 ? process.argv[argOut + 1] : "dist-pishdad";
const OUT = resolve(ROOT, OUT_NAME);

const git = (...args) =>
  execFileSync("git", args, { cwd: ROOT, encoding: "utf8", maxBuffer: 64 * 1024 * 1024 });

/* ── ۱. چه چیزی واقعاً tracked است ─────────────────────────────────────── */

const tracked = git("ls-files", "-z").split("\0").filter(Boolean);

console.log(`tracked files: ${tracked.length}`);

/**
 * فایل‌هایی که باید در بسته باشند.
 *
 * ⚠️ سیاست عمداً **سفید** است: هر چیزی که اینجا نیاید، در بسته نیست.
 * این برعکسِ روش «همه را بگیر و سیاه‌لیست کن» است که دقیقاً همان
 * چیزی است که یک secret را لو می‌دهد.
 */
const INCLUDE_ROOTS = ["cms-core/", "docs/", "Run/", "scripts/", ".github/", "Logo/"];

/** فایل‌های قانونی که بدون‌شان بسته ناقص یا بی‌معنی است. */
const REQUIRED = [
  "cms-core/backend/composer.json",
  "cms-core/backend/.env.example",
  "cms-core/backend/artisan",
  "cms-core/backend/routes/api.php",
  "cms-core/backend/app/Services/Push/PushSender.php",
  "cms-core/backend/database/seeders/PishdadSiteSeeder.php",
  "cms-core/backend/database/seeders/data/pishdad-pages.php",
  "cms-core/frontend/package.json",
  "cms-core/frontend/next.config.ts",
  // ‎⚠️ سرویس‌ورکر Web Push. نبودش یعنی PWA ناقص و `/sw.js` چهار-صد.
  "cms-core/frontend/public/sw.js",
  // فونت محلی — قانون پروژه: هیچ CDN.
  "cms-core/frontend/public/fonts/Vazirmatn-Regular.woff2",
];

const missingRequired = REQUIRED.filter((f) => !tracked.includes(f));

const included = tracked.filter((f) => {
  if (!INCLUDE_ROOTS.some((r) => f.startsWith(r))) return false;

  // J11/J12 — پوشهٔ قرنطینه (افزونه‌های central/subscription و لایهٔ هستهٔ
  // اشتراکِ دور) هرگز نباید در بستهٔ عمومی برود، حتی اگر روزی track شود.
  if (f.startsWith("cms-core/backend/plugins/_quarantine/")) return false;

  // فایل‌های محلی که ممکن است ناخواسته track شده باشند.
  if (/(^|\/)\.env($|\.)/.test(f) && !f.endsWith(".env.example")) return false;
  if (/(^|\/)\.npmrc$/.test(f)) return false;
  if (/\.log$/.test(f)) return false;
  if (/phpunit\.agent.*\.xml$/.test(f)) return false;

  return true;
});

console.log(`included files: ${included.length}`);

if (missingRequired.length) {
  console.error(`\n✗ these required files are NOT tracked — the package would be broken:`);
  missingRequired.forEach((f) => console.error(`    ${f}`));
  console.error("\n  `git add` them first (or they are gitignored by mistake).");
  process.exit(1);
}

/* ── ۲. مسیر افزونه‌های خصوصی ──────────────────────────────────────────── */

const PRIVATE_PLUGINS = [
  "cms-core/backend/plugins/central/",
  "cms-core/backend/plugins/subscription/",
  "cms-core/backend/plugins/_quarantine/",
];

const privateHits = included.filter((f) => PRIVATE_PLUGINS.some((p) => f.startsWith(p)));

if (privateHits.length) {
  console.error(
    `\n✗ ${privateHits.length} file(s) from a PRIVATE/quarantined plugin are tracked and would ship:`,
  );
  privateHits.slice(0, 10).forEach((f) => console.error(`    ${f}`));
  console.error("\n  The public distribution must not carry these plugins.");
  console.error("  Either untrack them (`git rm --cached`) or add them to .gitignore.");
  process.exit(1);
}

console.log("private/quarantined plugins: not present (good)");

/* ── ۳. ساخت بسته ─────────────────────────────────────────────────────── */

if (existsSync(OUT)) {
  rmSync(OUT, { recursive: true, force: true });
}

mkdirSync(OUT, { recursive: true });

/*
 * ⚠️ چرا کپیِ مستقیم با Node و نه `git archive` / `tar`:
 *
 * هر دو روی این پروژه شکست می‌خورند و دلیلش قابل حدس نیست:
 *
 *  • `git archive` با فرمانِ طولانیِ مسیرها روی ویندوز به خطای quoting
 *    می‌خورد.
 *  • `tar` (bsdtar) مسیرهایی که اسمشان فاصله دارد — مثل
 *    `Logo/social-preview - 01.png` — به‌عنوان دو مسیر جدا می‌خواند و
 *    خطای «Couldn't visit directory» می‌دهد.
 *
 * چون فهرست فایل‌ها از قبل `git ls-files` است، دسترسی به تک‌تک
 * فایل‌ها قطعی است. پس کپی کردن با خود Node هم درست‌تر است (بدون
 * پوستهٔ واسط، بدون تفسیرِ کاراکترهای خاص) و هم قابل‌اعتمادتر.
 */
let copied = 0;

for (const rel of included) {
  const from = resolve(ROOT, rel);
  const to = join(OUT, rel);

  // مسیرهای عجیبِ ردیابی‌شده با `git ls-files` می‌توانند از ریشه بیرون
  // بزنند؛ چنین چیزی نباید بی‌سروصدا رد شود.
  if (!from.startsWith(ROOT)) {
    console.error(`✗ refusing to copy a path outside the repo: ${rel}`);
    process.exit(1);
  }

  if (!existsSync(from)) {
    console.error(`✗ tracked but missing on disk: ${rel}`);
    process.exit(1);
  }

  mkdirSync(dirname(to), { recursive: true });
  cpSync(from, to);

  copied++;

  if (copied % 100 === 0) process.stdout.write(`\r  copied ${copied}/${included.length}`);
}

process.stdout.write(`\r  copied ${copied}/${included.length}\n`);

/* ── ۴. فایل‌های کمکی ─────────────────────────────────────────────────── */

const keep = ["cms-core/backend", "cms-core/frontend", "docs", "Run", "scripts"];

for (const entry of readFileSync(resolve(ROOT, ".gitignore"), "utf8").split(/\r?\n/)) {
  const line = entry.trim();
  if (!line || line.startsWith("#")) continue;

  for (const k of keep) {
    const target = join(OUT, k);
    if (!existsSync(target)) continue;

    const marker = join(target, ".gitignore");
    const current = existsSync(marker) ? readFileSync(marker, "utf8") : "";
    if (!current.includes(line)) {
      writeFileSync(marker, `${current}${current && !current.endsWith("\n") ? "\n" : ""}${line}\n`, "utf8");
    }
  }
}

/*
 * ⚠️ شمارشِ فایل‌ها *بعد* از نوشتن `.gitignore`ها و manifest انجام
 * می‌شود، چون هر دو فایل جدید اضافه می‌کنند. اگر قبلش شمرده شود،
 * manifest با درختِ واقعی ناهماهنگ می‌ماند و `verify-distribution.mjs`
 * هر بار یک note می‌دهد.
 */
let shippedFiles = 0;
let shippedBytes = 0;

/** مسیرهای نسبیِ فایل‌های نوشته‌شده، برای digest محتوا. */
const shippedList = [];

const walk = (dir) => {
  for (const e of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, e.name);
    if (e.isDirectory()) walk(full);
    else {
      shippedFiles++;
      shippedBytes += statSync(full).size;
      shippedList.push(relative(OUT, full).split(sep).join("/"));
    }
  }
};

walk(OUT);

/*
 * ⚠️ `built_from` به‌تنهایی **دروغ می‌گوید** وقتی درختِ کاری dirty است.
 *
 * بسته از روی *فایل‌های* ساخته می‌شود (`git ls-files` محتوایِ روی دیسک را
 * می‌خواند)، نه از روی commit. پس اگر تغیییر stage شده باشند ولی commit
 * نشده باشند — که وضعیت عادیِ حین کار است — هشِ `HEAD` به کدی اشاره
 * می‌کند که اصلاً شبیه محتوای بسته نیست. کسی که بعداً این هش را در
 * مخزن چک کند، نسخهٔ اشتباهی را می‌بیند.
 *
 * پس دو چیز اضافه می‌شود:
 *  • `source_state` — صریح می‌گوید درخت تمیز بوده یا نه.
 *  • `content_digest` — هشِ خودِ بسته. برخلاف commit، برای **همیشه** درست
 *    است، چون مستقیم از فایل‌های نوشته‌شده حساب می‌شود. دو بسته با digest
 *    یکسان، دقیقاً یکی هستند؛ با commit یکسان، لزوماً نیستند.
 */
const head = git("rev-parse", "HEAD").trim();
const workingTreeDirty = git("status", "--porcelain").trim().length > 0;

const digestOf = createHash("sha256");
for (const rel of [...shippedList].sort()) {
  digestOf.update(rel).update("\0").update(readFileSync(join(OUT, rel)));
  digestOf.update("\0");
}
const contentDigest = digestOf.digest("hex");

const manifest = {
  name: "pishdad",
  built_from: head,
  source_state: workingTreeDirty
    ? "dirty — the working tree had uncommitted changes, so built_from does NOT describe this package; use content_digest"
    : "clean — built_from describes this package exactly",
  content_digest: contentDigest,
  built_at: new Date().toISOString(),
  tracked_files: tracked.length,
  copied_files: copied,
  shipped_files: shippedFiles,
  shipped_bytes: shippedBytes,
  excluded: {
    reason: "untracked by design — see scripts/build-distribution.mjs",
    items: [
      ".env (real secrets)",
      "vendor/ and node_modules/ (installed by composer/npm)",
      "storage/ except the empty .gitignore placeholders",
      "cms-core/backend/plugins/central/ (private plugin)",
      "cms-core/backend/plugins/subscription/ (private plugin)",
      "cms-core/backend/plugins/_quarantine/ (quarantined plugins + remote subscription layer)",
      "build output (.next/, out/, dist-*)",
    ],
  },
  install: [
    "cd cms-core/backend && composer install",
    "cp .env.example .env && php artisan key:generate",
    "php artisan storage:link   # required: media/images are served from /storage/",
    "php artisan migrate --force",
    "php artisan db:seed --class=PishdadSiteSeeder   # optional: the Pishdad demo site",
    "cd ../frontend && npm ci && npm run build",
  ],
};

writeFileSync(join(OUT, "DISTRIBUTION.json"), JSON.stringify(manifest, null, 2) + "\n", "utf8");

console.log(`\n✔ package written to ${OUT_NAME}/`);
console.log(`  ${shippedFiles} files, ${(shippedBytes / 1024 / 1024).toFixed(1)} MB, ${workingTreeDirty ? "DIRTY working tree" : `commit ${manifest.built_from.slice(0, 8)}`}`);
console.log(`  content_digest ${manifest.content_digest.slice(0, 16)}…${workingTreeDirty ? "  (built_from does NOT describe this package — see source_state)" : ""}`);
console.log(`  next: node scripts/verify-distribution.mjs ${OUT_NAME}`);