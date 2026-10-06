/**
 * I2 — قراردادِ بستهٔ پلاگین، از راه runtime.
 *
 * ## چرا این فایل وجود دارد
 *
 * راهنمای توسعه‌دهنده (`DeveloperGuide.tsx`) قبلاً یک **کپیِ تولیدشده** از قرارداد
 * را `import` می‌کرد. یعنی دو نسخه از یک حقیقت: `PluginPackageContract.php` در
 * بک‌اند، و `scripts/generated/plugin-package-contract.ts` در فرانت. هر بار که
 * قرارداد عوض می‌شد، راهنما تا وقتی کسی فایل تولیدی را دوباره نمی‌ساخت دربارهٔ
 * نسخهٔ کهنه حرف می‌زد — و هیچ‌چیز قرمز نمی‌شد.
 *
 * حالا تنها منبع، `GET /v1/admin/plugins/contract` است. این فایل دو کار می‌کند:
 * شکلِ داده را تعریف می‌کند، و **بدنهٔ پاسخ را اعتبارسنجی می‌کند**.
 *
 * ## چرا اعتبارسنجی، و چرا نه `as PluginPackageContract`
 *
 * `authed<T>()` یک assertion بی‌صدا می‌کند: `json.data as T`. اگر بک‌اند یک کلید را
 * حذف کند یا کل endpoint در دسترس نباشد، TypeScript چیزی نمی‌گوید و UI
 * `undefined` را رندر می‌کند — یعنی راهنمایی که بی‌سروصدا **ناقص** است. بدتر از
 * آن، «صادقانه به نظر می‌رسد».
 *
 * پس `parsePluginContract` هر فیلدی را که UI لازم دارد می‌سنجد و به‌جای cast
 * کردن، **فهرستِ دقیقِ کمبودها** را برمی‌گرداند. UI آن را نشان می‌دهد و پنهانش
 * نمی‌کند: یک راهنمایِ ناقص که بگوید ناقص است، بهتر از یک راهنمایِ ناقصِ ساکت
 * است — و بهتر از هر دو، برگشتن بی‌صدا به یک کپیِ سخت‌کد است که هرگز کهنه نمی‌شود
 * چون هرگز خوانده نمی‌شود.
 */

/** وضعیت runtimeِ یک نقطهٔ اتصال. */
export type PluginPointStatus = "live" | "declared_only" | "deferred" | "deprecated";

/** درجهٔ باز بودنِ اعلانِ یک نقطه. */
export type PluginPointOpenness = "closed" | "schema_defined" | "open_vocabulary";

/** مشخصاتِ یک فیلد در micro-schema. فیلدهای اختیاری عمداً اختیاری‌اند. */
export type PluginFieldSpec = {
  type: string;
  required: boolean;
  maxLength?: number;
  minimum?: number;
  maximum?: number;
  pattern?: string;
  is_path?: boolean;
  maxItems?: number;
  itemMaxLength?: number;
  itemPattern?: string;
};

export type PluginAllowedPath = { path: string; label_fa: string; label_en?: string };

export type PluginBadExample = { why?: string; decl: Record<string, unknown> };

/** ECO3 — دستهٔ کدهای خطا: برچسب و راهنمای رفع، به دو زبان. */
export type PluginErrorCodeGroup = {
  label_fa: string;
  label_en: string;
  remedy_fa: string;
  remedy_en: string;
};

/** ECO3 — یک نمونهٔ عینی request/response. */
export type PluginApiExample = {
  id: string;
  title_fa: string;
  title_en: string;
  method: string;
  path: string;
  request: unknown;
  response: unknown;
  note_fa: string;
  note_en: string;
};

/** ECO3 — یک ورودی مستندات که خودِ افزونه در `manifest.docs` اعلام کرده. */
export type PluginDocEntry = {
  title_fa: string;
  title_en: string;
  method: string;
  path: string;
  description_fa: string;
  description_en: string;
  auth: string;
  request?: unknown;
  response?: unknown;
};

/** ECO3 — مستندات یک افزونهٔ فعال. */
export type PluginDeveloperDocs = {
  slug: string;
  name: string;
  version: string;
  entries: PluginDocEntry[];
};

