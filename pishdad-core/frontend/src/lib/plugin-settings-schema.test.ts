import test from "node:test";
import assert from "node:assert/strict";
import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * K6.7 — `admin.settings_schema` مسیر زنده ندارد، و این فایل دلیلش را قفل می‌کند.
 *
 * پرسش: «SchemaForm از قبل هست، پس فرم تنظیمات را بساز.» جواب منفی است، و این
 * تست دلیلش را نگه می‌دارد تا کسی دوباره همان فرم بی‌مصرف را نسازد.
 *
 * سه چیز جدا باید **همزمان** درست باشند تا این نقطه زنده شود. وضعیت امروز:
 * اول و دوم حل شدند، سوم هنوز نه.
 *
 *  1. **قرارداد** — حل شد، ولی به راه دیگری. `admin.settings_schema` میکرو-اسکیمای
 *     خالی داشت و `open_schema => false`، پس `validateDeclaration()` خطای
 *     `no_schema` می‌داد و هر اعلانی رد می‌شد. به‌جای پر کردن فیلدها (که تصمیم
 *     محصول است و نه این‌جا گرفته می‌شود)، نقطه `deprecated` شد با جایگزین صریح
 *     و بدون مثال — چون مثال قبلی خودش توسط validator رد می‌شد.
 *  2. **مصرف‌کننده** — حل شد. `ManifestRegistry::pluginSettingsSchemas()` کانال را
 *     به کلید سطح‌بالای مانیفست برد، درست مثل `widgets` و `page_types`. دلیلش
 *     ساختاری است: میکرو-اسکیما سقف عمق ۱ دارد و اسکیمای JSON تودرتو است.
 *  3. **محل سوار شدن** — حل شد (K6.7). `PluginSettingsSection` در
 *     `/admin/settings` سوار شده، `GET /v1/admin/plugins/settings` اسکیما و مقادیر
 *     را می‌دهد و `PUT /v1/admin/plugins/settings/{slug}` ذخیره می‌کند. اعتبارسنجی
 *     ورودی **سمت سرور** است (`PluginSettingsValidator`): کلید اعلام‌نشده رد می‌شود،
 *     نوع دقیق سنجیده می‌شود، و `enum`/`minimum`/`maximum` اجبار می‌شوند. یعنی
 *     فرم هرچه می‌خواهد می‌فرستد و سرور تعیین تکلیف می‌کند.
 *
 * ⚠️ دو نگهبانِ این فایل عمداً برداشته و با تست رفتار جایگزین شدند: «صفحهٔ
 * تنظیمات هیچ دادهٔ پلاگینی نمی‌خواند» و «`SchemaForm` در `SettingsClient`
 * نیست». هر دو دیگر **غلط** شده بودند، نه فقط کهنه — ولی عبارت‌های مردهٔ
 * «اسلات پلاگین»/«فقط‌نمایشی» که همان پچ جعلی را ساخته بودند هنوز باید غایب بمانند.
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
const PLUGIN_SECTION_PATH = join(SRC_ROOT, "components/admin/PluginSettingsSection.tsx");

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

