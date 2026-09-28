import { test } from "node:test";
import assert from "node:assert/strict";
import { checkPath } from "./proxy-allowlist.ts";

/**
 * K7.9 — پنل مرکزی دیگر ریشهٔ مستقل ندارد، ولی هنوز از مرورگر کار می‌کند.
 *
 * ## چرا این تست وجود دارد
 *
 * پیش از K7.9 پنل مرکزی زیر `/api/central/…` سرو می‌شد — یک **ریشهٔ موازی**
 * کنار `v1`. حالا که مرکز یک افزونهٔ معمولی شد، زیر catch-all افزونه‌ها
 * می‌رود: `/api/v1/p/central/…`.
 *
 * آن catch-all سطحِ عمومیِ dispatch است: **هر** بسته‌ای که روی دیسک نصب
 * باشد، و احراز هویت و مجوزش را خودش در مانیفست اعلام می‌کند. پروکسی
 * نمی‌تواند به آن اعلان اعتماد کند — پس فقط slugهای داخلی باز می‌مانند.
 *
 * تست‌های فرانت **نمی‌توانستند** این را بگیرند: ۱۶۶ تست سبز بود در حالی که
 * یا پنل مرکزی در مرورگر کار نمی‌کرد، یا — اگر allowlist را سهل‌انگارانه
 * باز می‌کردیم — هر بستهٔ بازاری هم از مرورگر باز می‌شد. هیچ‌کدام خطا تولید
 * نمی‌کردند؛ فقط درخواست‌ها بی‌صدا شکست می‌خوردند.
 */

const ok = (raw: string[], why?: string) =>
  assert.equal(checkPath(raw).ok, true, why ?? `باید باز باشد: ${raw.join("/")}`);

const denied = (raw: string[], why?: string) =>
  assert.equal(checkPath(raw).ok, false, why ?? `باید بسته باشد: ${raw.join("/")}`);

test("پنل مرکزی از راه ریشهٔ v1 باز است", () => {
  // ۳۹ فراخوانیِ `authed()` در شش فایل از همین مسیر می‌آیند
  // (BillingClients, ClientsTable, OperatorClient, ReviewClient,
  //  UpdatesClient, ChabokanClient). بستنش یعنی پنل در مرورگر کار نمی‌کند.
  ok(["v1", "p", "central", "clients"]);
  ok(["v1", "p", "central", "dashboard", "stats"]);
  ok(["v1", "p", "central", "review", "plugin", "12", "approve"]);
  ok(["v1", "p", "central", "chabokan", "services", "site-app", "start"]);
});

test("ریشهٔ central دیگر وجود ندارد", () => {
  // این همان چیزی است که K7.9 عوض کرد: یک سطح دسترسی موازی که هر چیزی
  // می‌توانست زیرش بنشیند. نگه‌داشتنش یعنی هر route بازاری هم باز است.
  denied(["central", "clients"]);
  denied(["central"]);
});

test("افزونه‌های بازاری از مرورگر بسته می‌مانند", () => {
  denied(["v1", "p", "zarinpal", "callback"]);
  denied(["v1", "p", "blog", "posts"]);
});

test("slug ناقص یا غیرمجاز رد می‌شود", () => {
  denied(["v1", "p"]);
  denied(["v1", "p", "CENTRAL", "clients"], "slug حساس به حروف است.");

  // `v1/p/central` **باز** است: ریشهٔ prefix افزونه. `PluginRouteTable`
  // برای slugی که هیچ route ریشه‌ای ندارد `null` می‌دهد و `PluginRouter`
  // ۴۰۴ فارسی می‌دهد. بستنش در پروکسی فقط خطا را از ۴۰۴ِ تمیز به
  // «دسترسی نیست» تبدیل می‌کرد — یعنی یک رفتار درست را مبهم می‌کردیم.
  ok(["v1", "p", "central"]);
});

test("مسیرهای داخلی همچنان بسته‌اند", () => {
  denied(["v1", "internal", "heartbeat"]);
});

test("پنل مشتری مثل قبل کار می‌کند", () => {
  ok(["v1", "me"]);
  ok(["v1", "tickets"]);
  ok(["v1", "subscriptions", "status"]);
});
