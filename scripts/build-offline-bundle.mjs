#!/usr/bin/env node
/**
 * بستهٔ آفلاین — نسخه‌ای که هنگامِ نصب **هیچ چیزی دانلود نمی‌کند**.
 *
 * ## مسئله
 *
 * بستهٔ سبک (`dist-pishdad`) عمداً وابستگی‌ها را حمل نمی‌کند: کاربر `composer`
 * می‌زند، `npm` می‌زند، و `docker` ایمیج می‌کشد. روی ماشینی بدونِ اینترنت — یا
 * با اینترنتِ محدود — هیچ‌کدام از این‌ها کار نمی‌کند.
 *
 * این اسکریپت همان بسته را برمی‌دارد و **همه چیز** را کنارش می‌گذارد:
 *
 *   • `vendor/` لاراول (بدونِ اجرای composer هنگامِ نصب)
 *   • فرانتِ بیلدشده، داخلِ ایمیج (بدونِ `npm install` و بدونِ نیاز به Node روی هاست)
 *   • شش ایمیجِ داکر به‌صورت `docker save` (بدونِ `docker pull`)
 *   • یک compose آفلاین با `pull_policy: never`
 *   • `install.ps1` / `install.sh` که اول `docker load` می‌کند و بعد بالا می‌آورد
 *
 * نتیجه: کاربر فقط Docker Desktop را از قبل دارد و بعد از آن **صفر دانلود**.
 *
 * ## چرا تگِ محلی و نه تگِ اصلی
 *
 * minio با **digest** پین شده و nginx/redis با تگ. اگر compose آفلاین همان
 * ارجاع‌ها را نگه دارد، `docker load` باید همان digest/tag را بازگرداند و هر
 * اختلافی یعنی compose دوباره pull می‌کند — دقیقاً چیزی که نباید. پس همه را به
 * یک تگِ سادهٔ محلی (`pishdad/<name>:<version>`) برمی‌گردانیم و compose آفلاین
 * فقط همان را می‌شناسد.
 *
 * ## اجرا
 *
 *   node scripts/build-offline-bundle.mjs                 # نسخه از package.json
 *   node scripts/build-offline-bundle.mjs --version 1.0.0
 *   node scripts/build-offline-bundle.mjs --skip-build    # فقط tar کردنِ ایمیج‌های موجود
  *   node scripts/build-offline-bundle.mjs --part backend   # E44: فقط بک‌اند (از dist-pishdad-backend)
  *   node scripts/build-offline-bundle.mjs --part frontend  # E44: فقط فرانت (از dist-pishdad-frontend)
  *
  * E44: هر بخش docker save خودش را دارد تا روی سرور جدا هم بدون دانلود نصب شود.
 */

import { execFileSync } from "node:child_process";
import { cpSync, existsSync, mkdirSync, readFileSync, rmSync, statSync, writeFileSync, readdirSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, "..");

const arg = (name, fallback = null) => {
  const i = process.argv.indexOf(name);
  return i > -1 ? process.argv[i + 1] : fallback;
};
const has = (name) => process.argv.includes(name);

const VERSION = arg("--version") ?? "1.0.0";
const SKIP_BUILD = has("--skip-build");

/** E44 — بخشِ درخواستی. مقدارِ نامعتبر = خطا (نه سکوت). */
const PART = arg("--part") ?? "full";
if (!["backend", "frontend", "full"].includes(PART)) {
  console.error(`✗ unknown --part: ${PART} (expected backend, frontend or full)`);
  process.exit(1);
}

const LEAN = join(ROOT, PART === "full" ? "dist-pishdad" : `dist-pishdad-${PART}`);
const OUT = join(ROOT, PART === "full" ? "dist-offline" : `dist-offline-${PART}`);
const IMAGES_DIR = join(OUT, "offline", "images");

const run = (cmd, args, opts = {}) =>
  execFileSync(cmd, args, { stdio: "inherit", cwd: ROOT, ...opts });

const tag = (name) => `pishdad/${name}:${VERSION}`;

