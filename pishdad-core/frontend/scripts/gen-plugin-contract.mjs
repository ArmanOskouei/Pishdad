#!/usr/bin/env node
/**
 * K6.11 — دادهٔ راهنمای برنامه‌نویس پلاگین را از قرارداد هسته تولید کن.
 *
 * چرا این اسکریپت وجود دارد
 * ─────────────────────────
 * منبع حقیقتِ «پلاگین چه می‌تواند» یک آرایهٔ PHP است:
 *   `pishdad-core/backend/app/Services/Plugins/PluginPackageContract.php`
 * پیش از این، همین اطلاعات را سه جا دستی تکرار می‌کردیم (فایل قرارداد،
 * `docs/PLUGIN-GUIDE.md`، و `src/components/DeveloperGuide.tsx`) و آن سه
 * با هم اختلاف پیدا کرده بودند. تولیدکنندهٔ بی‌صدا از دست‌نویسی بدتر است،
 * چون authoritative به نظر می‌رسد؛ پس این اسکریپت دو حالت دارد:
 *
 *   node scripts/gen-plugin-contract.mjs             → نوشتن/به‌روزرسانی
 *   node scripts/gen-plugin-contract.mjs --check     → فقط گزارش drift
 *
 * حالت `--check` روی drift **غیرصفر** خارج می‌شود، پس در CI یا قبل از
 * commit می‌شود آن را دید. الگو عیناً همان `gen:types` است: یک آرت‌یفکت
 * تولیدشده که **commit** می‌شود و مصرف‌کننده‌اش آن را import می‌کند —
 * نه hook داخل `next.config.ts`.
 *
 * چرا PHP اجرا نمی‌شود
 * ────────────────────
 * نه `php` روی این ماشین هست و نه هیچ جای دیگری از پروژه PHP را صدا
 * می‌زند؛ و `next build` باید بدون PHP هم سبز بماند. پس قرارداد **تجزیه
 * می‌شود، اجرا نمی‌شود**. تجزیه‌گر پایین عمداً سخت‌گیر است: هر چیزی که
 * نفهمد خطا می‌دهد و مقدار ساختگی تولید نمی‌کند. یک تجزیه‌گر ساکت که
 * `[]` برگرداند و بعد JSON را تولید کند دقیقاً همان باگی است که این تسک
 * برای حلش وجود دارد.
 *
 * محدودیت صادقانه
 * ───────────────
 * معنای `PluginPackageContract::extensionPoints()` اینجا **بازتولید** می‌شود
 * (`array_merge(['key' => $key], $meta, ['forbidden' => self::FORBIDDEN])`،
 * یعنی `PluginPackageContract.php:536`). اگر آن merge عوض شود، این
 * اسکریپت نمی‌فهمد — برای همین `assertMergeShapeUnchanged()` شکلش را نگه
 * می‌دارد و خطا می‌دهد. اجرای واقعی PHP تنها راه کاملاً وفادار بودن است.
 */

import { createHash } from "node:crypto";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";

const projectRoot = resolve(import.meta.dirname, "..");
const repoRoot = resolve(projectRoot, "../..");

const DEFAULT_CONTRACT = "pishdad-core/backend/app/Services/Plugins/PluginPackageContract.php";
const DEFAULT_OUT = "pishdad-core/frontend/scripts/generated/plugin-package-contract.ts";

/** ثابت‌هایی که بدون‌شان فایل تولیدی معنا ندارد — نبودشان خطاست، نه صفر. */
const REQUIRED_CONSTS = [
  "MANIFEST",
  "BACKEND_ROOT",
  "FRONTEND_ROOT",
  "MAX_ZIP_BYTES",
  "MAX_FILES",
  "MAX_UNCOMPRESSED_BYTES",
  "MAX_COMPRESSION_RATIO",
  "FORBIDDEN",
  "RESERVED_TYPE_NAMES",
  "OVERRIDABLE_INTERFACES",
  "PLUGIN_NAMESPACE_PREFIX",
  "GRAMMAR_LIMITS",
  "GRAMMAR_TYPES",
  "EXTENSION_POINTS",
  "DEFERRED_EXTENSION_POINTS",
  "ERROR_CODE_GROUPS",
  "ERROR_CODES",
  "API_EXAMPLES",
];

class ContractError extends Error {}

// ─── ۱. حذف کامنت‌ها ────────────────────────────────────────────────────────
// رشته‌ها اول از همه کپی می‌شوند تا `//` یا `#` داخل رشته کامنت نشود.
// نمونهٔ واقعی در همین فایل: `preg_match('#^[A-Za-z]:#', …)` و
// `'^/admin(/[a-z0-9._-]+)*$'`.

function skipString(code, i) {
  const quote = code[i];
  const n = code.length;
  let j = i + 1;
  while (j < n) {
    const c = code[j];
    if (c === "\\") {
      j += 2;
      continue;
    }
    if (c === quote) return j + 1;
    j += 1;
  }
  throw new ContractError("رشتهٔ بسته‌نشده در قرارداد");
}

