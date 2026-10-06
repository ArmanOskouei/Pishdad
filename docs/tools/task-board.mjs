#!/usr/bin/env node
/**
 * task-board.mjs — تختهٔ تسک‌های دسته‌بندی‌شده برای شروع سشن.
 *
 * ## چرا این ابزار وجود دارد
 *
 * `TASKS.md` منبع حقیقت است ولی برای «امروز چه کاری انجام دهم؟» ساخته نشده:
 * ۴۳۲ ردیف، بدون دسته‌بندیِ «پنل مدیریت / بک‌اند / فرانت‌اند سایت» و بدون
 * ترتیب اولویت. این اسکریپت همان ردیف‌ها را با **همان قرارداد پارسِ
 * `tasks-guard.mjs`** می‌خواند (پس دو پارسر هرگز ناهم‌سو نمی‌شوند)، به سه
 * دسته نگاشت می‌کند و یک جدولِ کاملِ آمادهٔ نمایش می‌نویسد.
 *
 * ## اجرا
 *
 *     node docs/tools/task-board.mjs                          # خلاصهٔ هر سه دسته
 *     node docs/tools/task-board.mjs --category=admin         # تختهٔ پنل مدیریت
 *     node docs/tools/task-board.mjs --category=backend --all # همهٔ ردیف‌ها، حتی done
 *     node docs/tools/task-board.mjs --category=frontend --status=pending,partial
 *
 * خروجیِ فایل پیش‌فرض: `.local/session/board-<category>.md` (gitignore است).
 * خلاصهٔ stdout فقط ASCII است تا در کنسول ویندوز به‌هم نریزد؛ متنِ فارسی فقط
 * داخل فایل UTF-8 می‌رود و ایجنت آن را با ابزار read می‌خواند.
 *
 * @see tasks-guard.mjs  — قرارداد پارس و اعتبارسنجی
 * @see tasks-index.mjs  — تولید ایندکس وضعیت
 */

import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, "..", "..");

const positional = process.argv.slice(2).filter((a) => !a.startsWith("--"));
const FILE = positional[0] ?? resolve(HERE, "..", "TASKS.md");

const arg = (name, def = null) => {
  const p = process.argv.find((a) => a.startsWith(`--${name}=`));
  return p ? p.slice(name.length + 3) : def;
};
const flag = (name) => process.argv.includes(`--${name}`);

const CATEGORY = (arg("category", "all") ?? "all").toLowerCase();
const ALL = flag("all");
const FULL = !flag("brief"); // پیش‌فرض: توضیحات کامل
const JSON_OUT = flag("json");
const STATUS_ARG = arg("status", null);

const CATS = ["admin", "backend", "frontend", "other"];
const CAT_LABEL = {
  admin: "پنل مدیریت",
  backend: "بک‌اند",
  frontend: "فرانت‌اند سایت",
  other: "سایر / زیرساخت",
};

/** ترتیب اولویتِ اجرا؛ عددِ کمتر = زودتر. */
const SEVERITY = { critical: 1, high: 2, medium: 3, low: 4, unknown: 3 };
const STATUS_RANK = {
  partial: 1,
  active: 2,
  pending: 3,
  waiting: 4,
  blocked: 5,
  deferred: 6,
  cancelled: 8,
  done: 9,
};
const TOKENS = ["done", "partial", "active", "pending", "waiting", "blocked", "deferred", "cancelled"];
const ACTIONABLE = ["partial", "active", "pending", "waiting", "blocked", "deferred"];