/**
 * ایمیج‌هایی که باید در بسته باشند.
 *
 * `from` یعنی از کجا بیاید: `build` = از خودِ پروژه ساخته می‌شود، `cache` =
 * از ایمیجِ عمومیِ موجود روی ماشین (و اگر نبود، کشیده می‌شود).
 */
const IMAGES_ALL = [
  { name: "backend-app", from: "backend-build" },
  { name: "backend-db", from: "backend-db-build" },
  { name: "frontend", from: "frontend-build" },
  { name: "nginx", from: "cache", source: "nginx:1.31.6-alpine" },
  { name: "redis", from: "cache", source: "redis:8.10.2-alpine" },
  {
    name: "minio",
    from: "cache",
    // ⚠️ عمداً برچسبِ Docker Hub و نه آن digestِ پین‌شده‌ی quay.io که compose
    // استفاده می‌کند. دلیلش قابل‌راستی‌آزمایی است، نه سلیقه:
    //
    // ایمیجی که با `@sha256:` کشیده شده باشد، در snapshotterِ containerd
    // **ID و RepoDigest یکسان** می‌شود (`14cea493…`). آن حالت برای export
    // قابل‌استفاده نیست: `docker save` با کدِ خروجی **صفر** یک tarِ خالیِ
    // ۱۰ کیلوبایتی می‌نویسد و حتی `docker commit` هم نجاتش نمی‌دهد
    // (`no suitable export target found`). پس نسخهٔ پین‌شده هرگز داخل بسته
    // نمی‌نشیند و کاربر آفلاین مجبور به pull می‌شد — یعنی کل هدف نقض می‌شد.
    //
    // این نسخه از Docker Hub قابلِ‌ذخیره است (۵۶ مگابایت) و MinIO در این
    // پروژه فقط یک object store محلیِ توسعه است («Do not expose it» در
    // compose). نسخه‌اش از پینِ قبلی قدیمی‌تر است و همین در README بسته
    // مستند می‌شود تا کسی فکر نکند جابه‌جایی بی‌دلیل بوده.
    source: "minio/minio:RELEASE.2024-10-13T13-34-11Z",
  },
  { name: "minio-mc", from: "cache", source: "quay.io/minio/mc:RELEASE.2025-08-13T08-35-41Z" },
];

/**
 * E44 — هر بخش فقط ایمیج‌های خودش را حمل می‌کند: بستهٔ فرانت فقط ایمیجِ
 * فرانت، بستهٔ بک‌اند فقط ایمیج‌های بک‌اند. روی سرورِ جدا هم بدون دانلود
 * نصب می‌شود.
 */
const IMAGES = IMAGES_ALL.filter((img) =>
  PART === "full" ? true : PART === "backend" ? img.name !== "frontend" : img.name === "frontend",
);

const BACKEND = join(ROOT, "cms-core", "backend");
const FRONTEND = join(ROOT, "cms-core", "frontend");

function imageExists(ref) {
  try {
    execFileSync("docker", ["image", "inspect", ref], { stdio: "ignore" });
    return true;
  } catch {
    return false;
  }
}

function human(bytes) {
  return `${(bytes / 1024 / 1024).toFixed(0)} MB`;
}

/* ── ۰. پیش‌نیازها ─────────────────────────────────────────────────────── */

if (!existsSync(LEAN)) {
  console.error(`✗ بستهٔ سبک پیدا نشد: ${LEAN}\n  اول این را بزنید: node scripts/build-distribution.mjs`);
  process.exit(1);
}

try {
  execFileSync("docker", ["version", "--format", "{{.Server.Version}}"], { stdio: "ignore" });
} catch {
  console.error("✗ داکر در دسترس نیست. برای ساختِ بستهٔ آفلاین لازم است.");
  process.exit(1);
}

rmSync(OUT, { recursive: true, force: true });
mkdirSync(IMAGES_DIR, { recursive: true });

console.log(`offline bundle → ${OUT}`);
console.log(`version: ${VERSION}\n`);

/* ── ۱. ساختِ ایمیج‌ها ─────────────────────────────────────────────────── */

