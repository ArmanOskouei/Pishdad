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
 */

import { execFileSync } from "node:child_process";
import { cpSync, existsSync, mkdirSync, readFileSync, rmSync, statSync, writeFileSync, readdirSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, "..");
const OUT = join(ROOT, "dist-offline");

const arg = (name, fallback = null) => {
  const i = process.argv.indexOf(name);
  return i > -1 ? process.argv[i + 1] : fallback;
};
const has = (name) => process.argv.includes(name);

const VERSION = arg("--version") ?? "1.0.0";
const SKIP_BUILD = has("--skip-build");
const LEAN = join(ROOT, "dist-pishdad");
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
const IMAGES = [
  { name: "backend-app", from: "backend-build" },
  { name: "backend-db", from: "backend-db-build" },
  { name: "frontend", from: "frontend-build" },
  { name: "nginx", from: "cache", source: "nginx:1.31.6-alpine" },
  { name: "redis", from: "cache", source: "redis:8.10.2-alpine" },
  {
    name: "minio",
    from: "cache",
    source: "quay.io/minio/minio@sha256:14cea493d9a34af32f524e538b8346cf79f3321eff8e708c1e2960462bd8936e",
  },
  { name: "minio-mc", from: "cache", source: "quay.io/minio/mc:RELEASE.2025-08-13T08-35-41Z" },
];

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

  console.log("  backend app...");
  run("docker", ["compose", "-f", "docker-compose.yml", "build", "app"], { cwd: BACKEND });
  run("docker", ["tag", "cms-core-backend-app:latest", tag("backend-app")]);

  console.log("  backend db...");
  run("docker", ["compose", "-f", "docker-compose.yml", "build", "db"], { cwd: BACKEND });
  run("docker", ["tag", "cms-core-backend-db:latest", tag("backend-db")]);

  console.log("  frontend (standalone build, this one is the slow one)...");
  run("docker", ["build", "-t", tag("frontend"), "."], { cwd: FRONTEND });
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

console.log("\n── backend vendor/ (into a container volume, then tarred out) ──");
const VENDOR_VOLUME = "pishdad-offline-vendor";
const VENDOR_TAR = "vendor.tar.gz";

run("docker", ["volume", "rm", "-f", VENDOR_VOLUME], { stdio: "ignore" });
run("docker", ["volume", "create", VENDOR_VOLUME], { stdio: "ignore" });

const COMPOSER_IMAGE = "composer:2";
if (!imageExists(COMPOSER_IMAGE)) run("docker", ["pull", COMPOSER_IMAGE]);

run("docker", [
  "run", "--rm",
  "-v", `${BACKEND}:/app:ro`,
  "-v", `${VENDOR_VOLUME}:/vendor`,
  "-w", "/app",
  // COMPOSER_VENDOR_DIR به‌جای `composer config vendor-dir` که composer.json را
  // دست می‌زد — سورس باید دست‌نخورده بماند.
  "-e", "COMPOSER_VENDOR_DIR=/vendor",
  // بسته‌های بزرگ روی بعضی شبکه‌ها از ۳۰۰ ثانیه می‌گذرند؛ پیش‌فرض را بالا می‌بریم.
  "-e", "COMPOSER_PROCESS_TIMEOUT=1800",
  COMPOSER_IMAGE,
  "sh", "-lc",
  // `--no-dev`: بستهٔ منتشرشده محیطِ production است و phpunit/pint هیچ‌وقت روی
  // ماشینِ کاربر اجرا نمی‌شوند.
  //
  // `--no-scripts` عمدی است: اسکریپتِ `post-autoload-dump` لاراول
  // (`artisan package:discover`) در `storage/logs/` و `bootstrap/cache/`
  // می‌نویسد — و سورس اینجا read-only است تا درختِ کاری آلوده نشود. لاراول در
  // اولین بوت، خودش این کش را می‌سازد (`PackageManifest::build()` وقتی فایل
  // کش نباشد صدا زده می‌شود)، پس چیزی از دست نمی‌رود.
  "composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader",
]);