function stripComments(src) {
  const n = src.length;
  let out = "";
  let i = 0;
  while (i < n) {
    const c = src[i];
    if (c === "'" || c === '"') {
      const end = skipString(src, i);
      out += src.slice(i, end);
      i = end;
      continue;
    }
    if (c === "/" && src[i + 1] === "/") {
      while (i < n && src[i] !== "\n") i += 1;
      continue;
    }
    if (c === "#") {
      while (i < n && src[i] !== "\n") i += 1;
      continue;
    }
    if (c === "/" && src[i + 1] === "*") {
      const end = src.indexOf("*/", i + 2);
      if (end === -1) throw new ContractError("کامنت /* بسته‌نشده در قرارداد");
      i = end + 2;
      continue;
    }
    out += c;
    i += 1;
  }
  return out;
}

// ─── ۲. خواندن ثابت‌ها و متدهای آرایه‌ای ────────────────────────────────────

/** از بعدِ `=` تا `;` سطح‌بالا می‌پرد. آرایه/پرانتز/کروشه را می‌شمارد. */
function skipToStatementEnd(code, i) {
  const n = code.length;
  let depth = 0;
  while (i < n) {
    const c = code[i];
    if (c === "'" || c === '"') {
      i = skipString(code, i);
      continue;
    }
    if (c === "[" || c === "(" || c === "{") {
      depth += 1;
      i += 1;
      continue;
    }
    if (c === "]" || c === ")" || c === "}") {
      depth -= 1;
      i += 1;
      continue;
    }
    if (c === ";" && depth === 0) return i;
    i += 1;
  }
  throw new ContractError("عبارت بدون `;` پایان نیافت");
}

function readConstRaw(code, name) {
  const re = new RegExp(`\\bconst\\s+${name}\\s*=`, "g");
  const m = re.exec(code);
  if (!m) return null;
  const end = skipToStatementEnd(code, m.index + m[0].length);
  return code.slice(m.index + m[0].length, end);
}

/** بدنهٔ یک متد استاتیک، از `{` تا `}` متوازن. */
function readMethodBody(code, name) {
  const re = new RegExp(`\\bfunction\\s+${name}\\s*\\([^)]*\\)[^{;]*\\{`, "g");
  const m = re.exec(code);
  if (!m) return null;
  let i = m.index + m[0].length;
  let depth = 1;
  const n = code.length;
  while (i < n && depth > 0) {
    const c = code[i];
    if (c === "'" || c === '"') {
      i = skipString(code, i);
      continue;
    }
    if (c === "{") depth += 1;
    else if (c === "}") depth -= 1;
    i += 1;
  }
  if (depth !== 0) throw new ContractError(`بدنهٔ متد ${name} بسته نشد`);
  return code.slice(m.index + m[0].length, i - 1);
}

function readNamespace(code) {
  const m = /^\s*namespace\s+([A-Za-z_][A-Za-z0-9_\\]*)\s*;/m.exec(code);
  return m ? m[1] : "";
}