if (!SKIP_BUILD) {
  console.log("── building images ──");

  // E44 — فقط ایمیج‌های همین بخش ساخته می‌شوند.
  if (PART !== "frontend") {
    console.log("  backend app...");
    run("docker", ["compose", "-f", "docker-compose.yml", "build", "app"], { cwd: BACKEND });
    run("docker", ["tag", "pishdad-backend-app:latest", tag("backend-app")]);

    console.log("  backend db...");
    run("docker", ["compose", "-f", "docker-compose.yml", "build", "db"], { cwd: BACKEND });
    run("docker", ["tag", "pishdad-backend-db:latest", tag("backend-db")]);
  }

  if (PART !== "backend") {
    console.log("  frontend (standalone build, this one is the slow one)...");
    run("docker", ["build", "-t", tag("frontend"), "."], { cwd: FRONTEND });
  }
}

for (const img of IMAGES) {
  if (img.from !== "cache") continue;

  if (!imageExists(img.source)) {
    console.log(`  pulling ${img.source} (needed once, then it travels in the bundle)`);
    run("docker", ["pull", img.source]);
  }
  run("docker", ["tag", img.source, tag(img.name)]);
}

/* ── ۲. vendor/ لاراول ─────────────────────────────────────────────────── */
//
// ## چرا روی volume و نه مستقیم روی پوشهٔ پروژه
//
// نسخهٔ اول `composer install` را با `-v <backend>:/app` و vendorِ خروجی روی همان
// bind-mount می‌زد. روی ویندوز آن مسیر از virtiofs رد می‌شود و unzipِ هر بسته
// فایل‌به‌فایل انجام می‌شود: `aws/aws-sdk-php` از timeoutِ ۳۰۰ ثانیه‌ای composer
// گذشت و کلِ بیلد مرد.
//
// حالا نصب روی یک volumeِ داخلیِ کانتینر انجام می‌شود (فایل‌سیستمِ خودِ دیمن،
// سریع) و بعد **یک‌بار** به‌صورت tar بیرون کشیده می‌شود. کاربر هم در زمانِ نصب
// همان tar را باز می‌کند که چند مرتبه از unzipِ فایل‌به‌فایل سریع‌تر است.

// E44 — بستهٔ فرانت vendor ندارد (PHP اصلاً در آن نیست).
const NEED_VENDOR = PART !== "frontend";
const VENDOR_TAR = "vendor.tar.gz";