export type PluginExtensionPoint = {
  key: string;
  label_fa: string;
  label_en?: string;
  desc_fa: string;
  desc_en?: string;
  status: PluginPointStatus;
  openness: PluginPointOpenness;
  openness_why: string;
  openness_why_en?: string;
  since: string;
  schema_version: number;
  schema: { fields: Record<string, PluginFieldSpec>; cross: string[] };
  open_schema: boolean;
  max: { declarations: number; bytes: number; depth: number; properties: number };
  example_ok: Record<string, unknown>[];
  example_bad: PluginBadExample[];
  forbidden: string[];
};

/** شکلِ `data` در پاسخ `GET /v1/admin/plugins/contract`. */
export type PluginPackageContract = {
  core_contract_version: string;
  declaration_meta_fields: string[];
  forbidden: string[];
  reserved_type_names: string[];
  overridable_interfaces: string[];
  deferred_extension_points: Record<string, string>;
  package: {
    manifest_name: string;
    backend_root: string;
    frontend_root: string;
    plugin_namespace_prefix: string;
  };
  limits: {
    max_zip_bytes: number;
    max_files: number;
    max_uncompressed_bytes: number;
    max_compression_ratio: number;
  };
  grammar: { types: string[]; limits: Record<string, number> };
  allowed_paths: PluginAllowedPath[];
  extension_points: PluginExtensionPoint[];
  error_code_groups: Record<string, PluginErrorCodeGroup>;
  error_codes: Record<string, string>;
  api_examples: PluginApiExample[];
  plugin_docs: PluginDeveloperDocs[];
};

/* ── اعتبارسنجی ───────────────────────────────────────────────────────────── */

export type ContractParse =
  | { ok: true; value: PluginPackageContract }
  | { ok: false; missing: string[] };

type Ctx = { missing: string[] };

const isRecord = (v: unknown): v is Record<string, unknown> =>
  typeof v === "object" && v !== null && !Array.isArray(v);

/**
 * map (شیءِ کلید→مقدار) را با تحملِ **آرایهٔ خالی** برمی‌گرداند.
 *
 * ⚠️ تلهٔ PHP: `json_encode([])` → `[]` نه `{}`. قراردادِ بک‌اند نقاطی دارد که
 * در PHP `'schema' => ['fields' => []]` هستند، پس JSON آن‌ها `fields: []`
 * می‌شود. اگر `[]` را «نبودِ map» بشماریم، پنج نقطه («ویجت داشبورد»،
 * «تنظیمات»، «مجموعه‌داده»، «block type»، «رجیستری صفحه») «کمبود» اعلام
 * می‌شوند و کلِ صفحهٔ مستندات به یک پیام خطا تبدیل می‌شود — بدون اینکه
 * واقعاً چیزی کم باشد. آرایهٔ خالی و شیءِ خالی اینجا **هم‌معنا**اند: هیچ
 * عضوی نیست.
 */
const asMap = (v: unknown): Record<string, unknown> | undefined =>
  isRecord(v) ? v : Array.isArray(v) && v.length === 0 ? {} : undefined;

function str(ctx: Ctx, obj: Record<string, unknown> | undefined, key: string, path: string): string {
  const v = obj?.[key];
  if (typeof v !== "string" || v === "") {
    ctx.missing.push(path);
    return "";
  }
  return v;
}

function num(ctx: Ctx, obj: Record<string, unknown> | undefined, key: string, path: string): number {
  const v = obj?.[key];
  if (typeof v !== "number" || !Number.isFinite(v)) {
    ctx.missing.push(path);
    return 0;
  }
  return v;
}

function bool(ctx: Ctx, obj: Record<string, unknown> | undefined, key: string, path: string): boolean {
  const v = obj?.[key];
  if (typeof v !== "boolean") {
    ctx.missing.push(path);
    return false;
  }
  return v;
}

function strList(ctx: Ctx, obj: Record<string, unknown> | undefined, key: string, path: string): string[] {
  const v = obj?.[key];
  if (!Array.isArray(v) || v.some((x) => typeof x !== "string")) {
    ctx.missing.push(path);
    return [];
  }
  return v as string[];
}

function strMap(ctx: Ctx, obj: Record<string, unknown> | undefined, key: string, path: string): Record<string, string> {
  const v = asMap(obj?.[key]);
  if (!v || Object.values(v).some((x) => typeof x !== "string")) {
    ctx.missing.push(path);
    return {};
  }
  return v as Record<string, string>;
}