mkdirSync(join(OUT, "offline"), { recursive: true });
run("docker", [
  "run", "--rm",
  "-v", `${VENDOR_VOLUME}:/vendor:ro`,
  "-v", `${join(OUT, "offline")}:/out`,
  "alpine:3.24",
  "tar", "-czf", `/out/${VENDOR_TAR}`, "-C", "/vendor", ".",
]);
run("docker", ["volume", "rm", "-f", VENDOR_VOLUME], { stdio: "ignore" });

/* ── ۳. بستهٔ سبک + اسکریپت‌ها ─────────────────────────────────────────── */

console.log("\n── lean package + installers ──");
for (const entry of readdirSync(LEAN)) {
  cpSync(join(LEAN, entry), join(OUT, entry), { recursive: true });
}
// vendor به‌صورت tar در `offline/` می‌رود (نه به‌عنوان پوشه زیر cms-core).
// اسکریپتِ نصب بازش می‌کند؛ دلیلش در بخشِ ۲ توضیح داده شده.
if (!existsSync(join(OUT, "offline", VENDOR_TAR))) {
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
name: \${PISHDAD_PROJECT:-cms-core-backend-offline}

services:
  app:
    image: ${tag("backend-app")}
    container_name: \${PISHDAD_APP:-cms-app}
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

  web:
    image: ${tag("nginx")}
    container_name: \${PISHDAD_WEB:-cms-web}
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
    container_name: \${PISHDAD_DB:-cms-db}
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
    container_name: \${PISHDAD_REDIS:-cms-redis}
    restart: unless-stopped
    pull_policy: never
    ports:
      - "\${PISHDAD_REDIS_PORT:-6380}:6379"
    volumes:
      - redisdata:/data

  minio:
    image: ${tag("minio")}
    container_name: \${PISHDAD_MINIO:-cms-minio}
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
    container_name: \${PISHDAD_MINIO_INIT:-cms-minio-init}
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
name: \${PISHDAD_PROJECT:-cms-core-frontend-offline}

services:
  web-front:
    image: ${tag("frontend")}
    container_name: \${PISHDAD_FRONT:-cms-web-front}
    restart: unless-stopped
    pull_policy: never
    ports:
      - "\${PISHDAD_FRONTEND_PORT:-3100}:3000"
    environment:
      INTERNAL_API_URL: \${INTERNAL_API_URL:-http://host.docker.internal:8080/api}
    extra_hosts:
      - "host.docker.internal:host-gateway"
`;

writeFileSync(join(OUT, "cms-core", "backend", "docker-compose.offline.yml"), backendOffline);
writeFileSync(join(OUT, "cms-core", "frontend", "docker-compose.offline.yml"), frontendOffline);

const imageList = IMAGES.map((i) => i.name);
writeFileSync(
  join(OUT, "offline", "IMAGES.txt"),
  imageList.map((n) => `${n}.tar  ->  ${tag(n)}`).join("\n") + "\n",
);

/* ── ۵. docker save ────────────────────────────────────────────────────── */

console.log("\n── saving images ──");
let saved = 0;
for (const img of IMAGES) {
  const ref = tag(img.name);
  if (!imageExists(ref)) {
    console.error(`✗ ایمیج ساخته/تگ نشد: ${ref}${SKIP_BUILD ? " (با --skip-build باید از قبل موجود باشد)" : ""}`);
    process.exit(1);
  }
  const file = join(IMAGES_DIR, `${img.name}.tar`);
  console.log(`  ${img.name}.tar`);
  run("docker", ["save", "-o", file, ref], { stdio: "ignore" });
  saved += statSync(file).size;
}
console.log(`  total: ${human(saved)}`);

console.log(`\n✔ offline bundle written to ${OUT}`);
console.log(`  api:      http://localhost:${process.env.PISHDAD_WEB_PORT ?? 8080}/install`);
console.log(`  frontend: http://localhost:3100`);
console.log(`  next:     zip ${OUT} for the release asset, then verify with scripts/verify-offline-bundle.mjs`);