if (NEED_VENDOR) {
console.log("\n── backend vendor/ (into a container volume, then tarred out) ──");
const VENDOR_VOLUME = "pishdad-offline-vendor";

run("docker", ["volume", "rm", "-f", VENDOR_VOLUME], { stdio: "ignore" });
run("docker", ["volume", "create", VENDOR_VOLUME], { stdio: "ignore" });

const COMPOSER_IMAGE = "composer:2";
if (!imageExists(COMPOSER_IMAGE)) run("docker", ["pull", COMPOSER_IMAGE]);

run("docker", [
  "run", "--rm",
  "-v", `${BACKEND}:/src:ro`,
  "-v", `${VENDOR_VOLUME}:/vendor`,
  // بسته‌های بزرگ روی بعضی شبکه‌ها از ۳۰۰ ثانیه می‌گذرند؛ پیش‌فرض را بالا می‌بریم.
  "-e", "COMPOSER_PROCESS_TIMEOUT=1800",
  COMPOSER_IMAGE,
  "sh", "-lc",
  /*
   * ⚠️ E50 — چرا پروژه کپی می‌شود و vendor **داخلِ** پروژه ساخته می‌شود.
   *
   * پیش‌تر پروژه روی `/app` مانت و `COMPOSER_VENDOR_DIR=/vendor` ست می‌شد،
   * یعنی vendor **بیرونِ** پروژه. Composer در آن حالت `$baseDir` را `dirname`
   * مسیرِ vendor می‌گیرد که می‌شود `/`، و در classmap به‌جای `__DIR__ . '/../..'`
   * مسیرِ **مطلقِ** زمانِ بیلد را می‌نویسد:
   *
   *     'App\Providers\AppServiceProvider' => '/app' . '/app/Providers/AppServiceProvider.php'
   *
   * آن مسیر روی ماشینِ کاربر وجود ندارد، پس اولین درخواست با
   * `include(/app/app/Providers/AppServiceProvider.php)` می‌میرد. و چون
   * `vendor/autoload.php` «موجود» است، entrypoint هم `composer install` نمی‌زند
   * تا خودش را درمان کند — در نصبِ آفلاین که شبکه هم نیست، یعنی مرگِ کامل.
   *
   * الان پروژه داخلِ کانتینر و روی همان مسیرِ زمانِ اجرا (`/var/www/html`) کپی
   * می‌شود، پس vendor داخلِ پروژه می‌افتد و Composer `__DIR__ . '/../..'`
   * می‌نویسد که هر جا قابلِ حمل است. کپی روی فایل‌سیستمِ کانتینر سریع است؛
   * مسئله‌ای که باعث شد از volume استفاده شود (unzipِ فایل‌به‌فایل روی
   * bind-mountِ ویندوز) همچنان حل است.
   *
   * `--no-dev`: بستهٔ منتشرشده محیطِ production است.
   * `--no-scripts`: اسکریپتِ `post-autoload-dump` لاراول در `storage/logs/` و
   * `bootstrap/cache/` می‌نویسد؛ آن فایل‌ها داخلِ tar نیستند و لاراول در اولین
   * بوت خودش می‌سازدشان (`PackageManifest::build()`).
   */
  "cp -a /src/. /var/www/html/ && " +
    "cd /var/www/html && " +
    "composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader && " +
    "cp -a /var/www/html/vendor/. /vendor/",
]);

mkdirSync(join(OUT, "offline"), { recursive: true });
run("docker", [
  "run", "--rm",
  "-v", `${VENDOR_VOLUME}:/vendor:ro`,
  "-v", `${join(OUT, "offline")}:/out`,
  "alpine:3.24",
  "sh", "-lc",
  /*
   * 🛡️ E50 — گاردِ بیلد.
   *
   * یک مسیرِ مطلقِ زمانِ بیلد در classmap یعنی بسته روی مسیری ساخته شده که روی
   * ماشینِ کاربر وجود ندارد. این خطا **بی‌صدا** ساخته می‌شد و فقط سرِ نصبِ
   * کاربر کشنده بود، پس باید همین‌جا بیلد را متوقف کند.
   *
   * الگو عمداً `=> '/` است: حالتِ سالم `=> __DIR__ . '/../..' . '/app/…'` است و
   * حالتِ خراب `=> '/app' . '/app/…'` — فقط دومی با این الگو می‌خورد.
   */
  "if grep -Eq \"=> '/\" /vendor/composer/autoload_classmap.php /vendor/composer/autoload_static.php; " +
    "then echo '✗ E50: absolute build paths leaked into the autoloader - the offline install would fail at boot' >&2; exit 1; fi; " +
    "tar -czf /out/" + VENDOR_TAR + " -C /vendor .",
]);
run("docker", ["volume", "rm", "-f", VENDOR_VOLUME], { stdio: "ignore" });
} // NEED_VENDOR

/* ── ۳. بستهٔ سبک + اسکریپت‌ها ─────────────────────────────────────────── */

console.log("\n── lean package + installers ──");
for (const entry of readdirSync(LEAN)) {
  cpSync(join(LEAN, entry), join(OUT, entry), { recursive: true });
}
// vendor به‌صورت tar در `offline/` می‌رود (نه به‌عنوان پوشه زیر cms-core).
// اسکریپتِ نصب بازش می‌کند؛ دلیلش در بخشِ ۲ توضیح داده شده.
// E44 — فقط وقتی بک‌اند در بسته است.
if (NEED_VENDOR && !existsSync(join(OUT, "offline", VENDOR_TAR))) {
  console.error(`✗ ${VENDOR_TAR} ساخته نشد`);
  process.exit(1);
}

