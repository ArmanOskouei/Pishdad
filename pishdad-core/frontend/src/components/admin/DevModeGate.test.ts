import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

// مسیر نسبی، نه `@/lib` — رانندهٔ تست `node --test` هیچ aliasی نمی‌شناسد.
import { timeRemaining } from "../../lib/devmode-time.ts";

/**
 * K4.9 — نمایش زمان باقی‌مانده.
 *
 * این تنها بخش قابل‌تستِ خالص این کامپوننت است، و دقیقاً همان‌جاست که اشتباه
 * کردنش کاربر را گمراه می‌کند.
 */

const SRC = fileURLToPath(new URL("./DevModeGate.tsx", import.meta.url));
const source = readFileSync(SRC, "utf8");

const NOW = Date.parse("2026-09-28T12:00:00Z");

test("بدون تاریخ انقضا چیزی نمایش داده نمی‌شود", () => {
  assert.equal(timeRemaining(null, NOW), null);
});

test("زمان گذشته نمایش داده نمی‌شود", () => {
  // «۰ دقیقه» یعنی همین الان منقضی شده — با «۸ ساعت دیگر» دو پیام کاملاً متفاوت.
  assert.equal(timeRemaining("2026-09-28T11:00:00Z", NOW), null);
});

test("تاریخ ناخوانا نمایش داده نمی‌شود", () => {
  assert.equal(timeRemaining("نیست-تاریخ", NOW), null);
});

test("دقیقهٔ باقی‌مانده درست حساب می‌شود", () => {
  assert.equal(timeRemaining("2026-09-28T12:45:00Z", NOW), "۴۵ دقیقه");
});

test("ساعت و دقیقه با هم نشان داده می‌شود", () => {
  assert.equal(timeRemaining("2026-09-28T19:30:00Z", NOW), "۷ ساعت و ۳۰ دقیقه");
});

test("یک دقیقهٔ مانده، «۱ دقیقه» است نه «۰ دقیقه»", () => {
  assert.equal(timeRemaining("2026-09-28T12:01:00Z", NOW), "۱ دقیقه");
});

test("کمتر از یک دقیقه، «کمتر از یک دقیقه» است نه «۰ دقیقه»", () => {
  // گرد کردن به پایین ۳۰ ثانیه را «۰ دقیقه» می‌کرد، یعنی کاربر «صفر» می‌دید و
  // فکر می‌کرد همین الان منقضی شده — درحالی که هنوز نیم دقیقه وقت هست.
  assert.equal(timeRemaining("2026-09-28T12:00:30Z", NOW), "کمتر از یک دقیقه");
  assert.equal(timeRemaining("2026-09-28T12:00:59Z", NOW), "کمتر از یک دقیقه");
});

test("ساعت صفر و دقیقهٔ صفر فقط وقتی رخ می‌دهد که هنوز ثانیه‌ای مانده باشد", () => {
  // مرز دقیق: ۶۰ ثانیه = «۱ دقیقه»، نه «کمتر از یک دقیقه».
  assert.equal(timeRemaining("2026-09-28T12:01:00Z", NOW), "۱ دقیقه");
});

// ── نگهبان‌های ساختاری: این‌ها قاعده‌هایی‌اند که رعایتشان اجباری است ──────────

test("هیچ fetch یا CDN خارجی در این کامپوننت نیست", () => {
  // قانون پروژه: فونت و اسکریپت هرگز از CDN خارجی نمی‌آید.
  assert.equal(/https?:\/\/(?!localhost)/.test(source), false, "ارجاع بیرونی در این کامپوننت نباید باشد.");
});

test("بارگذاری وضعیت در useEffect است، نه در بدنهٔ render", () => {
  // فراخوانی در render باعث حلقهٔ رندر و خطای #301 می‌شود که قبلاً این پروژه را
  // از کار انداخته بود.
  assert.match(source, /useEffect\(\(\) => \{\s*void load\(\);/, "load باید داخل useEffect صدا زده شود.");
  assert.equal(
    /^\s{2}load\(\);/m.test(source),
    false,
    "فراخوانی مستقیم load در بدنهٔ کامپوننت یعنی حلقهٔ رندر."
  );
});

test("load با useCallback پایدار است تا وابستگی useEffect نپرسد", () => {
  assert.match(source, /const load = useCallback\(/, "load باید useCallback باشد.");
});

test("مودال رمز دارد و فقط با رمز باز می‌شود", () => {
  assert.match(source, /type="password"/, "ورودی باید رمز باشد — checkbox دروازه نیست.");
  assert.match(source, /autoComplete="current-password"/);
  assert.match(source, /aria-modal="true"/, "مودال باید برای صفحه‌خوان نقش دیالوگ داشته باشد.");
});

test("شمارنده برای صفحه‌خوان اعلام می‌شود", () => {
  assert.match(source, /aria-live="polite"/, "شمارندهٔ کلیک باید live region باشد.");
});

test("خطا با role=alert اعلام می‌شود", () => {
  assert.match(source, /role="alert"/);
});

test("برچسب مودال به ورودی وصل است", () => {
  // بدون htmlFor/id، صفحه‌خوان نمی‌فهمد کدام ورودی رمز است.
  assert.match(source, /htmlFor="devmode-password"/);
  assert.match(source, /id="devmode-password"/);
});

test("دکمهٔ باز کردن با رمز خالی فعال نیست", () => {
  assert.match(source, /disabled=\{busy \|\| password === ""\}/, "ارسال رمز خالی نباید ممکن باشد.");
});