function numMap(ctx: Ctx, obj: Record<string, unknown> | undefined, key: string, path: string): Record<string, number> {
  const v = asMap(obj?.[key]);
  if (!v || Object.values(v).some((x) => typeof x !== "number")) {
    ctx.missing.push(path);
    return {};
  }
  return v as Record<string, number>;
}

function recordList<T>(
  ctx: Ctx,
  obj: Record<string, unknown> | undefined,
  key: string,
  path: string,
  item: (raw: Record<string, unknown>, at: string, ctx: Ctx) => T,
): T[] {
  const v = obj?.[key];
  if (!Array.isArray(v)) {
    ctx.missing.push(path);
    return [];
  }
  const out: T[] = [];
  v.forEach((raw, i) => {
    if (!isRecord(raw)) {
      ctx.missing.push(`${path}[${i}]`);
      return;
    }
    out.push(item(raw, `${path}[${i}]`, ctx));
  });
  return out;
}

/** نگاشتِ `key => آبجکت` — برای `error_code_groups`. */
function recordMap<T>(
  ctx: Ctx,
  obj: Record<string, unknown> | undefined,
  key: string,
  path: string,
  item: (raw: Record<string, unknown>, at: string, ctx: Ctx) => T,
): Record<string, T> {
  const v = obj?.[key];
  if (!isRecord(v)) {
    ctx.missing.push(path);
    return {};
  }
  const out: Record<string, T> = {};
  for (const [k, raw] of Object.entries(v)) {
    if (!isRecord(raw)) {
      ctx.missing.push(`${path}.${k}`);
      continue;
    }
    out[k] = item(raw, `${path}.${k}`, ctx);
  }
  return out;
}

/** رشتهٔ اختیاری — نبودش داده را خراب نمی‌کند، پس به `missing` نمی‌رود. */
function optStr(raw: Record<string, unknown>, key: string): string | undefined {
  return typeof raw[key] === "string" && raw[key] !== "" ? (raw[key] as string) : undefined;
}

/** رشتهٔ اختیاریِ صریحاً خالی — برای توضیح‌های بلند که می‌توانند خالی باشند. */
function lenientStr(raw: Record<string, unknown>, key: string): string {
  return typeof raw[key] === "string" ? raw[key] : "";
}

function parseFieldSpec(raw: Record<string, unknown>, at: string, ctx: Ctx): PluginFieldSpec {
  const spec: PluginFieldSpec = {
    type: str(ctx, raw, "type", `${at}.type`),
    required: bool(ctx, raw, "required", `${at}.required`),
  };
  // بقیهٔ کلیدها اختیاری‌اند: نبودشان داده را خراب نمی‌کند، فقط راهنما کمتر می‌گوید.
  for (const k of ["maxLength", "minimum", "maximum", "maxItems", "itemMaxLength"] as const) {
    if (typeof raw[k] === "number") spec[k] = raw[k] as number;
  }
  for (const k of ["pattern", "itemPattern"] as const) {
    if (typeof raw[k] === "string") spec[k] = raw[k] as string;
  }
  if (typeof raw.is_path === "boolean") spec.is_path = raw.is_path;
  return spec;
}

