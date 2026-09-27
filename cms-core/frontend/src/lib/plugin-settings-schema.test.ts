import test from "node:test";
import assert from "node:assert/strict";
import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * K6.7 — `admin.settings_schema` هنوز مسیر زنده ندارد.
 *
 * پرسش: «SchemaForm از قبل هست، پس فرم تنظیمات را بساز.» جواب منفی است، و این
 * تست دلیلش را قفل می‌کند تا کسی دوباره همان فرم بی‌مصرف را نسازد.
 *
 * سه چیز جدا باید **همزمان** درست باشند تا این نقطه زنده شود، و هیچ‌کدام امروز
 * نیست:
 *
 *  1. **قرارداد** — `PluginPackageContract` برای این نقطه
 *     `'schema' => ['fields' => []` دارد و `open_schema => false`. متد
 *     `validateDeclaration()` روی `fields === [] && open_schema === false`
 *     یک خطای `no_schema` برمی‌گرداند و **زود بازمی‌گردد** ⇒ هر اعلانی در
 *     `panel.extensions` رد می‌شود. یعنی هیچ پلاگینی امروز نمی‌تواند فرم
 *     تنظیمات اعلام کند.
 *  2. **مصرف‌کننده** — `ManifestRegistry` سه مصرف‌کننده دارد
 *     (`permissionModules`، `widgetSchemas`، `pageTypes`) و هیچ‌کدام
 *     `admin.settings_schema` را نمی‌خواند. endpoint و مسیر ذخیره هم نیست.
 *  3. **محل سوار شدن** — صفحهٔ `/admin/settings` چهارده `useState` دستی دارد
 *     و کارت «اسلات پلاگین»‌اش (سه input هاردکدِ `readOnly`) در K6.7 حذف شد.
 *
 * نتیجه: فرمی که امروز ساخته شود هیچ‌جا رندر نمی‌شود — همان دروغی که K6.10
 * کارت ویجت داشبورد را به‌خاطرش حذف کرد.
 *
 * ⚠️ اگر روزی نقطهٔ قرارداد `live` شد یا مصرف‌کننده‌ای اضافه شد، **این تست
 * عمداً می‌افتد**. آن‌وقت نگهبان را دستی بردارید و به‌جایش تست رفتار بنویسید.
 */

/** ریشهٔ `src/` — از این فایل یک سطح بالاتر (`src/lib` → `src`). */
const SRC_ROOT = fileURLToPath(new URL("../", import.meta.url));

const CONTRACT_PATH = fileURLToPath(
  new URL("../../../backend/app/Services/Plugins/PluginPackageContract.php", import.meta.url),
);

const VALIDATOR_PATH = fileURLToPath(
  new URL("../../../backend/app/Services/Plugins/PluginPackageValidator.php", import.meta.url),
);

const REGISTRY_PATH = fileURLToPath(
  new URL("../../../backend/app/Services/Plugins/ManifestRegistry.php", import.meta.url),
);

const SETTINGS_CLIENT_PATH = join(SRC_ROOT, "app/(client)/admin/settings/SettingsClient.tsx");
const SETTINGS_PAGE_PATH = join(SRC_ROOT, "app/(client)/admin/settings/page.tsx");
const SCHEMA_FORM_PATH = join(SRC_ROOT, "components/ui/SchemaForm.tsx");

/**
 * کامنت‌ها و یادداشت‌های فارسی بالای فایل‌ها اسمِ endpoint و متنِ کارتِ حذف‌شده
 * را می‌آورند تا دلیل را ثبت کنند. پس قبل از سنجش، کامنت‌ها را برمی‌داریم تا
 * نگهبان فقط *کد زنده* را بسنجد، نه یادداشتی دربارهٔ کد.
 */
function liveCode(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\/\/.*$/gm, "");
}

/** همهٔ فایل‌های متنی زیر یک پوشه، با نام نسبی. */
function walk(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) {
      walk(full, out);
    } else {
      out.push(full);
    }
  }

  return out;
}