// نصب‌کننده‌ها و راهنما: فایل‌های ایستا زیر `scripts/offline/` هستند (نه رشتهٔ
// درونِ این اسکریپت) تا `${...}` پاورشل با template literal جاوااسکریپت قاطی نشود.
const OFFLINE_FILES = ["install.ps1", "install.sh", "README-OFFLINE.md"];
for (const f of OFFLINE_FILES) {
  cpSync(join(HERE, "offline", f), join(OUT, f));
}
// On Linux/macOS the shell installer has to be executable or `./install.sh` fails.
try {
  execFileSync("chmod", ["+x", join(OUT, "install.sh")], { stdio: "ignore" });
} catch {
  /* Windows has no chmod; the file is run through `sh install.sh` there anyway */
}

/* ── ۴. compose آفلاین ─────────────────────────────────────────────────── */

const backendOffline = `# Offline backend stack — generated by scripts/build-offline-bundle.mjs
#
# Every image below is already loaded from ../offline/images/*.tar, so nothing is
# built and nothing is pulled. \`pull_policy: never\` is what makes that a
# guarantee rather than a hope: without it, a missing image silently triggers a
# registry fetch and the whole point of the bundle is lost.
name: \${PISHDAD_PROJECT:-pishdad-backend-offline}

services:
  app:
    image: ${tag("backend-app")}
    container_name: \${PISHDAD_APP:-pishdad-app}
    restart: unless-stopped
    pull_policy: never
    environment:
      DB_HOST: db
      DB_PORT: 5432
      DB_USERNAME: cms
      DB_PASSWORD: cmssecret
      REDIS_HOST: redis
      REDIS_PORT: 6379
    volumes:
      - ./:/var/www/html
    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_started

  # E76 — تیکِ دائمیِ صف (مثل compose اصلی). از همان ایمیجِ app می‌آید و
  # entrypoint بعدِ آماده‌سازی، schedule:work را به‌جای php-fpm می‌نشاند.
  scheduler:
    image: ${tag("backend-app")}
    container_name: \${PISHDAD_SCHEDULER:-pishdad-scheduler}
    restart: unless-stopped
    pull_policy: never
    command: ["php", "artisan", "schedule:work"]
    environment:
      DB_HOST: db
      DB_PORT: 5432
      DB_USERNAME: cms
      DB_PASSWORD: cmssecret
      REDIS_HOST: redis
      REDIS_PORT: 6379
    volumes:
      - ./:/var/www/html
    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_started

  web:
    image: ${tag("nginx")}
    container_name: \${PISHDAD_WEB:-pishdad-web}
    restart: unless-stopped
    pull_policy: never
    ports:
      - "\${PISHDAD_WEB_PORT:-8080}:80"
    volumes:
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
      - ./public:/var/www/html/public:ro
      - ./storage/app/public:/var/www/html/storage/app/public:ro
    depends_on:
      - app

  db:
    image: ${tag("backend-db")}
    container_name: \${PISHDAD_DB:-pishdad-db}
    restart: unless-stopped
    pull_policy: never
    environment:
      POSTGRES_DB: cms
      POSTGRES_USER: cms
      POSTGRES_PASSWORD: cmssecret
      PGDATA: /var/lib/postgresql/data/pgdata
    ports:
      - "\${PISHDAD_DB_PORT:-5433}:5432"
    volumes:
      - pgdata:/var/lib/postgresql/data
      - ./docker/postgres/init:/docker-entrypoint-initdb.d:ro
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U cms -d cms"]
      interval: 5s
      timeout: 5s
      retries: 10

  redis:
    image: ${tag("redis")}
    container_name: \${PISHDAD_REDIS:-pishdad-redis}
    restart: unless-stopped
    pull_policy: never
    ports:
      - "\${PISHDAD_REDIS_PORT:-6380}:6379"
    volumes:
      - redisdata:/data

  minio:
    image: ${tag("minio")}
    container_name: \${PISHDAD_MINIO:-pishdad-minio}
    restart: unless-stopped
    pull_policy: never
    environment:
      MINIO_ROOT_USER: minioadmin
      MINIO_ROOT_PASSWORD: minioadmin123
    ports:
      - "\${PISHDAD_MINIO_PORT:-9100}:9000"
      - "\${PISHDAD_MINIO_CONSOLE_PORT:-9101}:9001"
    volumes:
      - miniodata:/data
    command: server /data --console-address ":9001"
    entrypoint: /usr/bin/minio

  minio-init:
    image: ${tag("minio-mc")}
    container_name: \${PISHDAD_MINIO_INIT:-pishdad-minio-init}
    pull_policy: never
    depends_on:
      minio:
        condition: service_started
    entrypoint:
      - /bin/sh
      - -c
      - |
        until mc alias set local http://minio:9000 minioadmin minioadmin123 >/dev/null 2>&1; do sleep 2; done
        mc mb -p local/cms >/dev/null 2>&1 || true
        mc anonymous set download local/cms >/dev/null 2>&1 || true
        echo "minio bucket ready"
    restart: "no"

volumes:
  pgdata:
  redisdata:
  miniodata:
`;