/* ── همان منطق پارسِ tasks-guard ────────────────────────────────────────── */
const CANON = "ID | کار | فایل/محل | یادداشت | وضعیت";
if (!existsSync(FILE)) {
  console.error(`task-board: no such file: ${FILE}`);
  process.exit(2);
}
const raw = readFileSync(FILE, "utf8");
const lines = raw.split(/\r?\n/);
const splitRow = (l) => l.replace(/^\||\|$/g, "").split(/(?<!\\)\|/).map((c) => c.trim());
const isSep = (l) => /^\|[\s:\-|]+\|$/.test(l);
const cleanId = (s) => (s ?? "").replace(/^\*\*|\*\*$/g, "").replace(/^`|`$/g, "").trim();
const cleanSt = (s) => (s ?? "").replace(/[*_`]/g, "").trim().toLowerCase();
const unescape = (s) => (s ?? "").replace(/\\\|/g, "|");

/** نزدیک‌ترین سرفصلِ هر سطح + نزدیک‌ترین `##` (برای ستون «بخش»). */
const headingOf = (head) => {
  let h = "";
  let parent = "";
  for (let k = head; k >= 0; k--) {
    if (!parent) {
      const m2 = /^##\s+(.*)$/.exec(lines[k]);
      if (m2) parent = m2[1].trim();
    }
    const m = /^#{1,6}\s+(.*)$/.exec(lines[k]);
    if (m) {
      h = m[1].trim();
      break;
    }
  }
  return { heading: h, parent };
};

const severityOf = (heading) => {
  const t = (heading ?? "").toLowerCase();
  if (/critical|بحرانی/.test(t)) return "critical";
  if (/high|بالا/.test(t)) return "high";
  if (/medium|متوسط/.test(t)) return "medium";
  if (/low|پایین/.test(t)) return "low";
  return "unknown";
};

/* ── دسته‌بندی ─────────────────────────────────────────────────────────── */
const RE_ADMIN =
  /(^|[\s`/\\·,;:([\]])admin([/\\`\s,;:)\]·]|$)|Api\/V1\/Admin|Components\/Admin|پنل\s*مدیریت|پنل|داشبورد|admin\//i;
