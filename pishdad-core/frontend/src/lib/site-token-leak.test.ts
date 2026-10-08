import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync, statSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

/**
 * L-B7 / F0.8 دنباله — سایتِ عمومی نباید توکنِ پنل را ارث ببرد.
 *
 * نگهبانِ قبلی (`site-theme-vocabulary.test.ts`) دو چیز را می‌سنجد: نبودِ ارجاعِ
 * چرخه‌ای، و اینکه پایه داخلِ scope باشد. ولی **پوشش را نمی‌سنجد** — یعنی هر
 * توکنی که سایت مصرف می‌کند و در `.site` تعریف نشده، از آن تست رد می‌شد.
 *
 * و همان‌جا نشت زنده بود: سایتِ عمومی از قاعده‌های **پنل** استفاده می‌کند
 * (`.card`، `.card-pad`، `.btn-primary`، `.alert`، `.a-green`/`.a-red` در فرم
 * تماس)، پس `--shadow-lg` و `--space-4` و رنگ‌های معنایی هم از
 * `[data-direction]`ِ روی `<html>` به سایت می‌رسیدند — یعنی انتخابِ تمِ پنل،
 * ظاهرِ سایتِ مشتری را عوض می‌کرد. دقیقاً همان باگی که این دامنه ساخته شد تا
 * ببندد.
 *
 * این تست **دامنه** را می‌سنجد، نه یک فهرستِ دستی: یک قاعدهٔ تازه که از
 * `:root` ارث ببرد و به کلاسِ پنلی بنشیند، همین‌جا قرمز می‌شود.
 */

const HERE = dirname(fileURLToPath(import.meta.url));
const FRONT = resolve(HERE, "../..");
const read = (p: string) => readFileSync(resolve(FRONT, p), "utf8");

