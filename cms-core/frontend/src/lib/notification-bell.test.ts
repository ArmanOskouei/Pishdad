import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

/**
 * K5.8 — نگهبان‌های زنگ اعلان.
 *
 * این‌ها روی **کد** نگاه می‌کنند، چون رندر در `node --test` ممکن نیست. هدف
 * قفل‌کردن قواعدی است که در بازترتیبی به‌سادگی از بین می‌روند.
 */

const ROOT = fileURLToPath(new URL("../", import.meta.url));
const read = (p: string) => readFileSync(ROOT + p, "utf8");

const BELL = read("components/admin/NotificationBell.tsx");
const TOPBAR = read("components/layout/Topbar.tsx");
const CSS = read("app/globals.css");
const CONTROLLER = readFileSync(
  fileURLToPath(
    new URL(
      "../../../backend/app/Http/Controllers/Api/V1/Admin/NotificationController.php",
      import.meta.url,
    ),
  ),
  "utf8",
);

test("زنگ در هدر سوار شده و فقط یکی است", () => {
  assert.match(TOPBAR, /NotificationBell/, "زنگ باید در Topbar باشد.");
  assert.equal(
    (TOPBAR.match(/<NotificationBell/g) ?? []).length,
    1,
    "فقط یک زنگ اعلان.",
  );
});

test("بارگذاری در useEffect است، نه شرط در بدنهٔ رندر", () => {
  // قانون پروژه: شرطِ فراخوانی در بدنهٔ رندر یعنی React #301.
  assert.match(BELL, /useEffect\(/, "بارگذاری باید در useEffect باشد.");
  assert.equal(
    /if\s*\(\s*!\s*\w+\s*\)\s*\{?\s*void\s+\w+\(/.test(BELL),
    false,
    "شرطِ فراخوانی در بدنهٔ رندر یعنی حلقهٔ رندر.",
  );
});

test("polling فقط وقتی drawer بسته است و timer پاک می‌شود", () => {
  // اگر در حال خواندن، polling ادامه پیدا کند، لیست زیر انگشت کاربر عوض
  // می‌شود. و اگر timer پاک نشود، بعد از هر باز کردن یک تایمر می‌ماند.
  assert.match(
    BELL,
    /if\s*\(open\)\s*return;/,
    "وقتی drawer باز است نباید polling کند.",
  );
  assert.match(BELL, /clearInterval\(timer\.current\)/, "timer باید پاک شود.");
  assert.match(
    BELL,
    /setInterval/,
    "polling باید با setInterval باشد — الگوی همین پروژه.",
  );
});

test("فاصلهٔ polling گیت‌شدنی است و حداقل ۳۰ ثانیه", () => {
  // polling پرتکرار یعنی هر مدیر چند بار در ساعت یک درخواست بی‌دلیل. عدد زیر
  // ۳۰ ثانیه عملاً یک بار در هر بازدید صفحه است.
  const m = /const POLL_MS = ([\d_]+);/.exec(BELL);
  assert.ok(m, "POLL_MS باید تعریف‌شده باشد.");
  const ms = Number(m![1]!.replace(/_/g, ""));
  assert.ok(
    ms >= 30_000,
    `فاصلهٔ polling نباید زیر ۳۰ ثانیه باشد (الان ${ms}ms است).`,
  );
});

test("زنگ هیچ URL حدسی نمی‌سازد", () => {
  // `notification_id` فقط وقتی مسیر می‌شود که آن مسیر در `pageRegistry()`
  // ثبت شده باشد. این کامپوننت رجیستری را ندارد، پس ساختن لینک با URL
  // حدسی دقیقاً همان کاری است که این سرویس برای جلوگیری از آن ساخته شد.
  assert.equal(
    /router\.push|href=\{/.test(BELL),
    false,
    "زنگ نباید لینک بسازد.",
  );
  assert.match(BELL, /notification_id/, "فیلد را می‌خواند ولی به مسیر تبدیلش نمی‌کند.");
});

test("شمار خوانده‌نشده با ویژگی‌های منطقی می‌نشیند", () => {
  // `right` در RTL یعنی چپ. یعنی تعویض جهت، نشانگر را جابه‌جا می‌کند.
  const seg = CSS.slice(CSS.indexOf(".bell-count"));
  assert.match(seg, /inset-inline-end/, "نشانگر باید منطقی باشد.");
  assert.equal(
    /(^|[\s;])right\s*:/.test(seg.slice(0, seg.indexOf("}"))),
    false,
    "نباید از ویژگی فیزیکی استفاده شود.",
  );
  assert.match(CSS, /--danger/, "رنگ نشانگر باید از توکن بیاید نه مقدار ثابت.",
  );
});

test("کنترلر بک‌اند مقصد اعلان را تعیین نمی‌کند", () => {
  // اعلان فقط برای خودِ فراخوان است. اگر `user_id` از ورودی خوانده شود،
  // افزونه می‌تواند برای مدیر دیگر پیام جعلی بفرستد.
  const fn = CONTROLLER.slice(CONTROLLER.indexOf("public function store"));
  const body = fn.slice(0, fn.indexOf("public function readAll"));
  assert.equal(
    /request->input\(['"]user_id|['"]user_id['"]\s*=>/.test(body),
    false,
    "ورودی نباید بتواند مقصد اعلان را تعیین کند.",
  );
  assert.match(body, /\$request->user\(\)/, "مقصد باید کاربر جاری باشد.");
});

test("پاک‌سازی فقط اعلان‌های خوانده‌شده را حذف می‌کند", () => {
  // اعلان خوانده‌نشده یعنی کاربر هنوز ندیده. حذفش یعنی پیامی که هرگز نرسید.
  const fn = CONTROLLER.slice(CONTROLLER.indexOf("private function prune"));
  assert.match(
    fn,
    /whereNotNull\('read_at'\)/,
    "فقط خوانده‌شده‌ها پاک شوند.",
  );
});

test("پاک‌سازی در مسیر خواندن است، نه command جدا", () => {
  // یک command یعنی نیاز به cron که در این پروژه تضمین‌شده نیست، و آن وقت
  // جدول باز هم بی‌مهار رشد می‌کند.
  assert.match(
    CONTROLLER,
    /public function index[\s\S]{0,900}?\$this->prune/,
    "index باید پاک‌سازی را صدا بزند.",
  );
});