/** نام کوتاه کلاس → FQCN، از روی `use` ها. */
function readUseMap(code) {
  const map = new Map();
  const re = /^\s*use\s+(?!function\b|const\b)([A-Za-z_\\][A-Za-z0-9_\\]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/gm;
  let m;
  while ((m = re.exec(code)) !== null) {
    const fq = m[1].replace(/^\\/, "");
    const alias = m[2] ?? fq.slice(fq.lastIndexOf("\\") + 1);
    map.set(alias, fq);
  }
  return map;
}

// ─── ۳. تجزیهٔ بیان آرایه/اسکالر ────────────────────────────────────────────

const IDENT_RE = /[A-Za-z_][A-Za-z0-9_]*/y;

function parseStringLiteral(code, i) {
  const quote = code[i];
  const n = code.length;
  let j = i + 1;
  let value = "";
  while (j < n) {
    const c = code[j];
    if (c === "\\") {
      const next = code[j + 1];
      // PHP تک‌کوتیشنی: فقط \\ و \' گریز می‌گیرند؛ بقیه خام می‌مانند.
      value += next === "\\" || next === quote ? next : `\\${next}`;
      j += 2;
      continue;
    }
    if (c === quote) return [value, j + 1];
    value += c;
    j += 1;
  }
  throw new ContractError("رشتهٔ بسته‌نشده");
}

function parsePrimary(code, i, ctx) {
  const n = code.length;
  while (i < n && /\s/.test(code[i])) i += 1;
  const c = code[i];
  if (c === undefined) throw new ContractError("عبارت ناتمام");

  if (c === "'" || c === '"') return parseStringLiteral(code, i);

  if (c === "[") return parseArrayLiteral(code, i, ctx);

  if (/[0-9]/.test(c) || (c === "-" && /[0-9]/.test(code[i + 1] ?? ""))) {
    const m = /^-?\d+(?:_\d+)*(?:\.\d+)?/.exec(code.slice(i));
    if (!m) throw new ContractError(`عدد خوانده نشد در offset ${i}`);
    const raw = m[0].replace(/_/g, "");
    return [Number(raw), i + m[0].length];
  }

  if (/[A-Za-z_\\]/.test(c)) {
    // مسیر کامل \Foo\Bar یا namespace\Foo
    if (c === "\\") {
      const m = /^\\?[A-Za-z_][A-Za-z0-9_\\]*/.exec(code.slice(i));
      return [m[0].replace(/^\\/, ""), i + m[0].length];
    }
    IDENT_RE.lastIndex = i;
    const m = IDENT_RE.exec(code);
    if (!m) throw new ContractError(`شناسه خوانده نشد در offset ${i}`);
    let j = IDENT_RE.lastIndex;
    const name = m[0];

    if (name === "true") return [true, j];
    if (name === "false") return [false, j];
    if (name === "null") return [null, j];

    // NAME::class  |  self::CONST  |  CONST
    while (code[j] === ":") {
      const m2 = /^::\s*([A-Za-z_][A-Za-z0-9_]*)/.exec(code.slice(j));
      if (!m2) break;
      const member = m2[1];
      j += m2[0].length;
      if (member === "class") {
        return [ctx.resolveClass(name), j];
      }
      if (name === "self" || name === "static") {
        return [ctx.resolveConst(member), j];
      }
      throw new ContractError(`ارجاع غیرمجاز «${name}::${member}» در قرارداد`);
    }

    return [ctx.resolveConst(name), j];
  }

  throw new ContractError(`توکن ناشناخته «${c}» در offset ${i}`);
}

/** عملگرهای چسبنده: `*` و `/` چسبنده‌تر از `+` و `-`. */
const ADD_OPS = new Set(["+", "-"]);
const MUL_OPS = new Set(["*", "/"]);

function parseOperators(code, i, seed, ops, ctx) {
  let value = seed;
  const n = code.length;
  for (;;) {
    let j = i;
    while (j < n && /\s/.test(code[j])) j += 1;
    if (j >= n) return [value, j];
    const c = code[j];
    if (!ops.has(c)) return [value, i];

    // الحاق رشته در PHP: `.`
    if (c === ".") {
      const [rhs, next] = parseExpr(code, j + 1, ctx);
      value = String(value) + String(rhs);
      i = next;
      continue;
    }
    const [rhs, next] = parsePrimary(code, j + 1, ctx);
    if (typeof value !== "number" || typeof rhs !== "number") {
      throw new ContractError(`عملگر حسابی روی مقدار غیرعددی «${c}»`);
    }
    value = c === "+" ? value + rhs : c === "-" ? value - rhs : c === "*" ? value * rhs : value / rhs;
    i = next;
  }
}

function parseTerm(code, i, ctx) {
  const [head, next] = parsePrimary(code, i, ctx);
  const [value, end] = parseOperators(code, next, head, new Set([...MUL_OPS, "."]), ctx);
  return parseOperators(code, end, value, ADD_OPS, ctx);
}

function parseExpr(code, i, ctx) {
  return parseTerm(code, i, ctx);
}

function parseArrayLiteral(code, i, ctx) {
  const n = code.length;
  let j = i + 1;
  const list = [];
  const map = {};
  let keyed = false;

  for (;;) {
    while (j < n && /\s/.test(code[j])) j += 1;
    if (j >= n) throw new ContractError("آرایهٔ بسته‌نشده در قرارداد");
    if (code[j] === "]") {
      return [{ __php: keyed ? "map" : "list", entries: keyed ? map : list }, j + 1];
    }
    if (code[j] === ",") {
      j += 1;
      continue;
    }

    const [first, afterFirst] = parsePrimary(code, j, ctx);
    let k = afterFirst;
    while (k < n && /\s/.test(code[k])) k += 1;

    if (code.slice(k, k + 2) === "=>") {
      keyed = true;
      const key = typeof first === "number" || typeof first === "string" ? String(first) : null;
      if (key === null) throw new ContractError(`کلید غیرمجاز در آرایه: ${JSON.stringify(first)}`);
      const [value, next] = parseExpr(code, k + 2, ctx);
      map[key] = value;
      j = next;
    } else {
      const [value, next] = parseOperators(
        code,
        k,
        first,
        new Set([...MUL_OPS, ...ADD_OPS, "."]),
        ctx,
      );
      list.push(value);
      j = next;
    }
  }
}

/** `__php` نشانهٔ داخلی است و باید پیش از هر استفاده باز شود. */
function unwrap(value, what) {
  if (value && typeof value === "object" && !Array.isArray(value) && value.__php) {
    if (value.__php === "list") return value.entries.map((v) => unwrap(v, what));
    return Object.fromEntries(
      Object.entries(value.entries).map(([k, v]) => [k, unwrap(v, what)]),
    );
  }
  if (Array.isArray(value)) return value.map((v) => unwrap(v, what));
  if (value && typeof value === "object") {
    return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, unwrap(v, what)]));
  }
  return value;
}

function isPhpList(v) {
  return v && typeof v === "object" && !Array.isArray(v) && v.__php === "list";
}
function isPhpMap(v) {
  return v && typeof v === "object" && !Array.isArray(v) && v.__php === "map";
}
function isEmptyPhpArray(v) {
  return isPhpList(v) && v.entries.length === 0;
}

