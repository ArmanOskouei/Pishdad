#!/usr/bin/env node
/**
 * J3 — نگهبانِ «بیلدِ مشتری، پنلِ مرکزی ندارد».
 *
 * ## این تست چه می‌سنجد
 *
 * سه چیز، که هر سه در عمل شکسته شده‌اند:
 *
 *  ۱. **هسته importی از پنلِ مرکزی ندارد.** اگر روزی `import ... from '../(central)/…'`
 *     اضافه شود، بیلدِ مشتری **می‌شکند** (فایل نیست) — و آن خطا در CI خودِ مشتری
 *     می‌آید، نه در بیلدِ مرکزی. پس باید اینجا گرفته شود.
 *  ۲. **مرحلهٔ `client` در Dockerfile واقعاً پوشه را کنار می‌گذارد.** یک تایپوی کوچک
 *     در الگوی `COPY --exclude` یعنی ۲۱۴۷ خط روی دیسکِ هر مشتری، بی‌سروصدا.
 *  ۳. **پرچمِ runtime به بیلدِ درست وصل است.** بدون آن، اپراتورِ یک نصبِ مرکزی و
 *     کاربرِ یک نصبِ مشتری هر دو یک ۴۰۴ِ یکسان می‌بینند و نمی‌فهمند کدام نسخه را دارند.
 *
 * ⚠️ چرا این‌ها تست و نه فقط یادداشت؟ چون هر سه بارها «درست» به نظر رسیده بودند و
 * هر سه ناقص بودند: مرحلهٔ `client` وجود نداشت (فقط یک `rm` بعد از `COPY` بود)،
 * پرچم به هیچ متغیری وصل نبود، و کسی نشانده بود که `rm` کافی است.
 *
 * اجرا: `node scripts/check-client-only-build.mjs`
 */

import { readFileSync, existsSync, readdirSync, statSync } from "node:fs";
import { dirname, join, relative, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const failures = [];

const fail = (msg) => failures.push(msg);

// ---------------------------------------------------------------------------
// ۱) هیچ کدِ بیرونِ پنلِ مرکزی نباید از آن import کند.
// ---------------------------------------------------------------------------

const srcRoot = join(root, "src");
const centralRoot = join(srcRoot, "app", "(central)");

/** همهٔ فایل‌های TS/TSX زیر `src`، بدون پوشه‌های بی‌ربط. */
const walk = (dir, out = []) => {
  if (!existsSync(dir)) return out;
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, entry.name);
    if (entry.isDirectory()) walk(p, out);
    else if (/\.(ts|tsx)$/.test(entry.name)) out.push(p);
  }
  return out;
};

