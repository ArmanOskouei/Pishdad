#!/usr/bin/env node
/**
 * tasks-guard.mjs — fail CI when docs/TASKS.md drifts out of shape.
 *
 * Run:  node docs/tools/tasks-guard.mjs [path/to/TASKS.md]
 * Exit: 0 = clean, 1 = drift found, 2 = file missing.
 *
 * ── Why this exists ────────────────────────────────────────────────────────
 *
 * TASKS.md is the project's source of truth for what is done. It was
 * unreadable by machine for six months, and every bug below hid rows from
 * every parser without ever turning a test red:
 *
 * 1. Split on `|` is wrong. One note contains `curl \| bash`, and a naive
 *    split turns that 4-cell row into 5 — which then looks like a ragged row
 *    and poisons every column-position analysis. Always (?<!\\)\|.
 *
 * 2. A blank line ends a markdown table. Four row runs were orphaned that way
 *    and rendered as loose text. Thirteen of them (E2–E14, the whole
 *    "blocked on credentials" list) were invisible to any table-aware read.
 *
 * 3. A note containing a raw newline spills onto a bare line. That line does
 *    not start with `|`, so the table ends early and the rest of the row
 *    reads as prose.
 *
 * 4. The status column sat at index 1, 2 or 3 depending on the table, and
 *    often held prose instead of a status: `✅ تمام. PHP 8.3.33 → 8.3.35 …`,
 *    `⏸️`, `done (کاذب)`, `🟡 ۱ از ۴ — cache انجام شد`. Two passes over the
 *    same file disagreed about the same rows.
 *
 * The invariant this enforces: in every task table, `وضعیت` is the LAST column
 * and holds one bare token. Anything else is drift.
 *
 * Duplicates are reported, never merged. Two rows sharing an ID may be two
 * different tasks (B1 is both a header task and a K2 bug), and renumbering is
 * a decision for the owner, not for a linter.
 */

import { readFileSync, existsSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const HERE = dirname(fileURLToPath(import.meta.url));
const FILE = process.argv[2] ?? resolve(HERE, "..", "TASKS.md");

const CANON = "ID | کار | فایل/محل | یادداشت | وضعیت";
const TOKENS = ["done", "partial", "active", "pending", "waiting", "blocked", "deferred", "cancelled"];

if (!existsSync(FILE)) {
  console.error(`tasks-guard: no such file: ${FILE}`);
  process.exit(2);
}

const raw = readFileSync(FILE, "utf8");
const lines = raw.split(/\r?\n/);

/** (?<!\\) is load-bearing — see bug 1 in the header. */
const splitRow = (l) => l.replace(/^\||\|$/g, "").split(/(?<!\\)\|/).map((c) => c.trim());
const isSep = (l) => /^\|[\s:\-|]+\|$/.test(l);
const cleanId = (s) => (s ?? "").replace(/^\*\*|\*\*$/g, "").replace(/^`|`$/g, "").trim();
const isToken = (s) => TOKENS.includes((s ?? "").replace(/[*_`]/g, "").trim().toLowerCase());

const problems = [];
const notes = [];

/* ── encoding ──────────────────────────────────────────────────────────── */
const bad = (raw.match(/�/g) ?? []).length;
if (bad) problems.push(`${bad} U+FFFD replacement character(s) — the file is not valid UTF-8`);

/* ── walk the tables ──────────────────────────────────────────────────── */
const tables = [];
for (let i = 0; i < lines.length - 1; i++) {
  if (!lines[i].startsWith("|") || !isSep(lines[i + 1])) continue;
  let j = i + 2;
  const rows = [];
  // A table ends at a blank line, or at the next table's header — a pipe line
  // followed by a separator is a header, not a data row. Without this stop, two
  // adjacent tables fuse and the second one's header counts as data.
  while (j < lines.length && lines[j].startsWith("|") && !(j + 1 < lines.length && isSep(lines[j + 1]))) rows.push(j), j++;
  tables.push({ head: i, rows, hdr: splitRow(lines[i]) });
  i = j - 1;
}

/* ── 1. ragged rows ────────────────────────────────────────────────────── */
for (const t of tables) {
  for (const r of t.rows) {
    const n = splitRow(lines[r]).length;
    if (n !== t.hdr.length) {
      problems.push(`L${r + 1}: ${n} cells under a ${t.hdr.length}-column header [${t.hdr.join(" | ")}]`);
    }
  }
}

/* ── 2. orphan runs — pipe rows not inside any table (see bug 2) ────────── */
const inTable = new Array(lines.length).fill(false);
for (const t of tables) {
  inTable[t.head] = inTable[t.sepOf ?? t.head + 1] = true;
  t.rows.forEach((r) => (inTable[r] = true));
}
for (let i = 0; i < lines.length; i++) {
  if (!lines[i].startsWith("|") || inTable[i]) continue;
  let j = i;
  while (j < lines.length && lines[j].startsWith("|") && !inTable[j]) j++;
  problems.push(`L${i + 1}–L${j}: ${j - i} pipe row(s) not attached to any table (a blank line ended the table above)`);
  i = j - 1;
}

/* ── 3. every task row ends in a bare status token ─────────────────────── */
const byId = new Map();
/** "id=status" pairs taken from the tables — the ground truth for the index */
const bodyPairs = new Set();
let taskRows = 0;
for (const t of tables) {
  if (t.hdr.join(" | ") !== CANON) continue;
  for (const r of t.rows) {
    const c = splitRow(lines[r]);
    const id = cleanId(c[0]);
    const st = (c[4] ?? "").replace(/[*_`]/g, "").trim();
    taskRows++;
    if (!isToken(st)) {
      problems.push(`L${r + 1} [${id}]: last cell is not a status token → "${st.slice(0, 48)}" (expected one of ${TOKENS.join(", ")})`);
      continue;
    }
    if (!id) problems.push(`L${r + 1}: task row with an empty ID`);
    if (!byId.has(id)) byId.set(id, []);
    byId.get(id).push({ line: r + 1, st });
    bodyPairs.add(`${id}=${st}`);
  }
}