/**
 * PHP `[]` هم لیست خالی است هم نقشهٔ خالی؛ نوع اعلامیِ قرارداد تصمیم می‌گیرد.
 *
 * هر دو شکلِ «پیچیده‌نشده» (`__php`) و «بازشده» (آبجکت/آرایهٔ ساده) را می‌پذیرد،
 * چون گاهی مقدار از قبل `unwrap` شده است.
 */
function asMap(value, what) {
  if (isEmptyPhpArray(value)) return {};
  if (isPhpList(value)) {
    return Object.fromEntries(value.entries.map((v, idx) => [String(idx), unwrap(v, what)]));
  }
  if (isPhpMap(value)) return unwrap(value, what);
  if (value && typeof value === "object" && !Array.isArray(value)) return value;
  // `unwrap` آرایهٔ خالی PHP را به `[]` جاوااسکریپت تبدیل کرده و تفاوت
  // «لیست خالی»/«نقشهٔ خالی» از بین رفته. اینجا نوع اعلامیِ قرارداد داوری
  // می‌کند: فیلدی که نقشه است، خالی‌اش نقشه می‌ماند.
  if (Array.isArray(value)) {
    return Object.fromEntries(value.map((v, idx) => [String(idx), unwrap(v, what)]));
  }
  throw new ContractError(`${what} باید آرایه باشد، ${typeof value} بود`);
}

function asList(value, what) {
  if (isEmptyPhpArray(value)) return [];
  if (isPhpList(value)) return value.entries.map((v) => unwrap(v, what));
  if (isPhpMap(value)) return Object.values(unwrap(value, what));
  if (Array.isArray(value)) return value;
  throw new ContractError(`${what} باید فهرست باشد، ${typeof value} بود`);
}

// ─── ۴. حل ارجاع‌های بین ثابتی ──────────────────────────────────────────────

function makeResolver(code) {
  const namespace = readNamespace(code);
  const uses = readUseMap(code);
  const raw = new Map();
  const cache = new Map();
  const pending = new Set();

  const resolveConst = (name) => {
    if (cache.has(name)) return cache.get(name);
    if (pending.has(name)) {
      throw new ContractError(`ارجاع دایره‌ای بین ثابت‌ها روی «${name}»`);
    }
    if (!raw.has(name)) {
      const text = readConstRaw(code, name);
      if (text === null) throw new ContractError(`ثابت «${name}» در قرارداد پیدا نشد`);
      raw.set(name, parseExpr(text, 0, ctx));
    }
    pending.add(name);
    const [value] = raw.get(name);
    const resolved = unwrap(value, name);
    pending.delete(name);
    cache.set(name, resolved);
    return resolved;
  };

  const resolveClass = (name) => {
    if (name.includes("\\")) {
      if (uses.has(name)) return uses.get(name);
      return `${namespace}\\${name}`.replace(/^\\/, "");
    }
    if (uses.has(name)) return uses.get(name);
    if (name === "self" || name === "static") return namespace;
    throw new ContractError(`کلاس «${name}» نه use شده نه هم‌فضای نام‌برداری است`);
  };

  const ctx = { resolveConst, resolveClass };
  return { resolveConst, resolveClass, ctx };
}

/**
 * نگهبان شکل `extensionPoints()`.
 *
 * این اسکریپت معنای `array_merge(['key' => …], $meta, ['forbidden' => …])`
 * را **بازتولید** می‌کند، نه اینکه اجرایش کند. اگر آن merge عوض شود
 * بی‌صدا غلط می‌شویم ⇒ اینجا خطا می‌دهیم.
 */
function assertMergeShapeUnchanged(code) {
  const body = readMethodBody(code, "extensionPoints");
  if (body === null) {
    throw new ContractError("متد extensionPoints() پیدا نشد — بازتولید معنای آن ممکن نیست");
  }
  const flat = body.replace(/\s+/g, " ");
  for (const needle of ["array_merge(", "'key' => $key", "$meta", "self::FORBIDDEN"]) {
    if (!flat.includes(needle)) {
      throw new ContractError(
        `extensionPoints() شکلش عوض شده (${needle} نیست). معنای بازتولیدشده در ` +
          "scripts/gen-plugin-contract.mjs دیگر معتبر نیست — دستی بررسی کن.",
      );
    }
  }
}

// ─── ۵. ساخت مدل داده ───────────────────────────────────────────────────────

/**
 * `array_merge(['key' => $key], $meta, ['forbidden' => self::FORBIDDEN])`
 * دقیقاً مثل `PluginPackageContract::extensionPoints()`.
 */