const frontendOffline = `# Offline frontend stack — generated by scripts/build-offline-bundle.mjs
name: \${PISHDAD_PROJECT:-pishdad-frontend-offline}

services:
  web-front:
    image: ${tag("frontend")}
    container_name: \${PISHDAD_FRONT:-pishdad-web-front}
    restart: unless-stopped
    pull_policy: never
    ports:
      - "\${PISHDAD_FRONTEND_PORT:-3100}:3000"
    environment:
      INTERNAL_API_URL: \${INTERNAL_API_URL:-http://host.docker.internal:8080/api}
    extra_hosts:
      - "host.docker.internal:host-gateway"
`;

// E44 — فقط compose همان بخشی که در بسته است نوشته می‌شود.
if (PART !== "frontend") {
  writeFileSync(join(OUT, "cms-core", "backend", "docker-compose.offline.yml"), backendOffline);
}
if (PART !== "backend") {
  writeFileSync(join(OUT, "cms-core", "frontend", "docker-compose.offline.yml"), frontendOffline);
}

const imageList = IMAGES.map((i) => i.name);
writeFileSync(
  join(OUT, "offline", "IMAGES.txt"),
  imageList.map((n) => `${n}.tar  ->  ${tag(n)}`).join("\n") + "\n",
);

/* ── ۵. docker save ────────────────────────────────────────────────────── */

console.log("\n── saving images ──");
let saved = 0;
const MIN_PLAUSIBLE_TAR = 1024 * 1024; // 1 MB

for (const img of IMAGES) {
  const ref = tag(img.name);
  if (!imageExists(ref)) {
    console.error(`✗ ایمیج ساخته/تگ نشد: ${ref}${SKIP_BUILD ? " (با --skip-build باید از قبل موجود باشد)" : ""}`);
    process.exit(1);
  }
  const file = join(IMAGES_DIR, `${img.name}.tar`);
  console.log(`  ${img.name}.tar`);
  run("docker", ["save", "-o", file, ref], { stdio: "ignore" });

  // ⚠️ `docker save` در snapshotterِ containerd روی بعضی ایمیج‌ها با کدِ خروجی
  // **صفر** شکست می‌خورد و یک tarِ خالی می‌نویسد (ایمیجی که با digest کشیده
  // شده، `no suitable export target`). بدون این بررسی، بسته با یک ایمیجِ خالی
  // ساخته می‌شد و کاربر تازه موقعِ نصب می‌فهمید.
  const size = statSync(file).size;
  if (size < MIN_PLAUSIBLE_TAR) {
    console.error(`✗ ${img.name}.tar is only ${size} bytes - docker save produced an empty archive`);
    console.error(`  source: ${img.source ?? ref}`);
    console.error("  یک ایمیجِ کشیده‌شدهٔ digest را نمی‌توان export کرد؛ منبعِ برچسب‌دار انتخاب کنید.");
    process.exit(1);
  }
  saved += size;
}
console.log(`  total: ${human(saved)}`);

console.log(`\n✔ offline bundle written to ${OUT}`);
console.log(`  api:      http://localhost:${process.env.PISHDAD_WEB_PORT ?? 8080}/install`);
console.log(`  frontend: http://localhost:3100`);
console.log(`  next:     zip ${OUT} for the release asset, then verify with scripts/verify-offline-bundle.mjs`);