for (const file of walk(srcRoot)) {
  // خودِ پنلِ مرکزی طبیعی است که به خودش اشاره کند.
  if (file.startsWith(centralRoot)) continue;

  const source = readFileSync(file, "utf8");

  // فقط importهای واقعی. یک اشارهٔ متنی در کامنت (مثل `domain.ts` که مسیر را در
  // مستندات نام می‌برد) مشکلی نیست — و اگر آن را هم می‌گرفتیم، این تست فقط
  // کامنت‌ها را جریمه می‌کرد و به‌زودی کسی برای سبز‌شدنش کامنت را پاک می‌کرد.
  const importLines = source
    .split("\n")
    .filter((l) => /^\s*(import|export)\s.*from\s+['"]/.test(l) || /^\s*import\s+['"]/.test(l));

  for (const line of importLines) {
    if (line.includes("(central)")) {
      fail(
        `${relative(root, file)} از پنلِ مرکزی import می‌کند:\n    ${line.trim()}\n` +
          `    ⇒ بیلدِ مشتری می‌شکند، چون این پوشه در آن بیلد وجود ندارد.`,
      );
    }
  }
}

// ---------------------------------------------------------------------------
// ۲) مرحلهٔ `client` باید با exclude کپی کند، نه با rm بعد از COPY.
// ---------------------------------------------------------------------------

const dockerfile = join(root, "Dockerfile");

if (!existsSync(dockerfile)) {
  fail("Dockerfile پیدا نشد.");
} else {
  const df = readFileSync(dockerfile, "utf8");

  const clientStage = /^FROM\s+base\s+AS\s+client\b/m.test(df);
  if (!clientStage) {
    fail("Dockerfile مرحلهٔ `client` ندارد (بدون آن `--target client` کار نمی‌کند).");
  }

  // `COPY . .` در مرحلهٔ client یعنی پوشه وارد context می‌شود. exclude باید صریح باشد.
  const clientStageBody = df.split(/^FROM\s+base\s+AS\s+client\s*$/m)[1]?.split(/^FROM\s/m)[0] ?? "";
  if (clientStageBody) {
    if (!/COPY\s+--exclude=/.test(clientStageBody)) {
      fail(
        "مرحلهٔ `client` از `COPY --exclude=` استفاده نمی‌کند.\n" +
          "    ⇒ پوشهٔ `(central)` وارد لایهٔ ایمیج می‌شود و فقط از باندل بیرون می‌آید.",
      );
    }
    // ⚠️ نگهبان، `rm` نیست — `test ! -e` است. فرقش مهم است: `rm` بعد از کپی
    // دقیقاً همان کاری است که از آن فرار می‌کنیم (فایل در لایه می‌ماند)، پس
    // نباید دنبالش بگردیم. نگهبان یعنی «اگر فایل هست، بیلد را بشکن».
    if (!/test\s+!\s+-[ed]/.test(clientStageBody)) {
      fail(
        "مرحلهٔ `client` نگهبانِ `test ! -e` ندارد.\n" +
          "    ⇒ اگر الگوی exclude روزی خراب شود، هیچ‌چیز لو نمی‌دهد.",
      );
    }
    if (!/CMS_CLIENT_ONLY_BUILD=1/.test(clientStageBody)) {
      fail("مرحلهٔ `client` پرچمِ `CMS_CLIENT_ONLY_BUILD=1` را ست نمی‌کند.");
    }
  }

  const centralStageBody = df.split(/^FROM\s+base\s+AS\s+central\b.*$/m)[1]?.split(/^FROM\s/m)[0] ?? "";
  if (centralStageBody && !/CMS_CLIENT_ONLY_BUILD=0/.test(centralStageBody)) {
    fail("مرحلهٔ `central` پرچمِ `CMS_CLIENT_ONLY_BUILD=0` را ست نمی‌کند.");
  }
}

// ---------------------------------------------------------------------------
// ۳) compose باید هدفِ درست را صدا بزند، نه build-arg.
// ---------------------------------------------------------------------------

const compose = join(root, "docker-compose.yml");

if (!existsSync(compose)) {
  fail("docker-compose.yml پیدا نشد.");
} else {
  const yml = readFileSync(compose, "utf8");

  if (/CLIENT_ONLY:\s*1/.test(yml)) {
    fail(
      "docker-compose.yml هنوز از `build.args.CLIENT_ONLY` استفاده می‌کند.\n" +
        "    ⇒ آن build-arg دیگر وجود ندارد، پس compose یک بیلدِ کامل می‌سازد و پنلِ",
      "    مرکزی بی‌سروصدا داخل می‌ماند.",
    );
  }

  if (!/target:\s*client/.test(yml)) {
    fail("docker-compose.yml هدفِ `target: client` را ندارد.");
  }
  if (!/target:\s*central/.test(yml)) {
    fail("docker-compose.yml سرویسِ مرکزی (`target: central`) ندارد.");
  }
  if (!/CMS_CLIENT_ONLY_BUILD:\s*"1"/.test(yml)) {
    fail("docker-compose.yml پرچمِ runtime را برای سرویسِ مشتری ست نمی‌کند.");
  }
}

// ---------------------------------------------------------------------------
// ۴) پرچم باید دقیقاً '1' باشد، نه یک بررسیِ truthy.
// ---------------------------------------------------------------------------

const flavour = join(srcRoot, "lib", "build-flavour.ts");

if (!existsSync(flavour)) {
  fail("src/lib/build-flavour.ts پیدا نشد (پرچمِ بیلد تعریف نشده).");
} else {
  const source = readFileSync(flavour, "utf8");
  if (/Boolean\s*\(\s*process\.env/.test(source) || /!!\s*process\.env/.test(source)) {
    fail(
      "build-flavour.ts از بررسیِ truthy استفاده می‌کند.\n" +
        "    ⇒ داکر رشتهٔ \"0\" را هم true می‌دهد و بیلدِ مرکزی خود را مشتری جا می‌زند.",
    );
  }
  if (!/===\s*'1'/.test(source)) {
    fail("build-flavour.ts باید دقیقاً با `=== '1'` بسنجد.");
  }
}

// ---------------------------------------------------------------------------

if (failures.length > 0) {
  console.error(`\n✗ J3 — ${failures.length} مشکل:\n`);
  for (const f of failures) console.error(`  • ${f}\n`);
  process.exit(1);
}

console.log("✓ J3 — بیلدِ مشتری واقعاً جدا است:");
console.log("  • هسته هیچ importی از پنلِ مرکزی ندارد");
console.log("  • مرحلهٔ `client` از COPY --exclude استفاده می‌کند، با نگهبان");
console.log("  • compose هدفِ درست را صدا می‌زند (نه build-arg)");
console.log("  • پرچمِ runtime دقیقاً '1' را می‌سنجد، نه truthy");