test("قرارداد: admin.settings_schema بازنشسته شد و مثال نامعتبر ندارد", () => {
  const contract = readFileSync(CONTRACT_PATH, "utf8");

  // `[\s\S]` به‌جای پرچم `s` — هدف tsc این پروژه es2018 نیست.
  const point = /'admin\.settings_schema'\s*=>\s*\[([\s\S]*?)\n {8}\],/.exec(contract);
  assert.ok(point, "نقطهٔ admin.settings_schema باید در قرارداد تعریف شده باشد.");
  const body = point[1];

  // این نقطه از `declared_only` به `deprecated` رفت: میکرو-اسکیما با
  // `max_depth => 1` نمی‌تواند اسکیمای JSON تودرتو را حمل کند، پس هیچ مانیفستی
  // قانوناً نمی‌توانست آن را پر کند. جایگزینش کلید سطح‌بالای `settings` است.
  assert.match(body, /'status'\s*=>\s*'deprecated'/, "وضعیت باید deprecated باشد.");
  assert.match(
    body,
    /'replaced_by'\s*=>\s*'manifest\.settings'/,
    "نقطهٔ بازنشسته باید بگوید جایگزینش چیست، وگرنه نویسنده بی‌راه می‌ماند.",
  );
  assert.match(body, /'openness'\s*=>\s*'schema_defined'/);
  assert.match(
    body,
    /'schema'\s*=>\s*\[\s*'fields'\s*=>\s*\[\]/,
    "فهرست فیلدها خالی می‌ماند — تا وقتی پر نشده، هر اعلانی خطای no_schema می‌گیرد.",
  );
  assert.match(body, /'open_schema'\s*=>\s*false/, "بدون فهرست فیلد، واژگان باز هم مجاز نیست.");

  // K6.7 — مثال «معتبر» قبلی خودش توسط validator رد می‌شد. نمایش مثالی که سیستم
  // خودش ردش می‌کند بدتر از نبود مثال است.
  const exampleOk = /'example_ok'\s*=>\s*(\[[^\]]*\])/.exec(body);
  assert.ok(exampleOk, "example_ok باید صریح باشد.");
  assert.equal(
    exampleOk[1].replace(/\s/g, ""),
    "[]",
    "نقطه‌ای که اسکیما ندارد نباید مثال معتبر داشته باشد.",
  );
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

test("مصرف‌کننده حالا وجود دارد: رجیستری کلید سطح‌بالای settings را می‌خواند", () => {
  const registry = liveCode(readFileSync(REGISTRY_PATH, "utf8"));

  for (const reader of ["permissionModules", "widgetSchemas", "pageTypes", "pluginSettingsSchemas"]) {
    assert.match(registry, new RegExp(`function ${reader}\\b`), `${reader} باید مصرف‌کنندهٔ زنده باشد.`);
  }

  // K6.7 — `pluginSettingsSchemas()` اضافه شد و کانال را از
  // `panel.extensions` به کلید سطح‌بالای مانیفست برد، درست مثل `widgets` و
  // `page_types`. دلیلش ساختاری است: میکرو-اسکیمای نقطه‌ها سقف عمق ۱ دارد و
  // اسکیمای JSON شیء تودرتو است، پس جا دادنش آنجا یعنی بالا بردن سقف عمق برای
  // همهٔ نقاط.
  assert.match(
    registry,
    /\$manifest\['settings'\]\s*\?\?\s*null/,
    "کانال زندهٔ فرم تنظیمات باید کلید سطح‌بالای manifest.settings باشد.",
  );

  // نقطهٔ قراردادِ بازنشسته نباید دوباره به رجیستری برگردد: از راه micro-schema
  // قابل اعلام نبود و حالا جایگزین صریح دارد.
  assert.equal(
    registry.includes("settings_schema"),
    false,
    "کد زندهٔ رجیستری نباید به admin.settings_schema اشاره کند — مسیر زنده‌اش manifest.settings است.",
  );

  // کانال‌های واقعیِ schema در این کدبیس `panel.extensions` **نیست**: ویجت‌ها،
  // نوع‌های صفحه و فرم‌های تنظیمات، JSON Schema را در کلیدِ top-level مانیفست
  // می‌گذارند و از آنجا merge می‌شوند.
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

test("صفحهٔ تنظیمات هر دو منبع را می‌خواند: هسته و افزونه‌ها", () => {
  const page = liveCode(readFileSync(SETTINGS_PAGE_PATH, "utf8"));
  const client = liveCode(readFileSync(SETTINGS_CLIENT_PATH, "utf8"));

  assert.match(page, /\/v1\/admin\/settings\/site/, "منبع دادهٔ هسته باید بماند.");
  assert.match(
    client,
    /PluginSettingsSection/,
    "بخش افزونه‌ها باید در صفحه سوار شده باشد — تا پیش از این هیچ محل سواری نبود.",
  );
});

test("کارت «اسلات پلاگین» هنوز حذف شده است", () => {
  const client = liveCode(readFileSync(SETTINGS_CLIENT_PATH, "utf8"));

  // عبارت‌هایی که **نباید** برگردند. رندر واقعی در کامپوننت جدید است، نه در
  // خود صفحه — پس این‌ها همچنان باید غایب بمانند.
  for (const dead of ["اسلات پلاگین", "فقط‌نمایشی", "نمونه", "settings_schema"]) {
    assert.equal(
      client.includes(dead),
      false,
      `عبارت مرده در صفحهٔ تنظیمات مانده است: ${dead}`,
    );
  }
});

test("بخش تنظیمات افزونه‌ها به هر دو مسیر وصل است", () => {
  const section = liveCode(readFileSync(PLUGIN_SECTION_PATH, "utf8"));

  assert.match(section, /\/v1\/admin\/plugins\/settings/, "باید اسکیما و مقادیر را بخواند.");
  assert.match(section, /method:\s*"PUT"/, "باید مسیر ذخیره داشته باشد.");
  // قاعدهٔ ضد React #301: بارگذاری داده در `useEffect`، نه شرط در بدنهٔ رندر.
  assert.match(section, /useEffect\(/, "بارگذاری باید در useEffect باشد.");
  assert.equal(
    /if\s*\(\s*!\s*\w+\s*\)\s*void\s+\w+\(/.test(section),
    false,
    "شرطِ فراخوانی در بدنهٔ رندر یعنی React #301.",
  );
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
