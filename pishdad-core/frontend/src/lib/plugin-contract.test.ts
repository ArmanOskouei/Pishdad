/**
 * I2 — نگهبانِ `parsePluginContract`.
 *
 * این تست دربارهٔ *رفتار* است، نه شکلِ تایپ‌ها: تایپ‌ها `tsc` می‌سنجد. چیزی که
 * تایپ‌ها **نمی‌گیرند** این است که `authed<T>()` یک assertion بی‌صدا می‌کند و
 * راهنما با یک بدنهٔ ناقص هم build می‌شود و هم سبز به نظر می‌رسد. این تست‌ها
 * همان سوراخ را می‌بندند.
 *
 * نکتهٔ محوری: `missing` باید **دقیق** باشد. اگر همه‌چیز را در یک
 * «قرارداد ناقص است» بریزیم، UI دیگر نمی‌تواند بگوید کدام کلید نبوده — و نگهبانی
 * که همیشه یکسان جواب می‌دهد، بعد از دو هفته خاموش می‌شود.
 */

import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

import { parsePluginContract, PLUGIN_CONTRACT_PATH } from "./plugin-contract.ts";

/** متنِ یک فایل بک‌اند — همان ترفندی که `widget-type.test.ts` می‌زند. */
const backendFile = (rel: string): string =>
  readFileSync(fileURLToPath(new URL(`../../../backend/${rel}`, import.meta.url)), "utf8");

/** کامنت‌ها را می‌برد تا ادعاهای تست روی *کد* باشد، نه روی متنِ توضیح. */
const stripComments = (source: string): string =>
  source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\/\/[^\n]*/g, "");

/** یک بدنهٔ کامل و سالم — مبنای «چیزی نباید تغییر کند». */
function goodPayload(): Record<string, unknown> {
  return {
    core_contract_version: "1.6.0",
    declaration_meta_fields: ["since", "schema_version"],
    forbidden: ["eval", "class"],
    reserved_type_names: ["core", "system"],
    overridable_interfaces: ["App\\Services\\Billing\\PaymentGatewayInterface"],
    deferred_extension_points: { "admin.widget": "تعویق است." },
    package: {
      manifest_name: "manifest.json",
      backend_root: "Laravel",
      frontend_root: "Next.js",
      plugin_namespace_prefix: "Pishdad\\Plugins\\",
    },
    limits: {
      max_zip_bytes: 20971520,
      max_files: 5000,
      max_uncompressed_bytes: 209715200,
      max_compression_ratio: 100,
    },
    grammar: { types: ["string", "enum"], limits: { max_depth: 1 } },
    allowed_paths: [{ path: "manifest.json", label_fa: "مانیفست" }],
    extension_points: [
      {
        key: "admin.menu",
        label_fa: "آیتم منو",
        desc_fa: "یک آیتم در منوی ادمین.",
        status: "declared_only",
        openness: "closed",
        openness_why: "چون فیلدها بسته‌اند.",
        since: "1.2.0",
        schema_version: 1,
        open_schema: false,
        schema: {
          fields: { key: { type: "string", required: true, maxLength: 40 } },
          cross: ["is_path(href)"],
        },
        max: { declarations: 10, bytes: 4096, depth: 1, properties: 30 },
        example_ok: [{ key: "orders", label: "سفارش‌ها" }],
        example_bad: [{ why: "کلید رزرو.", decl: { key: "core" } }],
        forbidden: ["eval", "class"],
      },
    ],
    // ECO3 — قرارداد توسعه‌دهنده.
    error_code_groups: {
      zip: {
        label_fa: "بسته",
        label_en: "Archive",
        remedy_fa: "دوباره بساز.",
        remedy_en: "Rebuild it.",
      },
    },
    error_codes: { "zip.unreadable": "zip" },
    api_examples: [
      {
        id: "validate",
        title_fa: "اعتبارسنجی",
        title_en: "Validate",
        method: "POST",
        path: "/v1/admin/plugins/validate",
        request: { file: "my-plugin.zip" },
        response: { analysis: { ok: true } },
        note_fa: "همیشه ۲۰۰.",
        note_en: "Always 200.",
      },
    ],
    plugin_docs: [
      {
        slug: "blog",
        name: "Blog",
        version: "1.0.0",
        entries: [
          {
            title_fa: "فهرست نوشته‌ها",
            title_en: "List posts",
            method: "GET",
            path: "/v1/p/blog/posts",
            description_fa: "فهرست.",
            description_en: "A list.",
            auth: "manager",
          },
        ],
      },
    ],
  };
}