/* ── 4. duplicate IDs ─────────────────────────────────────────────────── */
const dups = [...byId.entries()].filter(([, v]) => v.length > 1);
for (const [id, v] of dups) {
  const statuses = new Set(v.map((x) => x.st));
  notes.push(
    `duplicate ID [${id}] x${v.length} at ${v.map((x) => `L${x.line}`).join(", ")}` +
      (statuses.size > 1 ? "  ⚠️ CONTRADICTORY STATUSES — needs an owner decision" : "  (same status, but two different tasks — do not merge)"),
  );
}

/* ── 5. the generated index must match the tables ─────────────────────── */
let indexPairs = 0;
const idx = lines.findIndex((l) => l.startsWith("## ایندکس وضعیت"));
if (idx < 0) {
  problems.push("no status index found (the «## ایندکس وضعیت» section)");
} else {
  let end = idx + 1;
  while (end < lines.length && !/^## /.test(lines[end])) end++;
  const idxText = lines.slice(idx, end).join("\n");
  // String.match() takes no flags argument — it would be silently dropped and
  // `^` would anchor to the start of the whole block instead of the line.
  const m = /^\*\*(\d+) ردیف تسک/m.exec(idxText);
  if (!m) {
    problems.push("the status index has no row count — regenerate it");
  } else if (Number(m[1]) !== taskRows) {
    problems.push(
      `the status index says ${m[1]} rows but the tables contain ${taskRows} — regenerate the index (it is generated, never hand-edited)`,
    );
  }

  // Row counts alone are not enough: someone can hand-edit one index row's
  // status and the count still matches. Compare every (id, status) pair.
  const HDR = "| ID | کار | وضعیت | بخش |";
  const idxHeader = lines.slice(idx, end).indexOf(HDR);
  if (idxHeader < 0) {
    problems.push("the status index has no table header — regenerate it");
  } else {
    const stale = [];
    let checkedPairs = 0;
    for (let k = idx + idxHeader + 2; k < end; k++) {
      const c = splitRow(lines[k]);
      if (c.length !== 4) continue;
      const id = cleanId(c[0]);
      const st = (c[2] ?? "").replace(/[*_`]/g, "").trim();
      if (!isToken(st)) continue; // group-separator row
      checkedPairs++;
      if (!bodyPairs.has(`${id}=${st}`)) stale.push(`${id}=${st} (L${k + 1})`);
    }
    if (stale.length) {
      problems.push(
        `${stale.length} index row(s) disagree with the tables: ${stale.slice(0, 6).join(", ")}${stale.length > 6 ? " …" : ""} — regenerate the index`,
      );
    }
    indexPairs = checkedPairs;
  }
}

/* ── report ────────────────────────────────────────────────────────────── */
console.log(`tasks-guard: ${FILE}`);
console.log(`  lines=${lines.length}  tables=${tables.length}  task rows=${taskRows}  index rows=${indexPairs}  duplicate IDs=${dups.length}`);

if (notes.length) {
  console.log(`\n  notes (not failures):`);
  notes.forEach((n) => console.log(`    · ${n}`));
}

if (problems.length) {
  console.error(`\n  FAIL — ${problems.length} problem(s):`);
  problems.forEach((p) => console.error(`    ✗ ${p}`));
  process.exit(1);
}

console.log("\n  PASS — every task row ends in a bare status token; no ragged or orphan rows.");