test("قرارداد: admin.settings_schema هنوز هیچ فیلدی اعتبارسنجی نمی‌کند", () => {
  const contract = readFileSync(CONTRACT_PATH, "utf8");

  // `[\s\S]` به‌جای پرچم `s` — هدف tsc این پروژه es2018 نیست.
  const point = /'admin\.settings_schema'\s*=>\s*\[([\s\S]*?)\n {8}\],/.exec(contract);
  assert.ok(point, "نقطهٔ admin.settings_schema باید در قرارداد تعریف شده باشد.");
  const body = point[1];

  assert.match(body, /'status'\s*=>\s*'declared_only'/, "وضعیت باید declared_only بماند.");
  assert.match(body, /'openness'\s*=>\s*'schema_defined'/);
  assert.match(
    body,
    /'schema'\s*=>\s*\[\s*'fields'\s*=>\s*\[\]/,
    "فهرست فیلدها باید خالی بماند — تا وقتی پر نشده، هر اعلانی خطای no_schema می‌گیرد.",
  );
  assert.match(body, /'open_schema'\s*=>\s*false/, "بدون فهرست فیلد، واژگان باز هم مجاز نیست.");
});

test("قرارداد: همین «فیلد خالی» است که اعلام را رد می‌کند (نه چیز دیگر)", () => {
  const validator = liveCode(readFileSync(VALIDATOR_PATH, "utf8"));

  // نقطهٔ `deferred` زودتر bail می‌کند، ولی این نقطه `declared_only` است — پس
  // گیرندهٔ واقعی همان شرط `fields === [] && open_schema === false` است. اگر
  // روزی این شرط عوض شد، دلیلِ «هیچ پلاگینی نمی‌تواند اعلام کند» عوض شده است.
  assert.match(
    validator,
    /validateDeclaration\(\$key, \$decl\)/,
    "اعلان هر نقطه باید از validateDeclaration رد شود.",
  );

  const contract = readFileSync(CONTRACT_PATH, "utf8");
  assert.match(
    contract,
    /\$fields === \[\] && \$meta\['open_schema'\] === false/,
    "قاعدهٔ no_schema باید بماند: خالی بودن فهرست فیلد یعنی «هنوز نوشته نشده».",
  );
  assert.match(contract, /'no_schema'/, "خطای no_schema باید به نویسندهٔ پلاگین گفته شود.");
});

test("مصرف‌کننده‌ای وجود ندارد: رجیستری مانیفست تنظیمات پلاگین را نمی‌خواند", () => {
  const registry = readFileSync(REGISTRY_PATH, "utf8");

  for (const reader of ["permissionModules", "widgetSchemas", "pageTypes"]) {
    assert.match(registry, new RegExp(`function ${reader}\\b`), `${reader} باید مصرف‌کنندهٔ زنده باشد.`);
  }

  assert.equal(
    registry.includes("settings_schema"),
    false,
    "ManifestRegistry نباید به admin.settings_schema اشاره کند — تا وقتی مصرف‌کننده‌ای نیست، افزودنش یعنی وعدهٔ بی‌پشتوانه.",
  );

  // کانالِ واقعیِ schema در این کدبیس `panel.extensions` **نیست**: ویجت‌ها و
  // نوع‌های صفحه، JSON Schema را در کلیدِ top-level مانیفست می‌گذارند و از
  // آنجا merge می‌شوند. یعنی پلاگین امروز می‌تواند فرم تنظیمات بیاورد **بدون**
  // اینکه به نقطهٔ قرارداد نیاز داشته باشد ⇒ اولویت با کانالِ top-level است،
  // و نقطهٔ `admin.settings_schema` فعلاً چیزی به آن اضافه نمی‌کند.
  assert.match(
    registry,
    /\$manifest\['widgets'\]\s*\?\?\s*null/,
    "کانال واقعیِ schema ویجت باید manifest.widgets بماند.",
  );
  assert.match(
    registry,
    /\$manifest\['page_types'\]\s*\?\?\s*null/,
    "کانال واقعیِ schema نوع صفحه باید manifest.page_types بماند.",
  );
});

test("صفحهٔ تنظیمات هیچ دادهٔ پلاگینی نمی‌خواند", () => {
  const page = liveCode(readFileSync(SETTINGS_PAGE_PATH, "utf8"));

  assert.match(page, /\/v1\/admin\/settings\/site/, "تنها منبع دادهٔ این صفحه باید تنظیمات هسته باشد.");
  assert.equal(
    /settings\/site[\s\S]{0,200}(plugin|settings_schema)/.test(page),
    false,
    "صفحهٔ تنظیمات نباید دنبال دادهٔ پلاگین بگردد — هیچ endpointی برایش وجود ندارد.",
  );
});

test("کارت «اسلات پلاگین» از صفحهٔ تنظیمات حذف شده است", () => {
  const client = liveCode(readFileSync(SETTINGS_CLIENT_PATH, "utf8"));

  for (const dead of ["اسلات پلاگین", "فقط‌نمایشی", "نمونه", "SchemaForm", "settings_schema"]) {
    assert.equal(
      client.includes(dead),
      false,
      `عبارت مرده در صفحهٔ تنظیمات مانده است: ${dead}`,
    );
  }
});

test("SchemaForm هنوز فقط یک map تخت را می‌چرخاند", () => {
  const form = liveCode(readFileSync(SCHEMA_FORM_PATH, "utf8"));

  // ادعای `openness_why` در قرارداد («SchemaForm آماده است و فقط یک map تخت را
  // می‌چرخاند») فقط وقتی راست است که این سه شرط برقرار بمانند. اگر روزی فرم
  // تودرتو شد، ادعا عوض شده و ادامهٔ طراحی نقطهٔ قرارداد عوض می‌شود.
  assert.match(form, /schema\.properties/, "فرم باید از properties طرح‌واره بخواند.");
  assert.match(form, /Object\.entries\(props\)/, "فرم باید روی کلیدهای تخت حلقه بزند.");
  assert.equal(
    form.includes("<SchemaForm"),
    false,
    "SchemaForm نباید خودش را صدا بزند — تودرتویی یعنی renderer نیست.",
  );
  assert.equal(
    form.includes("p.properties"),
    false,
    "SchemaForm نباید properties تودرتو بخواند؛ گرامر micro-schema هم عمق ۱ است.",
  );
});

test("هیچ کد فرانتی به admin.settings_schema اشاره نمی‌کند", () => {
  const hits: string[] = [];
  for (const file of walk(SRC_ROOT)) {
    if (!file.endsWith(".ts") && !file.endsWith(".tsx")) continue;
    // خودِ این تست نگهبان است، نه مصرف‌کننده.
    if (file.endsWith(".test.ts")) continue;
    if (readFileSync(file, "utf8").includes("settings_schema")) {
      hits.push(file.slice(SRC_ROOT.length));
    }
  }

  assert.deepEqual(
    hits,
    [],
    `کد فرانتی به نقطهٔ قرارداد اشاره می‌کند، ولی هیچ مسیر زنده‌ای وجود ندارد: ${hits.join("، ")}`,
  );
});
