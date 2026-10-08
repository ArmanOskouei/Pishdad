import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

/**
 * K6.10 — کارت «ویجت‌های پلاگین» داشبورد حذف شد.
 *
 * دلیل: `GET /v1/admin/dashboard/widgets` آرایهٔ خالیِ **هاردکد** برمی‌گرداند
 * (DashboardController::widgets → `'widgets' => []`) و هیچ نویسنده‌ای در کد ندارد.
 * نقطهٔ `admin.dashboard_slot` در PluginPackageContract هم `deferred`/`closed`
 * است — یعنی اعلامش در مانیفست پلاگین تا وقتی renderer واقعی ساخته نشده **خطاست**.
 * پس کارت همیشه EmptyState نشان می‌داد و وعدهٔ «پلاگین‌ها اینجا ویجت اضافه می‌کنند»
 * دروغی بود که هیچ‌وقت نمی‌شد به آن عمل کرد.
 *
 * این تست مثل plugin-admin-path.test.ts قرارداد بک‌اند را تکرار می‌کند: اگر روزی
 * نقطهٔ قرارداد `live` شد، این تست عمداً می‌افتد تا نگهبان را دستی برداریم.
 */

const pageSource = readFileSync(
  fileURLToPath(new URL("../app/(client)/admin/dashboard/page.tsx", import.meta.url)),
  "utf8",
);

/**
 * توضیح‌های فارسیِ بالای صفحه اسمِ endpoint و متنِ کارتِ حذف‌شده را می‌آورند تا
 * دلیل حذف را ثبت کنند. پس قبل از سنجش، کامنت‌ها را برمی‌داریم تا نگهبان فقط
 * *کد زنده* را بسنجد، نه یادداشتی دربارهٔ کد.
 */
const pageCode = pageSource
  .replace(/\/\*[\s\S]*?\*\//g, "")
  .replace(/\/\/.*$/gm, "");

const contractSource = readFileSync(
  fileURLToPath(
    new URL("../../../backend/app/Services/Plugins/PluginPackageContract.php", import.meta.url),
  ),
  "utf8",
);

test("داشبورد endpoint ویجت‌ها را صدا نمی‌زند", () => {
  assert.equal(
    pageCode.includes("dashboard/widgets"),
    false,
    "داشبورد نباید /v1/admin/dashboard/widgets را بگیرد — کارت ویجت حذف شده است.",
  );
});

test("داشبورد کارت ویجت و متن وعده‌دهندهٔ آن را ندارد", () => {
  for (const dead of ["DashboardWidgets", "ویجت‌های پلاگین", "ویجتی ثبت نشده", "registry_version"]) {
    assert.equal(
      pageCode.includes(dead),
      false,
      `عبارت مرده در صفحهٔ داشبورد مانده است: ${dead}`,
    );
  }
});

test("نقطهٔ قرارداد admin.dashboard_slot هنوز deferred و closed است", () => {
  // `[\s\S]` به‌جای پرچم `s` — هدف tsc این پروژه es2018 نیست.
  const point = /'admin\.dashboard_slot'\s*=>\s*\[([\s\S]*?)\n {8}\],/.exec(contractSource);
  assert.ok(point, "نقطهٔ admin.dashboard_slot باید در قرارداد تعریف شده باشد.");

  assert.match(point[1], /'status'\s*=>\s*'deferred'/, "وضعیت باید deferred بماند.");
  assert.match(point[1], /'openness'\s*=>\s*'closed'/, "درجهٔ باز بودن باید closed بماند.");

  // قرارداد خودش می‌گوید نقطه نه primitive دارد نه مصرف‌کننده — پس ساختن کارت
  // بدون renderer، دروغ ساختاریافته است. اگر این جمله عوض شد تست باید دیده شود.
  assert.match(point[1], /'openness_why'\s*=>\s*'[^']*مصرف‌کننده[^']*'/);
});