const RE_BACKEND =
  /\.php\b|\bApi\/V1\/|(^|[\s`/\\·(])app\/|\bServices\/|\bModels?\/|\bdatabase\/|migration|\bconfig\/|\broutes\/|\bControllers?\/|\bMiddleware\b|\bObservers?\b|\bProviders?\b|بک[\s-]?اند|دیتابیس|هسته/i;
const RE_FRONTEND =
  /\[locale\]|components\/site|\bsrc\/themes|\bsrc\/app|\blib\/|\.tsx\b|\.css\b|next\.config|فرانت|سایت\s*عمومی|ThemeShell|BlockRenderer|i18n|seo/i;

const categorize = (title, loc, heading) => {
  // ابتدا محلِ فایل؛ عنوان فقط وقتی محل خالی است — تا کلمه‌ای مثل «پنل» در
  // عنوانِ یک تسکِ زیرساختی، آن را اشتباهی «پنل مدیریت» نکند.
  const hay = loc.trim() ? loc : `${title} ${heading}`;
  if (RE_ADMIN.test(hay)) return "admin";
  if (RE_BACKEND.test(hay)) return "backend";
  if (RE_FRONTEND.test(hay)) return "frontend";
  return "other";
};

/* ── جمع‌آوری ردیف‌ها ───────────────────────────────────────────────────── */
const rows = [];
for (let i = 0; i < lines.length - 1; i++) {
  if (!lines[i].startsWith("|") || !isSep(lines[i + 1])) continue;
  let j = i + 2;
  const rr = [];
  while (j < lines.length && lines[j].startsWith("|") && !(j + 1 < lines.length && isSep(lines[j + 1]))) rr.push(j), j++;
  if (splitRow(lines[i]).join(" | ") !== CANON) {
    i = j - 1;
    continue;
  }
  const { heading, parent } = headingOf(i);
  for (const r of rr) {
    const c = splitRow(lines[r]);
    const id = cleanId(c[0]);
    const st = cleanSt(c[4]);
    if (!id || !TOKENS.includes(st)) continue;
    const title = c[1];
    const loc = c[2];
    const note = c[3];
    rows.push({
      id,
      title,
      loc,
      note,
      st,
      section: parent || heading,
      heading,
      severity: severityOf(heading),
      category: categorize(title, loc, heading),
      line: r + 1,
    });
  }
  i = j - 1;
}

/* ── فیلترها ───────────────────────────────────────────────────────────── */
const statusFilter = STATUS_ARG
  ? new Set(STATUS_ARG.split(",").map((s) => s.trim().toLowerCase()))
  : ALL
    ? new Set(TOKENS)
    : new Set(ACTIONABLE);

const visible = rows
  .filter((r) => (CATEGORY === "all" ? true : r.category === CATEGORY))
  .filter((r) => statusFilter.has(r.st))
  .sort(
    (a, b) =>
      SEVERITY[a.severity] - SEVERITY[b.severity] ||
      STATUS_RANK[a.st] - STATUS_RANK[b.st] ||
      a.category.localeCompare(b.category) ||
      a.id.localeCompare(b.id),
  );

/* ── خلاصهٔ ASCII برای کنسول ────────────────────────────────────────────── */
const table = {};
for (const c of CATS) table[c] = { total: 0, actionable: 0, done: 0, cancelled: 0 };
for (const r of rows) {
  table[r.category].total++;
  if (r.st === "done") table[r.category].done++;
  else if (r.st === "cancelled") table[r.category].cancelled++;
  else table[r.category].actionable++;
}
const catTotal = { total: 0, actionable: 0, done: 0, cancelled: 0 };
for (const c of CATS) for (const k of ["total", "actionable", "done", "cancelled"]) catTotal[k] += table[c][k];

console.log(`task-board: ${FILE}`);
console.log(`  rows=${rows.length}  shown=${visible.length}  category=${CATEGORY}  statusFilter=${[...statusFilter].join(",")}`);
for (const c of CATS) {
  const t = table[c];
  console.log(`  ${c.padEnd(9)} total=${String(t.total).padStart(3)}  actionable=${String(t.actionable).padStart(3)}  done=${String(t.done).padStart(3)}  cancelled=${t.cancelled}`);
}
console.log(`  ${"ALL".padEnd(9)} total=${String(catTotal.total).padStart(3)}  actionable=${String(catTotal.actionable).padStart(3)}  done=${String(catTotal.done).padStart(3)}  cancelled=${catTotal.cancelled}`);

if (JSON_OUT) {
  process.stdout.write(
    JSON.stringify(
      {
        generatedAt: new Date().toISOString(),
        source: FILE,
        category: CATEGORY,
        counts: { byCategory: table, total: catTotal },
        tasks: visible.map((r) => ({
          ...r,
          title: unescape(r.title),
          loc: unescape(r.loc),
          note: unescape(r.note),
        })),
      },
      null,
      2,
    ),
  );
  process.exit(0);
}

/* ── نوشتن تختهٔ مارک‌داون ─────────────────────────────────────────────── */
const label = CATEGORY === "all" ? "همهٔ دسته‌ها" : CAT_LABEL[CATEGORY];
const byCat = CATEGORY === "all" ? CATS : [CATEGORY];
const out = [];
out.push(`# تختهٔ تسک — ${label}`);
out.push("");
out.push(`> تولیدشده: ${new Date().toISOString()} · منبع: \`docs/TASKS.md\``);
out.push(`> ${visible.length} ردیفِ نمایش‌داده‌شده · فیلتر وضعیت: \`${[...statusFilter].join(", ")}\``);
out.push("");
out.push("## شمارش");
out.push("");
out.push("| دسته | قابلِ‌اجرا | done | cancelled | مجموع |");
out.push("|---|---:|---:|---:|---:|");
for (const c of byCat) out.push(`| ${CAT_LABEL[c]} | ${table[c].actionable} | ${table[c].done} | ${table[c].cancelled} | ${table[c].total} |`);
out.push("");

for (const c of byCat) {
  const group = visible.filter((r) => r.category === c);
  out.push(`## ${CAT_LABEL[c]} — ${group.length} ردیف`);
  out.push("");
  if (!group.length) {
    out.push("_ردیفِ مطابقِ فیلتر در این دسته نیست._");
    out.push("");
    continue;
  }
  out.push("| اولویت | ID | کار | وضعیت | فایل/محل | توضیحات کامل |");
  out.push("|---|---|---|---|---|---|");
  let n = 0;
  for (const r of group) {
    out.push(
      `| ${++n} | \`${r.id}\` | ${r.title} | \`${r.st}\` | ${r.loc} | ${FULL ? r.note : r.note.slice(0, 140)} |`,
    );
  }
  out.push("");
}

const OUT = arg("out", resolve(ROOT, ".local", "session", `board-${CATEGORY}.md`));
mkdirSync(dirname(OUT), { recursive: true });
writeFileSync(OUT, out.join("\n"), "utf8");
console.log(`  wrote ${OUT}`);