function parsePoint(raw: Record<string, unknown>, at: string, ctx: Ctx): PluginExtensionPoint {
  const schema = isRecord(raw.schema) ? raw.schema : undefined;
  if (!schema) ctx.missing.push(`${at}.schema`);

  const max = isRecord(raw.max) ? raw.max : undefined;
  if (!max) ctx.missing.push(`${at}.max`);

  const status = str(ctx, raw, "status", `${at}.status`) as PluginPointStatus;
  const openness = str(ctx, raw, "openness", `${at}.openness`) as PluginPointOpenness;

  // `fields: []` از PHP هم‌معنای «هیچ فیلدی» است — نه «نبودِ schema.fields».
  const fields = asMap(schema?.fields);
  if (!fields) ctx.missing.push(`${at}.schema.fields`);

  const point: PluginExtensionPoint = {
    key: str(ctx, raw, "key", `${at}.key`),
    label_fa: str(ctx, raw, "label_fa", `${at}.label_fa`),
    desc_fa: str(ctx, raw, "desc_fa", `${at}.desc_fa`),
    status,
    openness,
    openness_why: str(ctx, raw, "openness_why", `${at}.openness_why`),
    since: str(ctx, raw, "since", `${at}.since`),
    schema_version: num(ctx, raw, "schema_version", `${at}.schema_version`),
    schema: {
      fields: Object.fromEntries(
        Object.entries(fields ?? {}).map(([name, spec]) => [
          name,
          isRecord(spec) ? parseFieldSpec(spec, `${at}.schema.fields.${name}`, ctx) : (ctx.missing.push(`${at}.schema.fields.${name}`), { type: "", required: false }),
        ]),
      ),
      cross: strList(ctx, schema, "cross", `${at}.schema.cross`),
    },
    open_schema: bool(ctx, raw, "open_schema", `${at}.open_schema`),
    max: {
      declarations: num(ctx, max, "declarations", `${at}.max.declarations`),
      bytes: num(ctx, max, "bytes", `${at}.max.bytes`),
      depth: num(ctx, max, "depth", `${at}.max.depth`),
      properties: num(ctx, max, "properties", `${at}.max.properties`),
    },
    example_ok: recordList(ctx, raw, "example_ok", `${at}.example_ok`, (e) => e),
    example_bad: recordList(ctx, raw, "example_bad", `${at}.example_bad`, (e) => ({
      why: typeof e.why === "string" ? e.why : undefined,
      decl: isRecord(e.decl) ? e.decl : {},
    })),
    forbidden: Array.isArray(raw.forbidden) ? strList(ctx, raw, "forbidden", `${at}.forbidden`) : [],
  };
  // برچسب/توضیح انگلیسی اختیاری‌اند: نبودشان یعنی UI به فارسی برمی‌گردد،
  // نه اینکه قرارداد «ناقص» اعلام شود.
  const labelEn = optStr(raw, "label_en");
  const descEn = optStr(raw, "desc_en");
  const whyEn = optStr(raw, "openness_why_en");
  if (labelEn) point.label_en = labelEn;
  if (descEn) point.desc_en = descEn;
  if (whyEn) point.openness_why_en = whyEn;
  return point;
}

function parseApiExample(raw: Record<string, unknown>, at: string, ctx: Ctx): PluginApiExample {
  return {
    id: str(ctx, raw, "id", `${at}.id`),
    title_fa: str(ctx, raw, "title_fa", `${at}.title_fa`),
    title_en: str(ctx, raw, "title_en", `${at}.title_en`),
    method: str(ctx, raw, "method", `${at}.method`),
    path: str(ctx, raw, "path", `${at}.path`),
    request: raw.request ?? null,
    response: raw.response ?? null,
    note_fa: str(ctx, raw, "note_fa", `${at}.note_fa`),
    note_en: str(ctx, raw, "note_en", `${at}.note_en`),
  };
}

function parseDocEntry(raw: Record<string, unknown>, at: string, ctx: Ctx): PluginDocEntry {
  const entry: PluginDocEntry = {
    title_fa: str(ctx, raw, "title_fa", `${at}.title_fa`),
    title_en: str(ctx, raw, "title_en", `${at}.title_en`),
    method: str(ctx, raw, "method", `${at}.method`),
    path: str(ctx, raw, "path", `${at}.path`),
    description_fa: lenientStr(raw, "description_fa"),
    description_en: lenientStr(raw, "description_en"),
    auth: lenientStr(raw, "auth"),
  };
  if (raw.request !== undefined) entry.request = raw.request;
  if (raw.response !== undefined) entry.response = raw.response;
  return entry;
}

/**
 * بدنهٔ پاسخ را به شکلِ قابل‌استفاده درمی‌آورد، یا فهرستِ کمبودها را می‌دهد.
 *
 * `missing` خالی یعنی قرارداد کامل است. عمداً **هیچ مقدار پیش‌فرضی** جای
 * کمبود گذاشته نمی‌شود: صفرِ ساختگی در یک راهنما یعنی عددِ دروغ.
 */