function buildExtensionPoints(contract) {
  const forbidden = contract.forbidden;
  return Object.entries(contract.extensionPointsRaw).map(([key, meta]) => {
    const point = { key, ...meta, forbidden };
    for (const required of [
      "label_fa",
      "desc_fa",
      "status",
      "openness",
      "openness_why",
      "since",
      "schema_version",
      "max",
      "schema",
      "open_schema",
      "example_ok",
      "example_bad",
    ]) {
      if (!(required in point)) {
        throw new ContractError(`نقطهٔ اتصال «${key}» کلید «${required}» را ندارد`);
      }
    }
    return {
      ...point,
      schema: {
        fields: asMap(point.schema.fields ?? point.schema, `schema.fields نقطهٔ ${key}`),
        cross: asList(point.schema.cross ?? [], `schema.cross نقطهٔ ${key}`),
      },
      example_ok: asList(point.example_ok, `example_ok نقطهٔ ${key}`),
      example_bad: asList(point.example_bad, `example_bad نقطهٔ ${key}`).map((ex) => {
        if (ex === null || typeof ex !== "object" || Array.isArray(ex) || !("decl" in ex)) {
          throw new ContractError(`example_bad نقطهٔ ${key} ورودی «decl» ندارد`);
        }
        return { why: ex.why, decl: asMap(ex.decl, `example_bad.decl نقطهٔ ${key}`) };
      }),
    };
  });
}

function stableStringify(value) {
  if (Array.isArray(value)) return `[${value.map(stableStringify).join(",")}]`;
  if (value && typeof value === "object") {
    return `{${Object.keys(value)
      .sort()
      .map((k) => `${JSON.stringify(k)}:${stableStringify(value[k])}`)
      .join(",")}}`;
  }
  return JSON.stringify(value) ?? "null";
}

function readContract(contractPath) {
  const code = stripComments(readFileSync(contractPath, "utf8"));
  assertMergeShapeUnchanged(code);
  const { resolveConst, resolveClass, ctx } = makeResolver(code);

  for (const name of REQUIRED_CONSTS) resolveConst(name);

  const describeBody = readMethodBody(code, "describeAllowedPaths");
  if (describeBody === null) {
    throw new ContractError("متد describeAllowedPaths() پیدا نشد");
  }
  const retIdx = describeBody.indexOf("return");
  if (retIdx === -1) throw new ContractError("describeAllowedPaths() چیزی برنمی‌گرداند");
  const [allowedRaw] = parseExpr(describeBody, retIdx + "return".length, ctx);
  const allowedPaths = asList(allowedRaw, "describeAllowedPaths()").map((row) => {
    const entry = asMap(row, "describeAllowedPaths() سطر");
    for (const required of ["path", "label_fa"]) {
      if (!(required in entry)) {
        throw new ContractError(`describeAllowedPaths() سطر کلید «${required}» را ندارد`);
      }
    }
    return entry;
  });

  const extensionPointsRaw = asMap(resolveConst("EXTENSION_POINTS"), "EXTENSION_POINTS");
  const deferredRaw = asMap(resolveConst("DEFERRED_EXTENSION_POINTS"), "DEFERRED_EXTENSION_POINTS");
  const contract = {
    package: {
      manifestName: resolveConst("MANIFEST"),
      backendRoot: resolveConst("BACKEND_ROOT"),
      frontendRoot: resolveConst("FRONTEND_ROOT"),
      pluginNamespacePrefix: resolveConst("PLUGIN_NAMESPACE_PREFIX"),
    },
    limits: {
      maxZipBytes: resolveConst("MAX_ZIP_BYTES"),
      maxFiles: resolveConst("MAX_FILES"),
      maxUncompressedBytes: resolveConst("MAX_UNCOMPRESSED_BYTES"),
      maxCompressionRatio: resolveConst("MAX_COMPRESSION_RATIO"),
    },
    allowedPaths,
    forbidden: asList(resolveConst("FORBIDDEN"), "FORBIDDEN"),
    reservedTypeNames: asList(resolveConst("RESERVED_TYPE_NAMES"), "RESERVED_TYPE_NAMES"),
    overridableInterfaces: asList(resolveConst("OVERRIDABLE_INTERFACES"), "OVERRIDABLE_INTERFACES"),
    grammar: {
      limits: asMap(resolveConst("GRAMMAR_LIMITS"), "GRAMMAR_LIMITS"),
      types: asList(resolveConst("GRAMMAR_TYPES"), "GRAMMAR_TYPES"),
    },
    deferredExtensionPoints: Object.fromEntries(
      Object.entries(deferredRaw).map(([k, v]) => [k, String(v)]),
    ),
    extensionPoints: buildExtensionPoints({
      forbidden: asList(resolveConst("FORBIDDEN"), "FORBIDDEN"),
      extensionPointsRaw,
    }),
    errorCodeGroups: asMap(resolveConst("ERROR_CODE_GROUPS"), "ERROR_CODE_GROUPS"),
    errorCodes: asMap(resolveConst("ERROR_CODES"), "ERROR_CODES"),
    apiExamples: asList(resolveConst("API_EXAMPLES"), "API_EXAMPLES").map((entry) =>
      asMap(entry, "API_EXAMPLES ورودی"),
    ),
  };

  // ECO3 — هر کد خطا باید به یک دستهٔ موجود اشاره کند. کدِ بی‌دسته یعنی UI
  // درمانی برای نشان دادن ندارد — همان «راهنمای ناقصِ ساکت» که این تولیدکننده
  // برای حذفش وجود دارد.
  for (const [code, group] of Object.entries(contract.errorCodes)) {
    if (typeof group !== "string" || !(group in contract.errorCodeGroups)) {
      throw new ContractError(`کد خطای «${code}» به دستهٔ نامعتبر «${String(group)}» اشاره می‌کند`);
    }
  }
  for (const [key, group] of Object.entries(contract.errorCodeGroups)) {
    const g = asMap(group, `ERROR_CODE_GROUPS.${key}`);
    for (const requiredField of ["label_fa", "label_en", "remedy_fa", "remedy_en"]) {
      if (typeof g[requiredField] !== "string" || g[requiredField] === "") {
        throw new ContractError(`دستهٔ خطای «${key}» فیلد «${requiredField}» را ندارد`);
      }
    }
  }

  for (const required of ["manifestName", "backendRoot", "frontendRoot"]) {
    if (typeof contract.package[required] !== "string") {
      throw new ContractError(`package.${required} رشته نیست`);
    }
  }
  for (const [key, meta] of Object.entries(extensionPointsRaw)) {
    const built = contract.extensionPoints.find((p) => p.key === key);
    if (!built) throw new ContractError(`نقطهٔ «${key}» ساخته نشد`);
    for (const required of ["status", "openness", "since", "label_fa"]) {
      if (built[required] === undefined) {
        throw new ContractError(`نقطهٔ «${key}» کلید «${required}» را ندارد`);
      }
    }
    if (!["live", "declared_only", "deferred", "deprecated"].includes(built.status)) {
      throw new ContractError(`نقطهٔ «${key}» وضعیت ناشناخته «${built.status}» دارد`);
    }
    if (!["closed", "schema_defined", "open_vocabulary"].includes(built.openness)) {
      throw new ContractError(`نقطهٔ «${key}» درجهٔ بازی ناشناخته «${built.openness}» دارد`);
    }
    // `deprecated` بدون `replaced_by` یعنی نویسنده راه‌حل را حذف کرده ولی
    // جایگزین را نگفته — که عملاً یعنی مهاجرتی بدون مقصد. خودِ ساختار
    // قرارداد این را ممکن می‌کند، پس نگهبان باید جلویش را بگیرد.
    //
    // توجه به ساختار: `replaced_by` **داخل** بلوک `deprecated` است، نه
    // هم‌سطح `status`. این را از روی `PluginPackageContract.php:294-298` گرفته‌ام.
    if (built.status === "deprecated") {
      const dep = built.deprecated ?? {};
      if (typeof dep.replaced_by !== "string" || dep.replaced_by === "") {
        throw new ContractError(
          "نقطهٔ " + key + " وضعیت deprecated دارد ولی deprecated.replaced_by ندارد — مهاجرت بدون مقصد است",
        );
      }
    }
    void meta;
  }
  void resolveClass;

  return contract;
}