test("یک بدنهٔ کامل، همان‌طور که هست برمی‌گردد", () => {
  const parsed = parsePluginContract(goodPayload());
  assert.equal(parsed.ok, true);
  if (!parsed.ok) return;

  assert.equal(parsed.value.core_contract_version, "1.6.0");
  assert.equal(parsed.value.limits.max_zip_bytes, 20971520);
  assert.equal(parsed.value.package.manifest_name, "manifest.json");
  assert.equal(parsed.value.extension_points.length, 1);
  assert.equal(parsed.value.extension_points[0].schema.fields.key.maxLength, 40);
  // فیلدهای اختیاری نباید با مقدار ساختگی پر شوند.
  assert.equal("minLength" in parsed.value.extension_points[0].schema.fields.key, false);
  // ECO3 — کاتالوگ خطا و مستندات افزونه از قرارداد می‌آیند.
  assert.equal(parsed.value.error_codes["zip.unreadable"], "zip");
  assert.equal(parsed.value.error_code_groups.zip.remedy_en, "Rebuild it.");
  assert.equal(parsed.value.api_examples[0].method, "POST");
  assert.equal(parsed.value.plugin_docs[0].entries[0].path, "/v1/p/blog/posts");
});

test("بدنهٔ غیرobject، ناقص گزارش می‌شود — نه cast بی‌صدا", () => {
  for (const bad of [null, undefined, 42, "contract", []]) {
    const parsed = parsePluginContract(bad);
    assert.equal(parsed.ok, false, `بدنهٔ ${JSON.stringify(bad)} نباید پذیرفته شود.`);
    if (parsed.ok) return;
    assert.deepEqual(parsed.missing, ["data"]);
  }
});

/**
 * ⭐ نگهبانِ رگرسیون — باگِ واقعیِ ECO3.
 *
 * بک‌اند پاسخِ تک‌نمونه را در `{ data: … }` می‌پیچد. صفحهٔ «مستندات
 * توسعه‌دهندگان» اشتباهاً `serverApi` را صدا زد (که عمداً unwrap نمی‌کند) و
 * پوشش را مستقیم به پارسر داد؛ نتیجه این بود که **هر ۲۵ کلید** «missing»
 * شدند و کلِ صفحه به یک پیام خطا تبدیل شد — در حالی که ردیفِ ECO3 `done` بود.
 * این تست همان مسیر را قفل می‌کند تا دوباره بی‌صدا خراب نشود.
 */
test("پوشش {data} از API باز می‌شود — نه اینکه کلیدها گم شوند", () => {
  const parsed = parsePluginContract({ data: goodPayload() });
  assert.equal(parsed.ok, true, "پوشش {data} باید به خودِ قرارداد باز شود.");
  if (!parsed.ok) return;
  assert.equal(parsed.value.core_contract_version, "1.6.0");
  assert.equal(parsed.value.plugin_docs[0].entries[0].path, "/v1/p/blog/posts");
});

test("پوشش {data} که خودش قرارداد نیست، باز هم کمبود را نام می‌برد", () => {
  const parsed = parsePluginContract({ data: 42 });
  assert.equal(parsed.ok, false);
  if (parsed.ok) return;
  assert.ok(parsed.missing.includes("package"), "بدنهٔ بی‌ربط نباید «کامل» شمرده شود.");
});

/**
 * ⭐ نگهبانِ رگرسیون — تلهٔ PHP/JSON.
 *
 * قراردادِ بک‌اند برای چند نقطه `'schema' => ['fields' => []]` دارد و
 * `json_encode([])` در PHP `[]` می‌دهد نه `{}`. قبلاً همین باعث می‌شد آن نقاط
 * «کمبود» اعلام شوند و کلِ صفحهٔ مستندات به یک پیام خطا تبدیل شود.
 * آرایهٔ خالی و شیءِ خالی در معنای «هیچ فیلدی» یکی‌اند.
 */
test("fields: [] از PHP هم‌معنای «هیچ فیلدی» است — نه کمبود", () => {
  const payload = goodPayload();
  (payload.extension_points as Record<string, unknown>[])[0].schema = { fields: [], cross: [] };
  const parsed = parsePluginContract(payload);
  assert.equal(parsed.ok, true, "آرایهٔ خالی نباید schema.fields را «کمبود» کند.");
  if (!parsed.ok) return;
  assert.deepEqual(parsed.value.extension_points[0].schema.fields, {});
});

test("mapِ خالی به‌شکل آرایه هم پذیرفته می‌شود (strMap)", () => {
  const payload = goodPayload();
  payload.deferred_extension_points = [];
  const parsed = parsePluginContract(payload);
  assert.equal(parsed.ok, true, "mapِ خالی نباید «کمبود» شود.");
  if (!parsed.ok) return;
  assert.deepEqual(parsed.value.deferred_extension_points, {});
});

test("کمبودِ هر زیرمجموعه با مسیرش گزارش می‌شود", () => {
  for (const key of ["package", "limits", "grammar"] as const) {
    const payload = goodPayload();
    delete payload[key];
    const parsed = parsePluginContract(payload);
    assert.equal(parsed.ok, false, `بدون ${key} نباید «کامل» شمرده شود.`);
    if (parsed.ok) return;
    assert.ok(parsed.missing.includes(key), `مسیر «${key}» در missing نیست.`);
  }
});

