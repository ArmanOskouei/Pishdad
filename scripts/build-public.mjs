#!/usr/bin/env node
/**
 * ساختِ درختِ عمومیِ «پیشداد» — فقط کدِ بک‌اند و فرانت‌اند.
 *
 *     node scripts/build-public.mjs            # → dist-public/
 *     node scripts/build-public.mjs dist-x     # مسیرِ دلخواه
 *
 * خروجی برای مرورِ کاربر و سپس انتشار در مخزنِ عمومی `ArmanOskouei/Pishdad`.
 * در پایان، `verify-distribution.mjs` (ممیزیِ مستقل و ثابت‌شدهٔ همین ریپو) روی
 * خروجی اجرا می‌شود، پس سطحِ امنیتی از `build-distribution.mjs` پایین‌تر نیست.
 *
 * ## چرا سفید‌لیست، نه سیاه‌لیست
 *
 * فهرست از `git ls-files` می‌آید (مثلِ `build-distribution.mjs`): هر چیزی که
 * درست gitignore شده باشد — `.env`، `vendor/`، `node_modules/`، `.next/`،
 * `plugins/central/` — ساختاراً راهی به بسته ندارد. یک سیاه‌لیستِ جاافتاده
 * یعنی لو رفتنِ یک راز؛ یک سفید‌لیستِ جاافتاده فقط یعنی بستهٔ ناقص.
 *
 * ## چرا این اسکریپت لازم است (و pushِ مستقیمِ `master` خطرناک است)
 *
 * `cms-core/backend/plugins/_quarantine/` **۵۷ فایلِ** کدِ صورت‌حساب و
 * اپراتورِ مرکزی دارد (`BillingPurge`/`BillingRemind`/`BillingSuspend`،
 * `CentralDashboardController`، مایگریت‌های `central_*`) که در `master`
 * **track شده** و در `.gitignore` هم نیست — و در دو کامیتِ تاریخچه هم هست.
 * `docs/DEPLOY-CENTRAL.md §۵` صریح می‌گوید این لایه نباید عمومی شود.
 * پس `git rm --cached` هم کافی نبود: تاریخچه برای همیشه در گیت‌هاب می‌ماند.
 * درختِ تازه با تاریخچهٔ تازه، این را ساختاراً حل می‌کند.
 *
 * ## چه چیزی عمداً بیرون می‌ماند
 *
 *  • `plugins/_quarantine/`، `plugins/central/`، `plugins/subscription/`،
 *    `src/app/(central)/` و تست/فیکسچرِ مرکزی — لایهٔ تجاریِ خصوصی.
 *  • `docs/`، `Logo/`، `Run/`، `scripts/`، `.github/`، `AGENTS.md`،
 *    `.graphifyignore` — اسناد و ابزارِ داخلی؛ درخواستِ کاربر: فقط کد.
 *    (فایل‌های عمومیِ ریشه — README/LICENSE/… — از قبل در مخزنِ عمومی
 *    هستند و این اسکریپت آن‌ها را **دوباره نمی‌فرستد**.)
 *  • `dist-pishdad/` (بستهٔ خامِ قبلی) و `dist-public/` خودش.
 *
 * @see build-distribution.mjs   بستهٔ خام (شاملِ docs/Run/Logo)
 * @see verify-distribution.mjs  ممیزِ مستقل
 */

import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import {
  cpSync,
  existsSync,
  mkdirSync,
  readFileSync,
  readdirSync,
  rmSync,
  statSync,
  unlinkSync,
  writeFileSync,
} from "node:fs";
import { dirname, join, relative, resolve, sep } from "node:path";
import { fileURLToPath } from "node:url";

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, "..");

const positional = process.argv.slice(2).filter((a) => !a.startsWith("--"));
const OUT_NAME = positional[0] ?? "dist-public";
const OUT = resolve(ROOT, OUT_NAME);
const REPORT = join(ROOT, ".local", "public-preview.txt");

const git = (...args) =>
  execFileSync("git", args, { cwd: ROOT, encoding: "utf8", maxBuffer: 64 * 1024 * 1024 });

/* ── ۱. فهرستِ سفید ─────────────────────────────────────────────────────── */

/**
 * ریشه‌های مجاز. هر چیزی بیرون از این‌ها **حتی اگر track باشد** عمومی نمی‌شود.
 *
 * ⚠️ `cms-core/installer/` یک README است که نصب‌کنندهٔ وب را توصیف می‌کند؛
 * `deploy/` استقرارِ بک‌اند/فرانت‌اند است. هر دو به «نصب‌کردنِ» همین دو نیمه
 * مربوط‌اند، پس با درخواستِ «فقط بک‌اند و فرانت‌اند» هم‌راستا هستند.
 */