// ─── ۶. رندر ماژول TypeScript ──────────────────────────────────────────────

const HEADER = `/* eslint-disable */
/**
 * تولید خودکار — **دستی ویرایش نکن**.
 *
 * منبع حقیقت: \`${DEFAULT_CONTRACT}\`
 * تولیدکننده:  \`pishdad-core/frontend/scripts/gen-plugin-contract.mjs\`
 * بازتولید:    \`npm run gen:plugin-contract\`
 * بررسی drift: \`npm run gen:plugin-contract:check\`  ← روی drift غیرصفر می‌دهد
 *
 * اثر انگشت قرارداد: \`{FINGERPRINT}\`
 * (روی تغییر معنایی قرارداد عوض می‌شود؛ روی تغییر کامنت نه)
 */
`;

const PREAMBLE = `
export type PluginPointStatus = "live" | "declared_only" | "deferred" | "deprecated";

export type PluginPointOpenness = "closed" | "schema_defined" | "open_vocabulary";

export interface PluginFieldSpec {
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
}

export interface PluginAllowedPath {
  path: string;
  label_fa: string;
  label_en?: string;
}

export interface PluginErrorCodeGroup {
  label_fa: string;
  label_en: string;
  remedy_fa: string;
  remedy_en: string;
}

export interface PluginApiExample {
  id: string;
  title_fa: string;
  title_en: string;
  method: string;
  path: string;
  request: unknown;
  response: unknown;
  note_fa: string;
  note_en: string;
}

export interface PluginBadExample {
  why?: string;
  decl: Record<string, unknown>;
}

export interface PluginExtensionPoint {
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
  /**
   * فقط برای نقطه‌های منسوخ: نسخه‌ای که منسوخ شدند، جایگزینشان چیست، و چرا.
   * نگهبان بالا اجازه نمی‌دهد حالت deprecated بدون replaced_by باشد.
   */
  deprecated?: { since: string; replaced_by: string; reason_fa: string };
  schema_version: number;
  max: { declarations: number; bytes: number; depth: number; properties: number };
  schema: { fields: Record<string, PluginFieldSpec>; cross: string[] };
  open_schema: boolean;
  example_ok: Record<string, unknown>[];
  example_bad: PluginBadExample[];
  forbidden: string[];
}

export interface PluginPackageContractData {
  contractFingerprint: string;
  source: string;
  package: {
    manifestName: string;
    backendRoot: string;
    frontendRoot: string;
    pluginNamespacePrefix: string;
  };
  limits: {
    maxZipBytes: number;
    maxFiles: number;
    maxUncompressedBytes: number;
    maxCompressionRatio: number;
  };
  allowedPaths: PluginAllowedPath[];
  forbidden: string[];
  reservedTypeNames: string[];
  overridableInterfaces: string[];
  grammar: {
    limits: Record<string, number>;
    types: string[];
  };
  deferredExtensionPoints: Record<string, string>;
  extensionPoints: PluginExtensionPoint[];
  errorCodeGroups: Record<string, PluginErrorCodeGroup>;
  errorCodes: Record<string, string>;
  apiExamples: PluginApiExample[];
}

export const PLUGIN_PACKAGE_CONTRACT: PluginPackageContractData = `;