export function parsePluginContract(raw: unknown): ContractParse {
  const ctx: Ctx = { missing: [] };

  /*
   * ⚠️ نگهبانِ رگرسیون — چرا پوشش را همین‌جا باز می‌کنیم:
   *
   * بک‌اند پاسخِ تک‌نمونه را در `{ data: … }` می‌پیچد. `serverApi` عمداً unwrap
   * نمی‌کند و `serverOne` می‌کند. اگر صفحه‌ای اشتباهاً `serverApi` را صدا بزند،
   * پوشش به‌جای خودِ قرارداد به اینجا می‌رسد و **همهٔ** کلیدها «missing» می‌شوند —
   * دقیقاً همان چیزی که صفحهٔ «مستندات توسعه‌دهندگان» را به یک صفحهٔ خطای کامل
   * تبدیل کرده بود (ECO3 در عمل هرگز رندر نمی‌شد، ولی ردیفش `done` بود).
   * یک unwrapِ یک‌سطحی این اشتباه را پنهان نمی‌کند، فقط بی‌اثر می‌کند.
   */
  if (isRecord(raw) && isRecord(raw.data)) raw = raw.data;

  if (!isRecord(raw)) return { ok: false, missing: ["data"] };

  const pkg = isRecord(raw.package) ? raw.package : undefined;
  if (!pkg) ctx.missing.push("package");

  const limits = isRecord(raw.limits) ? raw.limits : undefined;
  if (!limits) ctx.missing.push("limits");

  const grammar = isRecord(raw.grammar) ? raw.grammar : undefined;
  if (!grammar) ctx.missing.push("grammar");

  const value: PluginPackageContract = {
    core_contract_version: str(ctx, raw, "core_contract_version", "core_contract_version"),
    declaration_meta_fields: strList(ctx, raw, "declaration_meta_fields", "declaration_meta_fields"),
    forbidden: strList(ctx, raw, "forbidden", "forbidden"),
    reserved_type_names: strList(ctx, raw, "reserved_type_names", "reserved_type_names"),
    overridable_interfaces: strList(ctx, raw, "overridable_interfaces", "overridable_interfaces"),
    deferred_extension_points: strMap(ctx, raw, "deferred_extension_points", "deferred_extension_points"),
    package: {
      manifest_name: str(ctx, pkg, "manifest_name", "package.manifest_name"),
      backend_root: str(ctx, pkg, "backend_root", "package.backend_root"),
      frontend_root: str(ctx, pkg, "frontend_root", "package.frontend_root"),
      plugin_namespace_prefix: str(ctx, pkg, "plugin_namespace_prefix", "package.plugin_namespace_prefix"),
    },
    limits: {
      max_zip_bytes: num(ctx, limits, "max_zip_bytes", "limits.max_zip_bytes"),
      max_files: num(ctx, limits, "max_files", "limits.max_files"),
      max_uncompressed_bytes: num(ctx, limits, "max_uncompressed_bytes", "limits.max_uncompressed_bytes"),
      max_compression_ratio: num(ctx, limits, "max_compression_ratio", "limits.max_compression_ratio"),
    },
    grammar: {
      types: strList(ctx, grammar, "types", "grammar.types"),
      limits: numMap(ctx, grammar, "limits", "grammar.limits"),
    },
    allowed_paths: recordList(ctx, raw, "allowed_paths", "allowed_paths", (p, at) => {
      const entry: PluginAllowedPath = {
        path: str(ctx, p, "path", `${at}.path`),
        label_fa: str(ctx, p, "label_fa", `${at}.label_fa`),
      };
      const labelEn = optStr(p, "label_en");
      if (labelEn) entry.label_en = labelEn;
      return entry;
    }),
    extension_points: recordList(ctx, raw, "extension_points", "extension_points", parsePoint),
    error_code_groups: recordMap(ctx, raw, "error_code_groups", "error_code_groups", (g, at, c) => ({
      label_fa: str(c, g, "label_fa", `${at}.label_fa`),
      label_en: str(c, g, "label_en", `${at}.label_en`),
      remedy_fa: str(c, g, "remedy_fa", `${at}.remedy_fa`),
      remedy_en: str(c, g, "remedy_en", `${at}.remedy_en`),
    })),
    error_codes: strMap(ctx, raw, "error_codes", "error_codes"),
    api_examples: recordList(ctx, raw, "api_examples", "api_examples", parseApiExample),
    plugin_docs: recordList(ctx, raw, "plugin_docs", "plugin_docs", (d, at, c) => ({
      slug: str(c, d, "slug", `${at}.slug`),
      name: str(c, d, "name", `${at}.name`),
      version: lenientStr(d, "version"),
      entries: recordList(c, d, "entries", `${at}.entries`, parseDocEntry),
    })),
  };

  if (ctx.missing.length > 0) return { ok: false, missing: [...new Set(ctx.missing)] };
  return { ok: true, value };
}

/** مسیر endpointِ قرارداد — تنها راهِ خواندنش از کلاینت. */
export const PLUGIN_CONTRACT_PATH = "/v1/admin/plugins/contract";
