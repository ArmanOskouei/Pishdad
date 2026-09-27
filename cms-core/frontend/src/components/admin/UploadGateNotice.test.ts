import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

import { gateNotice, type DevModeAvailability } from "../../lib/upload-gate.ts";

/**
 * K4.8 — دروازهٔ نصب بدون امضا.
 *
 * قاعده ساده است ولی جهت اشتباهش گران است: یا کاربر راهی را که ندارد می‌بیند،
 * یا راهی را که دارد نمی‌بیند و فکر می‌کند سیستم خراب است.
 */

const OFF: DevModeAvailability = { enabled: false, expiresAt: null, canUnlock: true };
const UNKNOWN: DevModeAvailability = { enabled: null, expiresAt: null, canUnlock: true };
const NO_PATH: DevModeAvailability = { enabled: false, expiresAt: null, canUnlock: false };

test("حالت توسعه‌دهندهٔ باز یعنی notice باز", () => {
  assert.equal(gateNotice({ ...OFF, enabled: true, expiresAt: "2026-09-28T20:00:00Z" }, false), "open");
});

test("حالت بسته ولی قابل‌بازکردن یعنی قفل با راهنما", () => {
  assert.equal(gateNotice(OFF, false), "locked");
});

test("نه باز و نه راهی ⇒ هیچ notice ای", () => {
  assert.equal(gateNotice(NO_PATH, false), "none");
});

test("اجازهٔ صریح سرور بر همه مقدم است", () => {
  // اگر سرور گفته بستهٔ بدون امضا مجاز است، notice «قفل» گفتن دروغ است.
  assert.equal(gateNotice(NO_PATH, true), "open");
});

test("هنوز خوانده‌نشده مثل بسته رفتار می‌کند", () => {
  assert.equal(gateNotice(UNKNOWN, false), "locked");
  // ولی تا وقتی نخوانده‌ایم نباید ادعا کنیم باز است.
  assert.notEqual(gateNotice(UNKNOWN, false), "open");
});

test("هیچ‌وقت راه دور زدن امضا را توضیح نمی‌دهد", () => {
  // راهنمای دور زدن دروازه، در دست کاربر بی‌دانا یعنی بدافزار. فقط مسیر رسمی
  // گفته می‌شود.
  const src = readFileSync(
    fileURLToPath(new URL("./UploadGateNotice.tsx", import.meta.url)),
    "utf8",
  );
  for (const leak of ["disable-signature", "unsigned=true", "skip_verify", "force_install"]) {
    assert.equal(src.includes(leak), false, `عبارت ${leak} نباید در راهنما باشد.`);
  }
});

test("notice قفل، مسیر رسمی را لینک می‌کند", () => {
  const src = readFileSync(
    fileURLToPath(new URL("./UploadGateNotice.tsx", import.meta.url)),
    "utf8",
  );
  assert.match(src, /<a href=\{unlockHref\}>/, "notice قفل باید لینک داشته باشد، نه فقط متن.");
});

test("notice باز، وضعیت را با role=status اعلام می‌کند", () => {
  const src = readFileSync(
    fileURLToPath(new URL("./UploadGateNotice.tsx", import.meta.url)),
    "utf8",
  );
  assert.match(src, /role="status"/, "تغییر وضعیت دروازه باید برای صفحه‌خوان اعلام شود.");
});
