import { strict as assert } from "node:assert";
import { createHash } from "node:crypto";
import { existsSync, readFileSync, readdirSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { test } from "node:test";
import { fileURLToPath } from "node:url";

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(HERE, "..", "..", "..", "..");

const FRONTEND = join(REPO, "pishdad-core", "frontend", "public", "images", "content");
const BACKEND = join(REPO, "pishdad-core", "backend", "database", "seeders", "assets", "content");

const NAMES = ["architecture.svg", "publish-flow.svg", "rtl.svg", "security.svg"];

const md5 = (buf: Buffer) => createHash("md5").update(buf).digest("hex");

/**
 * تصویرهای محتوا دو نسخه دارند: یکی برای سروِ مستقیم در فرانت و یکی
 * canonical برای `PishdadSiteSeeder`. هر دو از یک تابع ساخته می‌شوند، ولی
 * هیچ چیزی این را تضمین نمی‌کند که کسی بعداً یکی را دستی ویرایش نکند.
 *
 * اگر یکی جدا بیفتد، نصبِ تازه با تصویرهایی بالا می‌آید که با سایتِ
 * زنده فرق دارند — و هیچ تستِ بک‌اندی این را نمی‌بیند، چون کانتینر بک‌اند
 * اصلاً `frontend/` را ندارد. پس بررسی اینجا، در تنها جایی که هر دو مسیر
 * دیده می‌شوند، انجام می‌شود.
 */
test("both content-image copies exist and are byte-identical", () => {
  for (const name of NAMES) {
    const f = join(FRONTEND, name);
    const b = join(BACKEND, name);

    assert.ok(existsSync(f), `نسخهٔ فرانت گم است: ${name} — generator را اجرا کن`);
    assert.ok(existsSync(b), `نسخهٔ canonical بک‌اند گم است: ${name} — generator را اجرا کن`);

    const fb = readFileSync(f);
    const bb = readFileSync(b);

    assert.equal(
      md5(fb),
      md5(bb),
      `دو نسخهٔ ${name} یکی نیستند — node scripts/build-content-images.mjs را اجرا کن`,
    );
  }
});

/**
 * نمودارها باید خودبسنده باشند: با `<img>` بارگذاری می‌شوند و یک سندِ
 * جداگانه‌اند، پس نه `var(--…)` به آن‌ها می‌رسد و نه `currentColor`.
 * آزموده شد که با `color:#f00` روی `<img>` رنگِ واقعی سیاه بود.
 *
 * پس رنگِ ثابتِ خودشان را دارند — و اگر روزی به `currentColor` برگردند،
 * متن در تم تیره نامرئی می‌شود. این تست جلوی همان بازگشت را می‌گیرد.
 */
test("diagrams are self-contained and never rely on inherited colour", () => {
  for (const name of NAMES) {
    const src = readFileSync(join(FRONTEND, name), "utf8");

    assert.ok(
      !/currentColor/.test(src),
      `${name} از currentColor استفاده می‌کند، ولی داخل <img> ارث نمی‌برد و متن سیاه می‌شود`,
    );
    assert.ok(
      !/var\(--/.test(src),
      `${name} از متغیر CSS استفاده می‌کند، ولی متغیرها به سندِ SVG در <img> عبور نمی‌کنند`,
    );
    assert.ok(
      !/fill="#ffffff"/.test(src),
      `${name} متنِ سفیدِ ثابت دارد؛ روی جعبهٔ روشن نامرئی می‌شود`,
    );
    assert.match(src, /<svg[^>]+direction="rtl"/, `${name} جهت RTL را روی ریشه ندارد`);
  }
});

/**
 * چون دیاگرام‌ها خودبسنده‌اند، باید پس‌زمینهٔ ماتِ خودشان را داشته باشند،
 * وگرنه روی تم تیره «لکهٔ روشن» می‌سازند یا متن گم می‌شود.
 */
test("diagrams carry an opaque panel fill of their own", () => {
  for (const name of NAMES) {
    const src = readFileSync(join(FRONTEND, name), "utf8");

    assert.match(
      src,
      /\.box\s*\{\s*fill:\s*#[0-9a-f]{3,8}/i,
      `${name} جعبه‌ها را پر نمی‌کند — روی پس‌زمینهٔ صفحه معلق می‌ماند`,
    );
    assert.match(src, /text\s*\{[^}]*fill:\s*#[0-9a-f]{3,8}/i, `${name} رنگِ متن ندارد`);
  }
});

test("no stray files in the content-image directories", () => {
  for (const dir of [FRONTEND, BACKEND]) {
    const strays = readdirSync(dir).filter((f) => f.endsWith(".html") || f.endsWith(".txt"));

    assert.deepEqual(
      strays,
      [],
      `فایل‌های موقت در ${dir} جا مانده: ${strays.join(", ")}`,
    );
  }
});