function render(value, indent) {
  const pad = " ".repeat(indent);
  const padInner = " ".repeat(indent + 2);
  if (Array.isArray(value)) {
    if (value.length === 0) return "[]";
    if (value.every((v) => typeof v !== "object" || v === null)) {
      return `[\n${value.map((v) => `${padInner}${JSON.stringify(v)}`).join(",\n")}\n${pad}]`;
    }
    return `[\n${value
      .map((v) => `${padInner}${render(v, indent + 2)}`)
      .join(",\n")}\n${pad}]`;
  }
  if (value && typeof value === "object") {
    const keys = Object.keys(value);
    if (keys.length === 0) return "{}";
    const body = keys
      .map((k) => {
        const v = value[k];
        if (v === undefined) return null;
        return `${padInner}${/^[A-Za-z_$][A-Za-z0-9_$]*$/.test(k) ? k : JSON.stringify(k)}: ${render(v, indent + 2)}`;
      })
      .filter(Boolean)
      .join(",\n");
    return `{\n${body}\n${pad}}`;
  }
  return JSON.stringify(value) ?? "null";
}

function renderModule(contract, fingerprint) {
  const data = {
    contractFingerprint: fingerprint,
    source: DEFAULT_CONTRACT,
    package: contract.package,
    limits: contract.limits,
    allowedPaths: contract.allowedPaths,
    forbidden: contract.forbidden,
    reservedTypeNames: contract.reservedTypeNames,
    overridableInterfaces: contract.overridableInterfaces,
    grammar: contract.grammar,
    deferredExtensionPoints: contract.deferredExtensionPoints,
    extensionPoints: contract.extensionPoints,
    errorCodeGroups: contract.errorCodeGroups,
    errorCodes: contract.errorCodes,
    apiExamples: contract.apiExamples,
  };
  return `${HEADER.replace("{FINGERPRINT}", fingerprint)}${PREAMBLE}${render(data, 0)};\n`;
}

// ─── ۷. گزارش drift ────────────────────────────────────────────────────────

/**
 * نخستین جفتِ سه‌خطیِ یکسان در دو نسخه، از `i`/`j` به بعد.
 *
 * LCS حساب نمی‌کنیم: فایل تولیدی ساختارمند است و تغییرش موضعی. نگاشت
 * «کلید سه‌خطی ⇒ نخستین سطر» یک بار ساخته می‌شود و بعد هر ناحیه با
 * O(n) هم‌تراز می‌شود. کلید سه‌خطی لازم است تا سطرهای تکراری
 * (`],` یا `},`) هم‌ترازی کاذب نسازند.
 */
function resync(a, b, i, j) {
  const key = (lines, p) => `${lines[p]} ${lines[p + 1]} ${lines[p + 2]}`;
  const index = new Map();
  for (let q = j; q + 2 < b.length; q += 1) {
    const k = key(b, q);
    if (!index.has(k)) index.set(k, q);
  }
  for (let p = i; p + 2 < a.length; p += 1) {
    const q = index.get(key(a, p));
    if (q !== undefined) return [p, q];
  }
  return null;
}

const MAX_HUNKS = 20;
const MAX_HUNK_ROWS = 8;

function lineDiff(current, next) {
  const a = current.split("\n");
  const b = next.split("\n");
  const hunks = [];
  let i = 0;
  let j = 0;

  while (i < a.length || j < b.length) {
    if (i < a.length && j < b.length && a[i] === b[j]) {
      i += 1;
      j += 1;
      continue;
    }
    const point = resync(a, b, i, j);
    const endI = point ? point[0] : a.length;
    const endJ = point ? point[1] : b.length;
    hunks.push({ line: i + 1, removed: a.slice(i, endI), added: b.slice(j, endJ) });
    i = endI;
    j = endJ;
    if (!point) break;
  }

  if (hunks.length === 0) return "  (بدون تفاوت)";

  const totalRemoved = hunks.reduce((n, h) => n + h.removed.length, 0);
  const totalAdded = hunks.reduce((n, h) => n + h.added.length, 0);
  const out = [
    `  ${hunks.length} ناحیهٔ تغییر — ${totalRemoved} سطر حذف‌شده / ${totalAdded} سطر افزوده‌شده`,
  ];
  for (const hunk of hunks.slice(0, MAX_HUNKS)) {
    out.push(`  ── سطر ${hunk.line} ──`);
    for (const row of hunk.removed.slice(0, MAX_HUNK_ROWS)) out.push(`  - ${row}`);
    if (hunk.removed.length > MAX_HUNK_ROWS) out.push(`  - … ${hunk.removed.length - MAX_HUNK_ROWS} سطر دیگر`);
    for (const row of hunk.added.slice(0, MAX_HUNK_ROWS)) out.push(`  + ${row}`);
    if (hunk.added.length > MAX_HUNK_ROWS) out.push(`  + … ${hunk.added.length - MAX_HUNK_ROWS} سطر دیگر`);
  }
  if (hunks.length > MAX_HUNKS) out.push(`  … ${hunks.length - MAX_HUNKS} ناحیهٔ دیگر`);
  return out.join("\n");
}