const INCLUDE_ROOTS = ["cms-core/backend/", "cms-core/frontend/", "cms-core/installer/", "deploy/"];

/** استثناهای درونِ ریشه‌های مجاز، هر کدام با دلیلِ صریح. */
const EXCLUDE = [
  // ── لایهٔ تجاریِ خصوصی (DEPLOY-CENTRAL.md §۵) ──
  { re: /^cms-core\/backend\/plugins\/_quarantine\//, why: "کدِ صورت‌حساب/اپراتورِ مرکزی — نباید عمومی شود" },
  { re: /^cms-core\/backend\/plugins\/central\//, why: "افزونهٔ خصوصیِ central" },
  { re: /^cms-core\/backend\/plugins\/subscription\//, why: "افزونهٔ خصوصیِ subscription" },
  { re: /^cms-core\/frontend\/src\/app\/\(central\)\//, why: "صفحاتِ پنلِ مرکزی" },
  { re: /^cms-core\/backend\/tests\/Feature\/Central.*Test\.php$/, why: "تستِ مرکزی" },
  { re: /^cms-core\/backend\/tests\/Feature\/ServesCentralPlugin\.php$/, why: "تستِ مرکزی" },
  { re: /^cms-core\/backend\/tests\/Fixtures\/PluginCentral\//, why: "فیکسچرِ مرکزی" },

  // ── وابستگی و خروجیِ بیلد (باید با composer/npm ساخته شوند) ──
  { re: /(^|\/)vendor\//, why: "با composer نصب می‌شود، نه در مخزن" },
  { re: /(^|\/)node_modules\//, why: "با npm نصب می‌شود، نه در مخزن" },
  { re: /^cms-core\/frontend\/\.next\//, why: "خروجیِ بیلد" },
  { re: /^cms-core\/frontend\/out\//, why: "خروجیِ بیلد" },

  // ── رازها و بازمانده‌های محلی ──
  { re: /(^|\/)\.env($|\.)/, keep: (f) => f.endsWith(".env.example"), why: "رازِ واقعی (فقط .env.example می‌ماند)" },
  { re: /(^|\/)\.npmrc$/, why: "ممکن است توکنِ احراز داشته باشد" },
  {
    re: /^cms-core\/backend\/storage\//,
    keep: (f) => f.endsWith(".gitignore"),
    why: "آپلود/بکاپ/بازماندهٔ تست (فقط .gitignore خالی می‌ماند)",
  },
  { re: /\.log$/, why: "لاگ" },
  { re: /phpunit\.agent.*\.xml$/, why: "پیکربندیِ محلیِ ایجنت" },
];

const tracked = git("ls-files", "-z").split("\0").filter(Boolean);

const excluded = [];
const included = tracked.filter((f) => {
  if (!INCLUDE_ROOTS.some((r) => f.startsWith(r))) return false;

  for (const { re, keep, why } of EXCLUDE) {
    if (!re.test(f)) continue;
    if (keep && keep(f)) continue;
    excluded.push({ file: f, why });
    return false;
  }

  return true;
});

/* ── ۲. دروازهٔ فایل‌های حیاتی ───────────────────────────────────────────── */

/**
 * نبودِ هر کدام یعنی بسته نصب‌شدنی نیست — نه فقط ناقص.
 * (مکملِ `REQUIRED` در `verify-distribution.mjs`، ولی اینجا زودتر و با
 * پیامِ درستِ «tracked نیست» می‌ایستیم، چون تشخیص برای کاربر مفیدتر است.)
 */
const REQUIRED = [
  "cms-core/backend/composer.json",
  "cms-core/backend/.env.example",
  "cms-core/backend/artisan",
  "cms-core/backend/routes/api.php",
  "cms-core/backend/routes/install.php",
  "cms-core/backend/app/Http/Controllers/Install/InstallController.php",
  "cms-core/backend/resources/views/install/superadmin.blade.php",
  "cms-core/frontend/package.json",
  "cms-core/frontend/next.config.ts",
  "cms-core/frontend/public/sw.js",
  "cms-core/frontend/public/fonts/Vazirmatn-Regular.woff2",
];

const missing = REQUIRED.filter((f) => !included.includes(f));

if (missing.length) {
  console.error("\n✗ فایل‌های حیاتی track نیستند — بستهٔ عمومی نصب‌شدنی نمی‌شود:");
  for (const f of missing) console.error(`    ${f}`);
  console.error("\n  اول `git add` شوند (یا اشتباهاً gitignore شده‌اند).");
  process.exit(1);
}

/* ── ۳. ساخت ─────────────────────────────────────────────────────────────── */

if (existsSync(OUT)) rmSync(OUT, { recursive: true, force: true });
mkdirSync(OUT, { recursive: true });

let copied = 0;
let bytes = 0;

for (const rel of included) {
  const from = resolve(ROOT, rel);
  const to = join(OUT, rel);

  // `git ls-files` روی ویندوز می‌تواند مسیرهای عجیب بدهد؛ بیرون‌زدن از ریشه
  // نباید بی‌سروصدا رد شود.
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
  bytes += statSync(to).size;
}

/* ── ۴. ممیزیِ مستقل ────────────────────────────────────────────────────── */

/*
 * `verify-distribution.mjs` یک `DISTRIBUTION.json` می‌خواهد (و شمارشِ
 * `shipped_files === all.length - 1`). ما آن را **موقتاً** می‌نویسیم تا از
 * همان ممیزِ ثابت‌شده استفاده کنیم و بعد پاکش می‌کنیم — چون این فایل خودش
 * فهرستِ «چه چیزی حذف شد» را عمومی می‌کرد.
 */
const manifestPath = join(OUT, "DISTRIBUTION.json");
writeFileSync(
  manifestPath,
  `${JSON.stringify(
    {
      name: "pishdad-public",
      built_from: git("rev-parse", "HEAD").trim(),
      source_state: git("status", "--porcelain").trim() ? "dirty (temporary — verification only)" : "clean",
      content_digest: "temporary-manifest-for-verification",
      shipped_files: copied,
      shipped_bytes: bytes,
      install: ["see README.md"],
    },
    null,
    2,
  )}\n`,
  "utf8",
);

let verifyCode = 0;
try {
  execFileSync(process.execPath, [join(HERE, "verify-distribution.mjs"), OUT_NAME], {
    cwd: ROOT,
    stdio: "inherit",
  });
} catch (e) {
  verifyCode = typeof e.status === "number" ? e.status : 1;
} finally {
  if (existsSync(manifestPath)) unlinkSync(manifestPath);
}

/* ── ۵. گزارشِ مرور ─────────────────────────────────────────────────────── */

const walk = (dir, out = []) => {
  for (const e of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, e.name);
    if (e.isDirectory()) walk(full, out);
    else out.push(relative(OUT, full).split(sep).join("/"));
  }
  return out;
};

const shipped = walk(OUT);

const byRoot = new Map();
for (const f of shipped) {
  const key = f.split("/").slice(0, 2).join("/");
  const cur = byRoot.get(key) ?? { files: 0, bytes: 0 };
  cur.files++;
  cur.bytes += statSync(join(OUT, f)).size;
  byRoot.set(key, cur);
}

const excludedByReason = new Map();
for (const { why } of excluded) excludedByReason.set(why, (excludedByReason.get(why) ?? 0) + 1);

const lines = [];
lines.push(`درختِ عمومیِ پیشداد — گزارشِ مرور`);
lines.push(`ساخته‌شده از: ${git("rev-parse", "HEAD").trim()}`);
lines.push(`خروجی: ${OUT_NAME}/`);
lines.push("");
lines.push(`شامل: ${shipped.length} فایل، ${(bytes / (1024 * 1024)).toFixed(2)} MB`);
for (const [k, v] of [...byRoot].sort()) {
  lines.push(`  ${k.padEnd(28)} ${String(v.files).padStart(5)} فایل  ${(v.bytes / (1024 * 1024)).toFixed(2).padStart(7)} MB`);
}
lines.push("");
lines.push(`بیرون‌مانده‌ها (${excluded.length} فایل track‌شده که عمداً نرفت):`);
for (const [why, n] of [...excludedByReason].sort((a, b) => b[1] - a[1])) {
  lines.push(`  ${String(n).padStart(5)} × ${why}`);
}
lines.push("");
lines.push(`دروازه‌های عمومیِ ریشه (از قبل در مخزنِ عمومی، اینجا دوباره فرستاده نمی‌شوند):`);
lines.push(`  README.md، README.fa.md، LICENSE، CONTRIBUTING.md، CODE_OF_CONDUCT.md،`);
lines.push(`  SECURITY.md، COMPARISON.md، COMPARISON.fa.md، llms.txt، .github/workflows/tests.yml، assets/`);
lines.push("");
lines.push(`وضعیتِ ممیزیِ مستقل: ${verifyCode === 0 ? "PASS" : `FAIL (exit=${verifyCode})`}`);

mkdirSync(dirname(REPORT), { recursive: true });
writeFileSync(REPORT, `${lines.join("\n")}\n`, "utf8");

console.log("");
console.log(`✔ درختِ عمومی نوشته شد: ${OUT_NAME}/  — ${shipped.length} فایل، ${(bytes / (1024 * 1024)).toFixed(2)} MB`);
console.log(`  گزارشِ مرور: .local/public-preview.txt`);
console.log(`  ممیزیِ مستقل: ${verifyCode === 0 ? "PASS" : `FAIL (exit=${verifyCode})`}`);

process.exit(verifyCode);
