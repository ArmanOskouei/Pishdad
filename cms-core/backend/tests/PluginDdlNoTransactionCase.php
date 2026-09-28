<?php

namespace Tests;

/**
 * K5.12 — پایهٔ تست‌های DDL که **تراکنشِ باز** نمی‌خواهند.
 *
 * ## چرا `RefreshDatabase` ندارد
 *
 * نقش با `CREATE ROLE` ساخته می‌شود و `CREATE ROLE` فقط با `COMMIT` واقعی
 * می‌شود. داخل تراکنشِ باز ساخته شود، تا `ROLLBACK` دیده نمی‌شود — و اتصالِ
 * جدا (که تراکنشِ خودش را دارد) اصلاً آن را نمی‌بیند.
 *
 * `PluginMigratorTest` (K5.6) جدولِ واقعی می‌سازد و با `Schema::hasTable()`
 * بررسیش می‌کند. جدولی که در تراکنشِ تست ساخته شود برای اتصالِ جدا نامرئی
 * است. پس این تست‌ها باید **بدون** تراکنش باشند و خودشان جدول‌ها را پاک کنند.
 *
 * این دو پایه نباید ادغام شوند: `RefreshDatabase` روی `PluginDdlTestCase` است و
 * subclassها نباید دوباره `use RefreshDatabase` کنند چون امضای
 * `afterRefreshingDatabase` ناسازگار می‌شود و خطا سرِ کلاسِ دیگری گزارش می‌شود.
 */
abstract class PluginDdlNoTransactionCase extends TestCase
{
    use EnsuresPluginDdlRole;
}