/** CSS بدون کامنت‌ها، و بدون تودرتویی (که در این فایل وجود ندارد). */
const CSS = read("src/app/globals.css").replace(/\/\*[\s\S]*?\*\//g, "");

type Rule = { sel: string; body: string };

/**
 * اسکنرِ قاعده‌ها. `exec` با الگوی سراسری روی بلوک‌های مجاور **هم‌پوشانی** دارد
 * (کاراکتر `}` پایانیِ قاعدهٔ قبل، شروعِ قاعدهٔ بعد را می‌بلعد) و یک قاعده را
 * بی‌سروصدا از دست می‌دهد — همان اشتباهی که اولین نسخهٔ این بررسی داشت و
 * `--site-*` را نیمه‌کاره نشان می‌داد. اینجا شمارندهٔ عمق داریم.
 */
function rules(): Rule[] {
  const out: Rule[] = [];
  let depth = 0;
  let sel = "";
  let body = "";
  for (const c of CSS) {
    if (c === "{") {
      sel = body.trim();
      body = "";
      depth++;
      continue;
    }
    if (c === "}") {
      if (depth === 1) out.push({ sel, body });
      depth--;
      body = "";
      continue;
    }
    if (depth === 0 && c === ";") {
      body = "";
      continue;
    }
    body += c;
  }
  return out;
}

const RULES = rules();

/** توکن‌هایی که یک مجموعه selector تعریف می‌کند. */
function defines(pred: (sel: string) => boolean): Set<string> {
  const out = new Set<string>();
  for (const r of RULES) {
    if (!pred(r.sel)) continue;
    for (const m of r.body.matchAll(/(--[a-z0-9-]+)\s*:/g)) out.add(m[1]!);
  }
  return out;
}

/** توکن‌هایی که یک مجموعه selector می‌خواند. */
function reads(pred: (sel: string) => boolean): Map<string, string> {
  const out = new Map<string, string>();
  for (const r of RULES) {
    if (!pred(r.sel)) continue;
    for (const m of r.body.matchAll(/var\(\s*(--[a-z0-9-]+)/g)) out.set(m[1]!, r.sel);
  }
  return out;
}

/** selector های چندمقداری، هر کدام جدا. */
const each = (sel: string): string[] =>
  sel
    .split(",")
    .map((s) => s.trim())
    .filter(Boolean);

/** قاعده‌ای که **همه‌ی** اجزایش با پیشوند داده‌شده شروع می‌شوند. */
const byPrefix = (prefix: string) => (sel: string) => each(sel).every((s) => s.startsWith(prefix));

const SITE_SCOPE = defines(byPrefix(".site"));
/** قابل‌تأمیل از پنل: `:root` و هر `[data-*]` که روی `<html>` می‌نشیند. */
const PANEL_SCOPE = defines((sel) => each(sel).some((s) => s === ":root" || /^\[data-(direction|theme|accent|radius|density|fontSize)/.test(s)));

/** کلاس‌هایی که سایتِ عمومی واقعاً رندر می‌کند (نه آنچه فقط تعریف شده). */
function siteRenderedClasses(): Set<string> {
  const out = new Set<string>();
  const files = [
    ...readdirSync(resolve(FRONT, "src/components/site")).map((f) => `src/components/site/${f}`),
    "src/app/[locale]/page.tsx",
    "src/app/[locale]/[...path]/page.tsx",
    "src/app/[locale]/search/page.tsx",
    "src/app/preview/page.tsx",
  ];
  for (const f of files) {
    const src = read(f);
    for (const m of src.matchAll(/className=(?:"([^"]*)"|\{`([^`]*)`\})/g)) {
      const raw = (m[1] ?? m[2] ?? "").replace(/\$\{[^}]*\}/g, " ");
      for (const c of raw.split(/\s+/)) if (/^[a-z][a-z0-9-]*$/.test(c)) out.add(c);
    }
  }
  return out;
}

test("سایتِ عمومی هیچ توکنِ پنلی را ارث نمی‌برد", () => {
  const consumed = new Map<string, string>([
    ...reads(byPrefix(".site")),
    ...[...siteRenderedClasses()].flatMap((c) => [...reads((s) => s === `.${c}`)]),
  ]);

  // `--theme-*` روی همان عنصر با `themeVars()` نوشته می‌شود؛ از راهِ CSS تعریف
  // نمی‌شود و درست است که در دامنه نباشد.
  const leaked = [...consumed]
    .filter(([token]) => !SITE_SCOPE.has(token) && !token.startsWith("--theme-"))
    .sort();

  assert.deepEqual(
    leaked.map(([token, from]) => `${token} ← ${from}`),
    [],
    `این توکن‌ها را سایتِ عمومی می‌خواند ولی در scopeِ خودش تعریف نشده‌اند ⇒ از پنل ارث می‌برند:\n  ${leaked
      .map(([t, f]) => `${t} (${f}${PANEL_SCOPE.has(t) ? " — و پنل بازنویسی‌اش می‌کند، پس نشتِ قابل‌دیدن است" : ""})`)
      .join("\n  ")}`,
  );
});

test("واژگانِ قالبِ سایت (ROLES) هنوز کامل نگاشت می‌شود", () => {
  // برعکسِ تست بالا: اگر نقشی از قالب بیاید ولی در `.site` نگاشت نشود، سایت
  // رنگِ خودِ قالب را نمی‌بیند و بی‌سروصدا پایه را نشان می‌دهد (همان `F4.1.C`).
  const ROLES = [
    "primary", "primary-hover", "primary-soft",
    "accent", "accent-soft",
    "bg", "surface", "surface-2", "text", "text-muted", "border",
    "radius-sm", "radius-md", "radius-lg", "radius-xl",
    "fs-body", "fs-small", "fs-h", "density",
  ];
  const mapped = new Set(
    [...CSS.matchAll(/^\s{2}--([a-z0-9-]+):\s*var\(--theme-/gm)].map((m) => m[1]!),
  );
  const missing = ROLES.filter((r) => !mapped.has(r));
  assert.deepEqual(missing, [], "این نقش‌ها از قالب می‌آیند ولی scopeِ سایت آن‌ها را نمی‌خواند: " + missing.join("، "));
});