// ─── ۸. CLI ─────────────────────────────────────────────────────────────────

function parseArgs(argv) {
  const opts = { check: false, contract: null, out: null };
  for (let i = 0; i < argv.length; i += 1) {
    const arg = argv[i];
    if (arg === "--check") opts.check = true;
    else if (arg === "--contract") opts.contract = argv[++i];
    else if (arg === "--out") opts.out = argv[++i];
    else if (arg === "--help" || arg === "-h") opts.help = true;
    else throw new ContractError(`آرگومان ناشناخته «${arg}»`);
  }
  return opts;
}

const HELP = `gen:plugin-contract — دادهٔ راهنمای پلاگین را از قرارداد هسته تولید می‌کند

  node scripts/gen-plugin-contract.mjs              نوشتن/به‌روزرسانی آر‌تیفکت
  node scripts/gen-plugin-contract.mjs --check      فقط گزارش drift (روی drift غیرصفر)

  --contract <مسار>   منبع دیگری برای قرارداد (برای تست؛ فقط با --check یا --out)
  --out <مسار>        مقصد دیگری برای آر‌تیفکت تولیدی
`;

function main() {
  const opts = parseArgs(process.argv.slice(2));
  if (opts.help) {
    process.stdout.write(HELP);
    return 0;
  }

  const contractPath = resolve(repoRoot, opts.contract ?? DEFAULT_CONTRACT);
  const outPath = resolve(repoRoot, opts.out ?? DEFAULT_OUT);
  const isOverridden = Boolean(opts.contract ?? opts.out);

  if (!existsSync(contractPath)) {
    console.error(`gen:plugin-contract — قرارداد پیدا نشد: ${contractPath}`);
    return 1;
  }
  if (!opts.check && isOverridden && !opts.out) {
    console.error(
      "gen:plugin-contract — نوشتن با --contract مجاز نیست مگر با --out. " +
        "وگرنه آر‌تیفکت واقعی از یک منبع موقت تولید می‌شد.",
    );
    return 1;
  }

  let contract;
  let generated;
  let fingerprint;
  try {
    contract = readContract(contractPath);
    fingerprint = createHash("sha256")
      .update(stableStringify(contract), "utf8")
      .digest("hex")
      .slice(0, 16);
    generated = renderModule(contract, fingerprint);
  } catch (error) {
    if (error instanceof ContractError) {
      console.error(`gen:plugin-contract — خطای تجزیهٔ قرارداد: ${error.message}`);
      return 1;
    }
    throw error;
  }

  const points = contract.extensionPoints;
  const summary = [
    `نقاط اتصال: ${points.length}`,
    `  live=${points.filter((p) => p.status === "live").length}`,
    `  declared_only=${points.filter((p) => p.status === "declared_only").length}`,
    `  deferred=${points.filter((p) => p.status === "deferred").length}`,
    `کلیدهای ممنوع: ${contract.forbidden.length}`,
    `مسیرهای مجاز: ${contract.allowedPaths.length}`,
    `اثر انگشت قرارداد: ${fingerprint}`,
  ].join("\n");

  if (opts.check) {
    if (!existsSync(outPath)) {
      console.error(
        "gen:plugin-contract:check — DRIFT: آر‌تیفکت تولیدی وجود ندارد.\n" +
          `  انتظار: ${outPath}\n` +
          "  درمان:  npm run gen:plugin-contract",
      );
      return 1;
    }
    const current = readFileSync(outPath, "utf8");
    if (current === generated) {
      console.log(`gen:plugin-contract:check — OK، آر‌تیفکت با قرارداد یکی است.\n${summary}`);
      return 0;
    }
    console.error(
      "gen:plugin-contract:check — DRIFT: آر‌تیفکت تولیدی با قرارداد یکی نیست.\n" +
        `  ${outPath}\n` +
        `${lineDiff(current, generated)}\n` +
        "  درمان: npm run gen:plugin-contract  (و آن را commit کن)",
    );
    return 1;
  }

  if (existsSync(outPath) && readFileSync(outPath, "utf8") === generated) {
    console.log(`gen:plugin-contract — به‌روز است، چیزی نوشته نشد.\n${summary}`);
    return 0;
  }
  mkdirSync(dirname(outPath), { recursive: true });
  writeFileSync(outPath, generated, "utf8");
  console.log(`gen:plugin-contract — نوشته شد: ${outPath}\n${summary}`);
  return 0;
}

process.exitCode = main();
