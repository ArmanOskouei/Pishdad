#!/usr/bin/env node
/**
 * بازتولید بخش «## ایندکس وضعیت» در docs/TASKS.md از روی خودِ جدول‌ها.
 *
 * ## چرا این ابزار وجود دارد
 *
 * ایندکسِ وضعیت یک بخش **تولیدی** است و دستی ویرایش‌کردنش خطاست: کافی
 * است یک ردیف اضافه یا حذف شود تا شمارش و فهرست `id=status` غلط شود،
 * در حالی که خودِ جدول‌ها درست‌اند. `tasks-guard.mjs` این ناهماهنگی را
 * پیدا می‌کند ولی این اسکریپت آن را **درست** می‌کند.
 *
 * ## اجرا
 *
 *     node docs/tools/tasks-index.mjs           # بازتولید
 *     node docs/tools/tasks-index.mjs --check   # فقط بررسی، بدون نوشتن
 *
 * بعدش `tasks-guard.mjs` را اجرا کنید تا مطمئن شوید PASS است.
 *
 * @see tasks-guard.mjs
 */

import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const HERE = dirname(fileURLToPath(import.meta.url));
const CHECK_ONLY = process.argv.includes("--check");
// ‎⚠️ `--check` اول باید جدا شود، وگرنه به‌عنوان مسیر فایل تفسیر می‌شود.
const positional = process.argv.slice(2).filter((a) => !a.startsWith("--"));
const FILE = positional[0] ?? resolve(HERE, "..", "TASKS.md");

const CANON = "ID | کار | فایل/محل | یادداشت | وضعیت";
const TOKENS = ["done", "partial", "active", "pending", "waiting", "blocked", "deferred", "cancelled"];

/** ترتیب گروه‌ها در ایندکس — ثابت، تا خروجی بین اجراها نپرد. */
const ORDER = ["blocked", "active", "partial", "pending", "waiting", "deferred", "cancelled", "done"];

if (!existsSync(FILE)) {
  console.error(`tasks-index: no such file: ${FILE}`);
  process.exit(2);
}

const raw = readFileSync(FILE, "utf8");
const lines = raw.split(/\r?\n/);

/** ‎(?<!\\) باربر است — همان منطق guard. */
const splitRow = (l) => l.replace(/^\||\|$/g, "").split(/(?<!\\)\|/).map((c) => c.trim());
const isSep = (l) => /^\|[\s:\-|]+\|$/.test(l);
const cleanId = (s) => (s ?? "").replace(/^\*\*|\*\*$/g, "").replace(/^`|`$/g, "").trim();
const cleanToken = (s) => (s ?? "").replace(/[*_`]/g, "").trim().toLowerCase();

/* ── پیدا کردن جدول‌ها ─────────────────────────────────────────────────── */
const tables = [];
for (let i = 0; i < lines.length - 1; i++) {
  if (!lines[i].startsWith("|") || !isSep(lines[i + 1])) continue;
  let j = i + 2;
  const rows = [];
  while (j < lines.length && lines[j].startsWith("|") && !(j + 1 < lines.length && isSep(lines[j + 1]))) rows.push(j), j++;
  tables.push({ head: i, rows, hdr: splitRow(lines[i]) });
  i = j - 1;
}

/* ── بخشِ متناظر هر ردیف (برای ستون «بخش» ایندکس) ─────────────────────── */
const sectionOf = (head) => {
  // بالاترین `## ` قبل از سرِ جدول
  for (let k = head; k >= 0; k--) {
    const m = /^##\s+(.*)$/.exec(lines[k]);
    if (m) return m[1].trim();
  }
  return "";
};

/* ── جمع‌آوری همهٔ ردیف‌های تسک ────────────────────────────────────────── */
const tasks = [];
for (const t of tables) {
  if (t.hdr.join(" | ") !== CANON) continue;
  const section = sectionOf(t.head);
  for (const r of t.rows) {
    const c = splitRow(lines[r]);
    const id = cleanId(c[0]);
    const st = cleanToken(c[4]);
    if (!id || !TOKENS.includes(st)) continue;
    tasks.push({ id, title: (c[1] ?? "").trim(), section, st, line: r + 1 });
  }
}

if (!tasks.length) {
  console.error("tasks-index: no task rows found — refusing to write an empty index");
  process.exit(1);
}

/* ── ساخت بدنهٔ ایندکس ────────────────────────────────────────────────── */
const counts = Object.fromEntries(ORDER.map((t) => [t, 0]));
for (const t of tasks) counts[t.st]++;

const summary = ORDER.filter((t) => counts[t] > 0)
  .map((t) => `\`${t}\` ${counts[t]}`)
  .join(" · ");

const out = [];
out.push("## ایندکس وضعیت");
out.push("");
out.push(`**${tasks.length} ردیف تسک.** ${summary}`);
out.push("");
out.push("| ID | کار | وضعیت | بخش |");
out.push("|---|---|---|---|");

for (const st of ORDER) {
  const inGroup = tasks.filter((t) => t.st === st);
  if (!inGroup.length) continue;
  out.push(`| **— ${st} (${inGroup.length}) —** | | | |`);
  for (const t of inGroup) {
    out.push(`| \`${t.id}\` | ${t.title} | \`${st}\` | ${t.section} |`);
  }
}

/* ── جای‌گذاری ────────────────────────────────────────────────────────── */
const idx = lines.findIndex((l) => l.startsWith("## ایندکس وضعیت"));
if (idx < 0) {
  console.error("tasks-index: no «## ایندکس وضعیت» section to replace");
  process.exit(1);
}

let end = idx + 1;
while (end < lines.length && !/^## /.test(lines[end])) end++;

const next = [...lines.slice(0, idx), ...out, "", ...lines.slice(end)];
const rendered = next.join("\n");

if (rendered === raw) {
  console.log(`tasks-index: already up to date (${tasks.length} rows)`);
  process.exit(0);
}

if (CHECK_ONLY) {
  console.error(`tasks-index: index is stale (${tasks.length} rows in the tables) — run without --check`);
  process.exit(1);
}

// ‎⚠️ خط‌ها را دست‌کاری نکن؛ فقط همان بازه را جایگزین کن تا CRLF/LF
// بقیهٔ فایل به هم نریزد.
writeFileSync(FILE, rendered, "utf8");

console.log(`tasks-index: rewrote ${end - idx} lines → ${out.length} lines (${tasks.length} rows)`);
for (const t of ORDER) if (counts[t]) console.log(`  ${t}: ${counts[t]}`);