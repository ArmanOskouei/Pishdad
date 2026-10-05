<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| K7.6 — قیدِ یکتاییِ `site_id` روی جدولِ اشتراک.
|
| ## این مهاجرت چه چیزی را می‌بندد
|
| `2026_10_15_000001_installation_level_subscriptions` تصمیمِ «اشتراک ملکِ
| **نصب** است» را در **داده** اجرا کرد: هر ردیفِ زنده یک `site_id` گرفت و ردیف‌های
| تکراریِ یک نصب soft-delete شدند. ولی خودِ فایل صریحاً یادداشت کرد که
| «یکتایی فقط در **داده** است» و `TODO(K7.6)` را جا گذاشت — چون
| `CREATE INDEX` در PostgreSQL مالکیت می‌خواهد و migrationهای افزونه با نقشِ
| محدودِ `pishdad_plugin_ddl` اجرا می‌شوند.
|
| اینجا آن TODO بسته می‌شود: مهاجرت در `database/migrations` هسته است، پس با
| نقشِ هسته اجرا می‌شود و می‌تواند ایندکس بسازد.
|
| ## چرا ایندکسِ **جزئی** (`WHERE deleted_at IS NULL`)
|
| این تنها نکتهٔ فنیِ این مهاجرت است و اگر رعایت نشود، استقرار می‌شکند:
|
|  - مهاجرتِ داده فقط ردیف‌های **زنده** را جمع کرد. ردیف‌های soft-deleteشده با
|    ردیفِ زنده `site_id` یکسان **می‌مانند** — و باید بمانند، چون قاعدهٔ «هرگز
|    DROP» است و سابقهٔ مالی نباید پاک شود.
|  - یک ایندکسِ یکتای **سراسری** روی `site_id` پس از همان لحظه رد می‌شود با
|    `duplicate key value violates unique constraint`، چون آن ردیف‌های
|    soft-deleteشده عمداً تکراری‌اند.
|  - ایندکسِ جزئی فقط ردیف‌های **زنده** را یکتا می‌کند. یعنی قید دقیقاً همان چیزی
|    است که مدل معنا دارد: «یک نصب، یک اشتراکِ فعال».
|
| در PostgreSQL، NULL در ایندکسِ یکتا چند بار مجاز است، پس `site_id IS NULL`
| هم ایندکس را نمی‌شکند — و ردیفِ بدون کلید هم با مهاجرتِ داده ناسازگار نیست چون
| آن یکی `site_id` ساخته‌ای می‌گیرد.
|
| ## چرا `IF NOT EXISTS`
|
| استقرارِ دوباره روی دیتابیسی که این مهاجرت قبلاً رویش اجرا شده نباید بمیرد.
| `CREATE INDEX IF NOT EXISTS` در PostgreSQL از نسخهٔ ۹.۵ هست.
|
| ⛔ **چیزی DROP نمی‌شود**، حتی در `down()` غیر از همین ایندکس: قاعدهٔ «هرگز
| DROP» و یکتاییِ داده در خودِ migrationِ قبلی است، نه در ایندکس.
*/
return new class extends Migration
{
    private const TABLE = 'subscription_subscriptions';

    private const INDEX = 'subscription_subscriptions_site_id_live_unique';

    public function up(): void
    {
        if (! $this->ready()) {
            return;
        }

        DB::unprepared(sprintf(
            'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (site_id) WHERE deleted_at IS NULL',
            self::INDEX,
            self::TABLE
        ));
    }

    public function down(): void
    {
        if (! $this->ready()) {
            return;
        }

        // فقط همین ایندکس. اگر مهاجرت روی دیتابیسی اجرا شود که ایندکس را
        // ندارد، `DROP INDEX IF EXISTS` بی‌سروصدا رد می‌شود و `down()` هیچ‌کس را
        // غافلگیر نمی‌کند.
        DB::unprepared(sprintf('DROP INDEX IF EXISTS %s', self::INDEX));
    }

    /**
     * نصبِ عمومیِ بدون افزونهٔ اشتراک: جدول نیست و کل فایل باید بی‌سروصدا رد شود
     * — نه اینکه `php artisan migrate` را بشکند.
     *
     * `site_id` و `deleted_at` هر دو لازم‌اند: اولی قید، دومی شرطِ جزئی.
     * نصبِ نیمه‌کاره (جدول هست ولی ستون‌ها نه) همین‌طور رد می‌شود.
     */
    private function ready(): bool
    {
        return Schema::hasTable(self::TABLE)
            && Schema::hasColumn(self::TABLE, 'site_id')
            && Schema::hasColumn(self::TABLE, 'deleted_at');
    }
};
