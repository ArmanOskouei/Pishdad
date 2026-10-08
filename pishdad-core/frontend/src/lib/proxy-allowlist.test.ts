import { test, beforeEach, afterEach } from "node:test";
import assert from "node:assert/strict";
import { checkPath } from "./proxy-allowlist.ts";

/**
 * K7.9 + L-B12 — ریشهٔ افزونه‌ها از مرورگر، اما فقط slugهای اعلام‌شده.
 *
 * ## چرا این تست وجود دارد
 *
 * پیش از K7.9 پنل مرکزی زیر `/api/sampleplug/…` سرو می‌شد — یک **ریشهٔ موازی**
 * کنار `v1`. حالا زیر catch-all افزونه‌ها می‌رود: `/api/v1/p/{slug}/…`.
 *
 * آن catch-all سطحِ عمومیِ dispatch است: **هر** بسته‌ای که روی دیسک نصب
 * باشد، و احراز هویت و مجوزش را خودش در مانیفست اعلام می‌کند. پروکسی
 * نمی‌تواند به آن اعلان اعتماد کند — پس فقط slugهایی باز می‌مانند که **build
 * صریحاً اعلام کرده** باشد.
 *
 * تست‌های فرانت **نمی‌توانستند** این را بگیرند: ۱۶۶ تست سبز بود در حالی که
 * یا پنل مرکزی در مرورگر کار نمی‌کرد، یا — اگر allowlist را سهل‌انگارانه باز
 * می‌کردیم — هر بستهٔ بازاری هم از مرورگر باز می‌شد. هیچ‌کدام خطا تولید
 * نمی‌کردند؛ فقط درخواست‌ها بی‌صدا شکست می‌خوردند.
 *
 * ## L-B12 — چرا دیگر `sampleplug` در این فایل نیست
 *
 * allowlist از `new Set(["sampleplug"])` به `NEXT_PUBLIC_BROWSER_PLUGIN_SLUGS` رفت.
 * یعنی هستهٔ منتشرشده دیگر نامِ افزونهٔ خصوصی را در بایند خود ندارد. پس این
 * تست هم دیگر فرض نمی‌کند کدام slug باز است — خودش آن را اعلام می‌کند و بعد
 * رفتار را می‌سنجد. نتیجه دو چیز را هم‌زمان اثبات می‌کند: (۱) بستهٔ اعلام‌شده
 * باز است، (۲) بستهٔ اعلام‌نشده بسته است.
 *
 * به این ترتیب تست برای slugِ دلخواه هم درست می‌ماند، نه فقط برای مرکز.
 */

const KEY = "NEXT_PUBLIC_BROWSER_PLUGIN_SLUGS";
let saved: string | undefined;

beforeEach(() => {
  saved = process.env[KEY];
});
afterEach(() => {
  if (saved === undefined) delete process.env[KEY];
  else process.env[KEY] = saved;
});

const ok = (raw: string[], why?: string) =>
  assert.equal(checkPath(raw).ok, true, why ?? `باید باز باشد: ${raw.join("/")}`);

const denied = (raw: string[], why?: string) =>
  assert.equal(checkPath(raw).ok, false, why ?? `باید بسته باشد: ${raw.join("/")}`);

/** یک بستهٔ خصوصیِ فرضی را اعلام می‌کند — هسته اسمی نمی‌داند، فقط build می‌داند. */
function declare(slugs: string) {
  process.env[KEY] = slugs;
}

test("بستهٔ اعلام‌شده از راه ریشهٔ v1 باز است", () => {
  declare("sampleplug,household");
  // ۳۹ فراخوانیِ `authed()` در صفحه‌های پنل از همین مسیر می‌آیند. بستنش یعنی
  // پنل در مرورگر کار نمی‌کند.
  ok(["v1", "p", "sampleplug", "clients"]);
  ok(["v1", "p", "sampleplug", "dashboard", "stats"]);
  ok(["v1", "p", "sampleplug", "review", "plugin", "12", "approve"]);
  ok(["v1", "p", "household", "anything"]);
});

test("ریشهٔ parallel دیگر وجود ندارد", () => {
  declare("sampleplug");
  // این همان چیزی است که K7.9 عوض کرد: یک سطح دسترسی موازی که هر چیزی
  // می‌توانست زیرش بنشیند. نگه‌داشتنش یعنی هر route بازاری هم باز است.
  denied(["sampleplug", "clients"]);
  denied(["sampleplug"]);
});

test("افزونه‌های بازاری از مرورگر بسته می‌مانند", () => {
  declare("sampleplug");
  denied(["v1", "p", "zarinpal", "callback"]);
  denied(["v1", "p", "blog", "posts"]);
});

test("در build عمومی، ریشهٔ افزونه از مرورگر کاملاً بسته است", () => {
  // این همان حالتِ نسخهٔ منتشرشدهٔ گیت‌هاب است: هیچ متغیر محیطی‌ای پر نیست،
  // پس پروکسی نباید ریشهٔ افزونه را باز کند. یک نصب عمومی که افزونهٔ
  // خصوصی ندارد، از این مسیر چیزی صدا نمی‌زند.
  delete process.env[KEY];
  denied(["v1", "p", "sampleplug", "clients"]);
  denied(["v1", "p", "anything", "at", "all"]);
});

test("slug ناقص یا غیرمجاز رد می‌شود", () => {
  declare("sampleplug");
  denied(["v1", "p"]);
  denied(["v1", "p", "SAMPLEPLUGIN", "clients"], "slug حساس به حروف است.");

  // `v1/p/sampleplug` **باز** است: ریشهٔ prefix افزونه. `PluginRouteTable`
  // برای slugی که هیچ route ریشه‌ای ندارد `null` می‌دهد و `PluginRouter`
  // ۴۰۴ فارسی می‌دهد. بستنش در پروکسی فقط خطا را از ۴۰۴ِ تمیز به
  // «دسترسی نیست» تبدیل می‌کرد — یعنی یک رفتار درست را مبهم می‌کردیم.
  ok(["v1", "p", "sampleplug"]);
});

test("ورودیِ بد در متغیر محیطی نادیده گرفته می‌شود (fail-closed)", () => {
  declare("  ,  ,../evil,Has-Caps,ok-1  ");
  // `../evil` می‌توانست الگوی matcher را دور بزند؛ `Has-Caps` با slug
  // معتبر نمی‌خواند. هر دو باید حذف شوند، نه اینکه باز شوند.
  denied(["v1", "p", "..", "evil"]);
  denied(["v1", "p", "Has-Caps", "x"]);
  ok(["v1", "p", "ok-1", "x"], "slug معتبرِ واقعی باید باز بماند");
});

test("مسیرهای داخلی همچنان بسته‌اند", () => {
  declare("sampleplug");
  denied(["v1", "internal", "heartbeat"]);
});