test("کمبودِ یک سقف، همان کلید را نام می‌برد و بقیه را رد می‌کند", () => {
  const payload = goodPayload();
  (payload.limits as Record<string, unknown>).max_zip_bytes = undefined;
  const parsed = parsePluginContract(payload);
  assert.equal(parsed.ok, false);
  if (parsed.ok) return;
  // صفرِ ساختگی در یک راهنما یعنی «سقفِ صفر» — دروغ. پس باید نام بیاید.
  assert.ok(parsed.missing.includes("limits.max_zip_bytes"));
});

test("کمبودِ فیلدهای یک نقطه با اندیس گزارش می‌شود", () => {
  const payload = goodPayload();
  (payload.extension_points as Record<string, unknown>[])[0].open_schema = undefined;
  const parsed = parsePluginContract(payload);
  assert.equal(parsed.ok, false);
  if (parsed.ok) return;
  assert.ok(parsed.missing.includes("extension_points[0].open_schema"));
});

test("تکرارِ یک مسیر، در فهرستِ کمبودها دوباره نمی‌آید", () => {
  const payload = goodPayload();
  (payload.extension_points as Record<string, unknown>[])[0].key = "";
  (payload.extension_points as Record<string, unknown>[])[0].status = 1;
  const parsed = parsePluginContract(payload);
  assert.equal(parsed.ok, false);
  if (parsed.ok) return;
  assert.equal(new Set(parsed.missing).size, parsed.missing.length);
});

test("`example_ok` خالی خطا نیست — نقطهٔ بدون مثال مجاز است", () => {
  // I3 در بک‌اند پین کرده که کلید `example_ok` همیشه هست و می‌تواند خالی باشد؛
  // راهنما باید «هنوز نوشته نشده» بگوید، نه اینکه قرارداد را ناقص اعلام کند.
  const payload = goodPayload();
  (payload.extension_points as Record<string, unknown>[])[0].example_ok = [];
  (payload.extension_points as Record<string, unknown>[])[0].example_bad = [];
  const parsed = parsePluginContract(payload);
  assert.equal(parsed.ok, true);
});

/**
 * ⭐ نگهبانِ هم‌خوانی با بک‌اند.
 *
 * نامِ کلیدهایی که UI لازم دارد در `contractPayload()` بک‌اند نوشته شده. اگر آن
 * را جابه‌جا کنند، پارسر اینجا ساکت می‌ماند و راهنما «کمبود» می‌شود — یا بدتر،
 * با صفرِ ساختگی رندر می‌شود. پس سوراخ را همین‌جا می‌بندیم.
 */
test("نامِ کلیدهایی که پارسر می‌خواند، در endpoint بک‌اند هم هست", () => {
  const php = stripComments(backendFile("app/Http/Controllers/Api/V1/Admin/PluginController.php"));
  const payload = php.slice(php.indexOf("private function contractPayload"));

  // مسیر endpoint در فایل route ثبت است، نه در کنترلر.
  const routes = stripComments(backendFile("routes/api.php"));
  assert.ok(
    routes.includes("plugins/contract"),
    "مسیر plugins/contract در routes/api.php نیست — پارسرِ ما هرگز چیزی نمی‌گیرد.",
  );

  const required = [
    "core_contract_version",
    "declaration_meta_fields",
    "forbidden",
    "reserved_type_names",
    "overridable_interfaces",
    "deferred_extension_points",
    "allowed_paths",
    "extension_points",
    "package",
    "limits",
    "grammar",
    // ECO3 — قرارداد توسعه‌دهنده.
    "error_code_groups",
    "error_codes",
    "api_examples",
    "plugin_docs",
  ];

  for (const key of required) {
    assert.ok(
      payload.includes(`'${key}'`),
      `کلید «${key}» در contractPayload بک‌اند نیست ولی UI آن را می‌خواند.`,
    );
  }

  // و زیرمجموعه‌هایی که راهنمای ZIP به آن‌ها گره خورده — نبودشان یعنی راهنما
  // دربارهٔ سقف‌ها و نام مانیفست ساکت می‌ماند.
  for (const key of [
    "manifest_name",
    "backend_root",
    "frontend_root",
    "plugin_namespace_prefix",
    "max_zip_bytes",
    "max_files",
    "max_uncompressed_bytes",
    "max_compression_ratio",
  ]) {
    assert.ok(payload.includes(`'${key}'`), `سقف/بستهٔ «${key}» در contractPayload نیست.`);
  }
});

test("مسیر endpoint، یکی و تنها است", () => {
  // اگر روزی مسیر عوض شود و پارسر همین بماند، `authed` به یک ۴۰۴ می‌خورد و
  // کاربر فقط یک پیامِ مبهم می‌بیند.
  assert.equal(PLUGIN_CONTRACT_PATH, "/v1/admin/plugins/contract");
});
