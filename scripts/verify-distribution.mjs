#!/usr/bin/env node
/**
 * ممیزی بستهٔ توزیع (R1.2) — مستقل از اسکریپت ساخت.
 *
 * ## چرا یک اسکریپتِ جدا
 *
 * `build-distribution.mjs` خودش ادعا می‌کند که پاک است. اگر همان اسکریپت
 * را دوباره صدا بزنیم، همان فرض‌ها را دوباره می‌کند و همان خطاها را نادیده
 * می‌گیرد. اینجا فرض نمی‌کنیم — **خودِ پوشهٔ خروجی** را می‌خوانیم و
 * دنبال چیزهایی می‌گردیم که نباید آنجا باشند.
 *
 * ## اجرا
 *
 *     node scripts/verify-distribution.mjs dist-pishdad
 *
 * خروجیِ صفر یعنی بسته قابل توزیع است.
 *
 * @see build-distribution.mjs
 */

import { execFileSync } from "node:child_process";
import { existsSync, readFileSync, readdirSync, statSync } from "node:fs";
import { dirname, resolve, join, relative, sep } from "node:path";
import { fileURLToPath } from "node:url";

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, "..");

const pkgName = process.argv[2] ?? "dist-pishdad";
const PKG = resolve(ROOT, pkgName);

if (!existsSync(PKG)) {
  console.error(`verify-distribution: no such package: ${pkgName}`);
  process.exit(2);
}

/* ── بازگشت به‌عمق ─────────────────────────────────────────────────────── */

const walk = (dir, out = []) => {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) walk(full, out);
    else out.push(full);
  }
  return out;
};

const all = walk(PKG).map((f) => relative(PKG, f).split(sep).join("/"));
const rel = (f) => relative(PKG, f).split(sep).join("/");

const problems = [];
const notes = [];

/* ── ۱. مسیرهای ممنوع ──────────────────────────────────────────────────── */

/**
 * پوشه‌هایی که خودشان هم ممنوع‌اند، حتی اگر **خالی** باشند.
 *
 * ⚠️ بررسیِ فایل‌ها کافی نیست: یک پوشهٔ خالیِ `plugins/central/` در بسته
 * یعنی ساختارِ خصوصی لو رفته و هر ابزاری که آنجا را نگاه کند می‌فهمد
 * این بسته از یک نصبِ مرکزی آمده. برای همین پوشه‌ها را جدا می‌سنجیم،
 * نه فقط محتوایشان را.
 */
const FORBIDDEN_DIRS = [
  { path: "cms-core/backend/plugins/central", why: "the private central plugin (even empty)" },
  { path: "cms-core/backend/plugins/subscription", why: "the private subscription plugin (even empty)" },
  { path: "cms-core/backend/plugins/_quarantine", why: "quarantined plugins/remote subscription layer (even empty)" },
  { path: "cms-core/backend/vendor", why: "installed by composer" },
  { path: "cms-core/frontend/node_modules", why: "installed by npm" },
  { path: "cms-core/frontend/.next", why: "build output" },
];

for (const { path: p, why } of FORBIDDEN_DIRS) {
  if (existsSync(join(PKG, ...p.split("/")))) {
    problems.push(`${p}/ — must not ship: ${why}`);
  }
}

/**
 * پوشه‌ها و الگوهایی که هرگز نباید در بستهٔ عمومی باشند.
 *
 * 🔴 `plugins/central/` مهم‌ترینِ این لیست است: پنل مرکزی افزونهٔ
 * خصوصیِ همین نصب است. اگر وارد بسته شود، بازدهی مشتری، آستانه‌های
 * صورتحساب و دروازهٔ اپراتور به‌طور عمومی قابل خواندن می‌شود
 * (`docs/DEPLOY-CENTRAL.md` ج۸: نصب تازهٔ هسته باید صفر اثر مرکزی داشته باشد).
 *
 * ⚠️ `storage/` هم استثنای کوچکی دارد: لاراول به آن پوشه‌ها نیاز دارد و
 * خودِ `.gitignore` خالیِ هرکدام تنها چیزی است که آن‌ها را در گیت نگه
 * می‌دارد. پس فایل‌های `.gitignore` داخل `storage/` مجازند، ولی **هر
 * فایل دیگری** آنجا ممنوع است — یعنی هر آپلود، بکاپ یا بازماندهٔ تست.
 */
