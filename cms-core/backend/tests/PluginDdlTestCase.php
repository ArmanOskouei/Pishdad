<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * K5.12 — پایهٔ تست‌هایی که به **نقشِ DDL افزونه** نیاز دارند.
 *
 * ## ⚠️ چرا `RefreshDatabase` اینجاست، نه در subclassها
 *
 * نقش با `CREATE ROLE` ساخته می‌شود و `CREATE ROLE` فقط با `COMMIT` واقعی
 * می‌شود. یعنی اگر داخل تراکنشِ باز ساخته شود، تا `ROLLBACK` دیده نمی‌شود و
 * اتصالِ جدا (که تراکنشِ خودش را دارد) اصلاً آن را نمی‌بیند.
 *
 * این را با آزمایش کشف شد، نه با بازبینی کد: `PluginsTest` ۳۵ تست دارد و ۳۴ تای
 * آن‌ها با `migration.ddl_unavailable` قرمز می‌شدند — پیامی که دربارهٔ افزونه بود
 * در حالی که ریشه، نبودِ نقش بود.
 *
 * ## ⚠️ چرا `afterRefreshingDatabase` بدون `parent`
 *
 * `TestCase` این متد را ندارد — در trait `RefreshDatabase` است. پس `parent::`
 * روی آن `Call to undefined method Tests\TestCase::afterRefreshingDatabase()` می‌داد
 * و خطا سرِ کلاسِ فرزند گزارش می‌شد.
 *
 * نسخهٔ اول با `alias` حلش کرد، ولی آن‌طور alias می‌شد که متدِ اصلی دیگر صدا زده
 * نشود و `afterRefreshingDatabase` من **هرگز اجرا نمی‌شد** — نتیجه:
 * `permission denied for table plugin_table_prefixes`.
 *
 * پس راه درست: trait را `use` کن، متد را **بدون** `parent` بازنویسی کن. کاری که
 * نسخهٔ اصلی trait در این نقطه می‌کرد نداشتیم.
 *
 * این هوک **بعد** از `migrate:fresh` و **قبل** از `beginDatabaseTransaction`
 * اجرا می‌شود — همان پنجرهٔ لازم، چون `migrate:fresh` جدول‌ها را دوباره می‌سازد و
 * `relacl` را پاک می‌کند.
 */
abstract class PluginDdlTestCase extends TestCase
{
    use EnsuresPluginDdlRole;
    use RefreshDatabase;

    protected function afterRefreshingDatabase(): void
    {
        static::ensureDdlRoleExists();
    }
}