const FORBIDDEN_PATHS = [
  { re: /^cms-core\/backend\/plugins\/central\//, why: "the private central plugin" },
  { re: /^cms-core\/backend\/plugins\/subscription\//, why: "the private subscription plugin" },
  { re: /^cms-core\/backend\/plugins\/_quarantine\//, why: "quarantined plugins/remote subscription layer" },
  { re: /^cms-core\/backend\/vendor\//, why: "installed by composer" },
  { re: /^cms-core\/frontend\/node_modules\//, why: "installed by npm" },
  { re: /^cms-core\/frontend\/\.next\//, why: "build output" },
  { re: /^cms-core\/frontend\/out\//, why: "build output" },
  { re: /(^|\/)node_modules\//, why: "installed by npm" },
  { re: /(^|\/)vendor\//, why: "installed by composer" },
  { re: /^dist-/, why: "a previous package" },
  { re: /^graphify-out\//, why: "local tooling output" },
  { re: /^docs\/.*\.(pdf|png|jpe?g|webp)$/i, why: "binary docs, gitignored by design" },
  {
    re: /^cms-core\/backend\/storage\//,
    except: /(^|\/)\.gitignore$/,
    why: "uploads, backups and test leftovers (only the empty .gitignore placeholders may ship)",
  },
];

for (const f of all) {
  for (const { re, except, why } of FORBIDDEN_PATHS) {
    if (!re.test(f)) continue;
    if (except && except.test(f)) continue;
    problems.push(`${f} — must not ship: ${why}`);
  }
}

/* ── ۲. فایل‌هایی که وجودشان فاجعه است ─────────────────────────────────── */

/** نام فایل‌هایی که فقط در یک نصبِ واقعی معنا دارند. */
const FORBIDDEN_NAMES = [
  { re: /^\.env$/, why: "real secrets (only .env.example may ship)" },
  { re: /^\.env\.(local|production|staging)$/, why: "real secrets" },
  { re: /^\.npmrc$/, why: "may hold an auth token (only .npmrc.example may ship)" },
  { re: /\.(pem|key|p12|pfx|jks|keystore)$/i, why: "a private key or keystore" },
  { re: /^id_(rsa|dsa|ecdsa|ed25519)$/, why: "a private key" },
  { re: /^\.env\.backup$/, why: "a copy of .env" },
  { re: /~$/, why: "an editor backup file" },
  { re: /\.log$/, why: "a log file" },
  { re: /^phpunit\.agent.*\.xml$/, why: "local agent config" },
];

for (const f of all) {
  const base = f.split("/").pop();
  for (const { re, why } of FORBIDDEN_NAMES) {
    if (re.test(base)) problems.push(`${f} — must not ship: ${why}`);
  }
}

/* ── ۳. الگوهای محتوایی داخل فایل‌های متنی ─────────────────────────────── */

/**
 * الگوهای محتوایی داخل فایل‌های متنی.
 *
 * ⚠️ هر کدام یک استثنا دارد و استثنا **باریک** است:
 *
 *  • `APP_KEY` — فقط مقدارِ آزمایشیِ صریحاً برچسب‌خورده در `phpunit.xml`.
 *  • `DB_PASSWORD` — فقط مقدارِ نمونه در `.env.example`، چون همان فایل
 *    *تعریف* رمز است و بدون مقدار بی‌معنی می‌شود. نکتهٔ مهم: این فقط
 *    برای `.env.example` است؛ اگر همین مقدار داخل `.env` واقعی پیدا شود
 *    یعنی فایل `.env` اشتباهی track شده و باید fail شود.
 *  • `PRIVATE KEY` — رشتهٔ PEM در *کد* (مثل `derToPem()`) است، نه یک
 *    کلید. تشخیصش با این است که بدنهٔ بین دو خط `BEGIN`/`END` فقط base64
 *    باشد و طولش کمتر از ۲ KB — یعنی یک کلید واقعی هم همین شکلی است، پس
 *    برای فایل‌های غیرکد سخت‌گیر می‌مانیم.
 */
const SECRET_PATTERNS = [
  {
    // ⚠️ بدنهٔ این الگو **فقط base64** است. عمداً سخت‌گیرانه نوشته شده تا
    // با کدِ `VapidKeys::derToPem()` تطبیق نکند: آنجا بین دو خطِ BEGIN/END
    // کدِ PHP می‌آید (تودرکردنِ رشته، `chunk_split`، `{$var}`) و هیچ‌کدام
    // از آن کاراکترها در base64 نیستند.
    //
    // استثنای قبلی `exceptFile: (f) => f.endsWith(".php")` یک **سوراخ** بود:
    // یک کلیدِ واقعی که داخل هر فایل PHP دیگری paste می‌شد بی‌صدا رد
    // می‌شد. حالا هیچ استثنایی لازم نیست و یک کلید در هر فایلی — از جمله
    // PHP — fail می‌شود.
    re: /-----BEGIN (?:RSA |EC |OPENSSH |PGP )?PRIVATE KEY-----[ \t]*\r?\n(?:[A-Za-z0-9+/]{1,76}[ \t]*={0,2}[ \t]*\r?\n){3,}[A-Za-z0-9+/]{1,76}[ \t]*={0,2}[ \t]*\r?\n?-----END (?:RSA |EC |OPENSSH |PGP )?PRIVATE KEY-----/,
    why: "a real private key",
  },
  { re: /\bAKIA[0-9A-Z]{16}\b/, why: "an AWS access key id" },
  { re: /\bghp_[A-Za-z0-9]{36}\b/, why: "a GitHub token" },
  { re: /\bsk-[A-Za-z0-9]{32,}\b/, why: "an OpenAI-style API key" },
  { re: /\bxox[baprs]-[A-Za-z0-9-]{10,}\b/, why: "a Slack token" },
  {
    re: /\beyJhbGciOi[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{20,}/,
    why: "a JWT — a token or a key in the wild",
    // JWT در `VapidKeysTest` عمداً ساخته می‌شود تا امضای ES256 تست شود.
    exceptFile: (f) => /tests?\//.test(f),
  },
  {
    re: /APP_KEY\s*=\s*base64:[A-Za-z0-9+/=]{40,}/,
    why: "a real Laravel APP_KEY, not a placeholder",
    exceptMatch: (m) => m.includes("PR8akB+5eVsv5VdD91nrhoHRsz+bo57kKRpCi3jAJmY="),
  },
  {
    re: /DB_PASSWORD\s*=\s*(\S{8,})/,
    why: "a database password",
    // مقدارِ نمونه در فایلِ نمونه مجاز است؛ در هر فایلِ دیگری نه.
    exceptFile: (f) => f.endsWith(".env.example") || f.endsWith(".env.example.txt"),
  },
];

/** مقادیرِ نمونهٔ شناخته‌شده که فقط در فایل مرجع می‌آیند. */
const KNOWN_PLACEHOLDERS = new Set(["cmssecret", "secret", "password", "changeme", "root"]);

const TEXT_EXT = /\.(php|ts|tsx|js|jsx|mjs|cjs|json|md|yml|yaml|txt|env|example|ps1|sh|css|html|xml|sql|cs)$/i;
const MAX_SCAN = 4 * 1024 * 1024;

let scanned = 0;

for (const f of all) {
  const base = f.split("/").pop();

  if (!TEXT_EXT.test(base) && !/^\.env/.test(base) && !/^\.gitattributes$/.test(base)) continue;

  const full = join(PKG, f);
  if (statSync(full).size > MAX_SCAN) {
    notes.push(`${f} — skipped (larger than ${MAX_SCAN} bytes)`);
    continue;
  }

  scanned++;
  let text;
  try {
    text = readFileSync(full, "utf8");
  } catch {
    continue;
  }

  for (const { re, why, exceptFile, exceptMatch } of SECRET_PATTERNS) {
    const m = re.exec(text);
    if (!m) continue;

    const line = text.slice(0, m.index).split("\n").length;

    // استثنای «فقط سرِ رشته در کد» — باید مطمئن شویم *بدنه* هم در فایل
    // نیست، وگرنه یک کلیدِ واقعی داخل کد رد می‌شد.
    if (exceptFile?.(f)) continue;
    if (exceptMatch?.(m[0])) continue;

    // مقدارِ نمونه در فایل مرجع مجاز است.
    if (/^\.env/.test(base)) {
      const val = m[1];
      if (val && KNOWN_PLACEHOLDERS.has(val)) continue;
    }

    problems.push(`${f}:${line} — looks like ${why}`);
  }
}

/* ── ۴. فایل‌های لازم ──────────────────────────────────────────────────── */

/**
 * E25 — اسنادِ عمومی هم «لازم»‌اند، نه فقط فایل کد. بسته‌ای که README یا
 * LICENSE ندارد نه خواندنی است و نه از نظر حقوقی قابل استناد؛ و چون
 * `REQUIRED` قبلاً فقط کد را می‌سنجید، ممیزی از کنارشان رد می‌شد.
 */
const PUBLIC_DOCS = [
  "README.md",
  "README.fa.md",
  "LICENSE",
  "CONTRIBUTING.md",
  "SECURITY.md",
  "COMPARISON.md",
  "llms.txt",
  "docs/PLUGIN-GUIDE.md",
  "docs/PLUGIN-GUIDE.en.md",
  "docs/PLUGIN-MANIFEST-CONTRACT.md",
  "docs/PLUGIN-MANIFEST-CONTRACT.en.md",
  "docs/SEARCH-PLUGIN-CONTRACT.md",
  "docs/SEARCH-PLUGIN-CONTRACT.en.md",
  "docs/PLUGIN-ECOSYSTEM-ARCHITECTURE.md",
  "docs/PLUGIN-ECOSYSTEM-ARCHITECTURE.en.md",
  "docs/DEVELOPER-QUICKSTART.en.md",
];

/**
 * E41 — بستهٔ تک‌بخش فقط فایل‌های همان بخش را دارد. part از
 * `DISTRIBUTION.json` خوانده می‌شود (بسته‌های قدیمیِ بدون part یعنی full).
 */
const part = (() => {
  try {
    const m = JSON.parse(readFileSync(join(PKG, "DISTRIBUTION.json"), "utf8"));
    return m.part ?? "full";
  } catch {
    return "full";
  }
})();

const inPart = (f) =>
  part === "full"
    ? true
    : part === "backend"
      ? f.startsWith("cms-core/backend/") || f === "DISTRIBUTION.json"
      : f.startsWith("cms-core/frontend/") || f === "DISTRIBUTION.json";

const REQUIRED_ALL = [
  "cms-core/backend/composer.json",
  "cms-core/backend/.env.example",
  "cms-core/backend/artisan",
  "cms-core/backend/routes/api.php",
  "cms-core/backend/database/seeders/PishdadSiteSeeder.php",
  "cms-core/backend/database/seeders/data/pishdad-pages.php",
  "cms-core/backend/app/Services/Push/PushSender.php",
  "cms-core/backend/app/Services/Push/VapidKeys.php",
  "cms-core/backend/database/migrations/2026_10_20_000001_create_push_subscriptions_table.php",
  "cms-core/frontend/package.json",
  "cms-core/frontend/public/sw.js",
  "cms-core/frontend/src/lib/push.ts",
  "cms-core/frontend/public/fonts/Vazirmatn-Regular.woff2",
  "DISTRIBUTION.json",
];

const REQUIRED = REQUIRED_ALL.filter(inPart);

for (const f of REQUIRED) {
  if (!all.includes(f)) problems.push(`missing required file: ${f}`);
}

/* اسنادِ عمومی — جدا از REQUIRED گزارش می‌شوند تا وقتی جا افتادند،
   پیامشان صریحاً بگوید «سند عمومی» است، نه «فایل لازم». */
const missingDocs = PUBLIC_DOCS.filter((f) => !all.includes(f));

if (missingDocs.length) {
  problems.push(
    `${missingDocs.length} public document(s) missing from the package — a release without ` +
      `them is unreadable and legally meaningless:\n` +
      missingDocs.map((f) => `      ${f}`).join("\n"),
  );
}

/* ── ۵. manifest ───────────────────────────────────────────────────────── */

const manifestPath = join(PKG, "DISTRIBUTION.json");

if (existsSync(manifestPath)) {
  let m;
  try {
    m = JSON.parse(readFileSync(manifestPath, "utf8"));
  } catch (e) {
    problems.push(`DISTRIBUTION.json is not valid JSON: ${e.message}`);
  }

  if (m) {
    for (const k of ["name", "built_from", "install", "shipped_files"]) {
      if (!m[k] && m[k] !== 0) problems.push(`DISTRIBUTION.json has no "${k}"`);
    }

    // `-1` چون خودِ manifest یکی از فایل‌هاست و بعد از شمارش ساخته شد.
    if (typeof m.shipped_files === "number" && m.shipped_files !== all.length - 1) {
      problems.push(
        `manifest says ${m.shipped_files} shipped files but the tree has ${all.length - 1} (excluding the manifest)`,
      );
    }

    // `content_digest` باید حتماً باشد: `built_from` وقتی درخت dirty است
    // به بسته اشاره نمی‌کند و بدون digest هیچ راهی برای شناسایی بسته نیست.
    if (!m.content_digest) {
      problems.push("DISTRIBUTION.json has no content_digest");
    }
  }
} else {
  problems.push("no DISTRIBUTION.json");
}

/* ── ۶. آیا منبعِ untracked از بسته جا مانده؟ ─────────────────────────────── */

/*
 * ⚠️ این بخش از یک باگ واقعی اضافه شد.
 *
 * بسته از روی `git ls-files` ساخته می‌شود، یعنی فقط **tracked**‌ها. ولی
 * بررسی‌های قبلی فقط می‌پرسیدند «فایل‌های لازم هست؟» — و آن‌ها هستند. پس
 * بسته PASS می‌شد در حالی‌که ۱۲۰ مسیرِ untracked از جمله ۲۰+ migration
 * (از جمله `create_media`)، کل `installer/`، سرویس‌های Subscription/Market/
 * Outbox و حدود ۲۰ کتابخانهٔ فرانت **داخل بسته نبودند**. یعنی بسته
 * عملاً نصب‌شدنی نبود و هیچ خطایی هم نمی‌داد.
 *
 * پس اینجا برعکس بررسی می‌کنیم: هر فایلِ منبع که روی دیسک هست ولی
 * tracked نیست، یعنی از بسته جا مانده است. فایل‌های build/dependency با
 * فیلتر کنار گذاشته می‌شوند چون عمداً هرگز نباید بیایند.
 */
const SOURCE_DIRS_ALL = [
  "cms-core/backend/app",
  "cms-core/backend/config",
  "cms-core/backend/database",
  "cms-core/backend/resources",
  "cms-core/backend/routes",
  "cms-core/backend/tests",
  "cms-core/backend/tools",
  "cms-core/frontend/src",
  "cms-core/frontend/public",
  "cms-core/installer",
  // E24 — `scripts` و `Run` دیگر منبعِ بسته نیستند (dev-only)، پس اینجا هم نیستند.
];

/** E41 — در بستهٔ تک‌بخش فقط دایرکتوری‌های همان بخش وارسی می‌شوند. */
const SOURCE_DIRS = SOURCE_DIRS_ALL.filter((d) =>
  part === "full"
    ? true
    : part === "backend"
      ? !d.startsWith("cms-core/frontend/")
      : !d.startsWith("cms-core/backend/"),
);

const NOT_SOURCE =
  /\/(node_modules|vendor|\.next|out|storage\/app|plugins\/central|plugins\/subscription|plugins\/_quarantine|\(central\)|graphify-out|dist-pishdad)\//;

function untrackedSource() {
  let out;
  try {
    out = execFileSync("git", ["ls-files", "--others", "--exclude-standard", "--", ...SOURCE_DIRS], {
      cwd: ROOT,
      encoding: "utf8",
    });
  } catch {
    return []; // git در دسترس نیست ⇒ نمی‌توان قضاوت کرد، و دروغ هم نمی‌گوییم
  }

  return out.split(/\r?\n/).filter(Boolean).filter((f) => !NOT_SOURCE.test(`/${f}`));
}

const missingFromPackage = untrackedSource();

if (missingFromPackage.length) {
  problems.push(
    `${missingFromPackage.length} untracked source file(s) are missing from the package — ` +
      `stage them or exclude them on purpose:\n` +
      missingFromPackage.slice(0, 12).map((f) => `      ${f}`).join("\n") +
      (missingFromPackage.length > 12 ? `\n      … and ${missingFromPackage.length - 12} more` : ""),
  );
}

/* ── ۷. گزارش ──────────────────────────────────────────────────────────── */

const totalBytes = all.reduce((n, f) => n + statSync(join(PKG, f)).size, 0);

console.log(`verify-distribution: ${pkgName}/`);
console.log(`  ${all.length} files, ${(totalBytes / 1024 / 1024).toFixed(1)} MB, ${scanned} text files scanned`);
console.log(`  untracked source files missing from the package: ${missingFromPackage.length}`);

if (notes.length) {
  console.log(`\n  notes:`);
  notes.forEach((n) => console.log(`    · ${n}`));
}

if (problems.length) {
  console.error(`\n  FAIL — ${problems.length} problem(s):`);
  problems.forEach((p) => console.error(`    ✗ ${p}`));
  process.exit(1);
}

console.log("\n  PASS — no secrets, no private plugin, no local data; all required files present; no untracked source left behind.");