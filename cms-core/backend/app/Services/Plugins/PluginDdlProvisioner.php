<?php

namespace App\Services\Plugins;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * K5.12 — ساخت و مدیریت نقش DDL افزونه، و پر کردن رجیستری.
 *
 * ## چرا این کلاس و چرا از راه مهاجرت نیست
 *
 * ساختِ نقش و نوشتنِ ACL **DDL سطح پایگاه‌داده** است و در `migrations` لاراول جای
 * درستی ندارد: آن دسته برای جدول‌های خودِ اپ است و در هر نصب تازه اجرا می‌شود،
 * در حالی که نقش باید **یک بار** ساخته شود و رمزش از نصب بیاید.
 *
 * ## مرزِ این کلاس
 *
 * این کلاس روی اتصال `cms` کار می‌کند و **superuser** است — یعنی می‌تواند هر کاری
 * بکند. همین‌طور که هست نباید در دسترس افزونه قرار بگیرد؛ فقط هسته صدایش می‌زند.
 */
final class PluginDdlProvisioner
{
    public function __construct(private PluginDdlConnection $connection) {}

    /**
     * ساخت نقش، اگر نبود. `LOGIN` با رمز — چون `SET ROLE` جداسازی نمی‌دهد.
     *
     * اگر نقش هست ولی رمزش با پیکربندی نمی‌خواند، **خودسرانه رمز را بازنشانی
     * نمی‌کند** و به‌جایش خطای صریح می‌دهد.
     *
     * نسخهٔ اول هشِ `pg_authid` را با `crypt()` می‌ساخت تا رمز را مقایسه کند — و
     * این به `pgcrypto` نیاز داشت که در نصب پیش‌فرض نیست. ولی این کار **لازم
     * نبود**: `PluginDdlConnection::isAvailable()` با یک اتصالِ واقعی رمز را
     * می‌سنجد، پس نیازی به دانستن هش نیست.
     *
     * و بازنشانیِ خودسرانه بدتر بود: هر بار که `.env` بازتولید می‌شد (که در
     * نصب تازه اتفاق می‌افتد) رمز دیتابیس عوض می‌شد. تغییر پنهانِ دیتابیس از
     * راه یک deploy، بدتر از خطای صریح است. برای همین `resetPassword()` یک
     * عملیات **جدا و عمدی** است.
     */
    public function ensureRole(): void
    {
        // ── ترتیب: اول امتیازها، بعد تصمیم دربارهٔ ساخت ────────────────
        //
        // ⚠️ این سه بار جابه‌جا شد و هر بار یک باگ واقعی ساخت.
        //
        // نسخهٔ اول: `if (roleExists() && isAvailable()) { grantMinimal(); return; }`
        // ولی `isAvailable()` فقط می‌پرسد «آیا می‌توانم **وصل** شوم؟» — و
        // وصل شدن ربطی به داشتنِ `SELECT` روی رجیستری ندارد. پس وقتی نقش بود
        // ولی امتیازهایش ناقص بود، `ensureRole` زودتر برمی‌گشت و **هرگز
        // `grantMinimal` صدا نمی‌زد**. نتیجه: `permission denied for table
        // plugin_table_prefixes` — دقیقاً چیزی که تست لو داد.
        //
        // راه درست: `grantMinimal()` **همیشه** اجرا شود، چون کارش «ترمیم» است نه
        // «اعطا». بعد تصمیم بگیریم ساخت لازم است یا نه.
        if ($this->roleExists() && $this->connection->isAvailable()) {
            return;
        }

        // فقط حالا که واقعاً باید **بسازیم**، تراکنش مانع است.
        //
        // `CREATE ROLE` فقط با `COMMIT` واقعی می‌شود و تا آن لحظه برای اتصالِ
        // جدا نامرئی است. این را با آزمایش کشف شد، نه با بازبینی کد.
        //
        // ⚠️ و راهِ «commit کردنِ تراکنش و باز کردنِ تراکنشِ تازه» **خودش یک
        // باگ** است: داده‌هایی که تست تا آن لحظه نوشته بود در تراکنشِ کنارگذاشته
        // می‌مانند و در تراکنشِ تازه ناپدید می‌شوند. نسخهٔ اول این کار را کرد و
        // ۳۵ تست `PluginsTest` را خراب کرد.
        if (DB::connection()->transactionLevel() > 0) {
            throw new \RuntimeException(
                'ساختِ نقش دیتابیس نمی‌تواند داخل تراکنشِ باز انجام شود — `CREATE ROLE`'
                .' با `COMMIT` واقعی می‌شود و تا آن لحظه برای اتصالِ جدا نامرئی است.'
                .' اگر این از یک تست می‌آید، نقش را در `setUpBeforeClass` بساز'
                .' (`PluginDdlTestCase`). اگر از مسیرِ درخواست می‌آید، یعنی'
                .' middleware جایی تراکنش باز کرده که نباید.'
            );
        }

        $this->ensureRoleInner();
    }

    private function ensureRoleInner(): void
    {
        if (! $this->roleExists()) {
            $password = $this->password();

            // اسمِ نقش در فرمول `CREATE ROLE` نمی‌شود پارامتر داد، ولی **رمز**
            // می‌شود. پس با `quoteLiteral` می‌آید.
            DB::unprepared(sprintf(
                'CREATE ROLE %s LOGIN PASSWORD %s NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION',
                self::roleName(),
                $this->quoteLiteral($password),
            ));

            $this->grantMinimal();

            return;
        }

        // ⚠️ هر دو حالت **نامِ متغیر** را می‌گویند. نسخهٔ اول در حالتِ «رمز نیست»
        // فقط می‌گفت «رمز عوض شده» که غلط بود — اگر رمز اصلاً تنظیم نشده باشد،
        // جملهٔ «اگر رمز عوض شده» مدیر را دنبالِ کار بی‌ربط می‌برد.
        if ($this->connection->isAvailable()) {
            $this->grantMinimal();

            return;
        }

        $password = config('database.connections.plugin_ddl.password');

        if (! is_string($password) || $password === '') {
            throw new \RuntimeException(
                'PLUGIN_DDL_PASSWORD در پیکربندی نیست. بدون آن نقش ساخته نمی‌شود —'
                .' عمداً، چون نقشی با رمز خالی یعنی بدون مرز. در `.env` مقدارش را بگذار.'
            );
        }

        throw new \RuntimeException(
            'نقش «'.self::roleName().'» وجود دارد ولی با رمزِ پیکربندی وصل نمی‌شود.'
            .' اگر رمز عوض شده، عمداً اجرا کن: php artisan plugin-ddl:reset-password'
        );
    }

    /**
     * بازنشانیِ **عمدی** رمز نقش.
     *
     * عملیاتِ جدا از `ensureRole()` است چون دیتابیس را تغییر می‌دهد و باید
     * انتخابِ کاربر باشد، نه اثر جانبیِ یک deploy.
     */
    public function resetPassword(): void
    {
        if (! $this->roleExists()) {
            $this->ensureRole();

            return;
        }

        DB::unprepared(sprintf(
            'ALTER ROLE %s PASSWORD %s',
            self::roleName(),
            $this->quoteLiteral($this->password()),
        ));

        $this->grantMinimal();
    }

    /**
     * فقط سه امتیاز. بقیه **ناخواسته** بسته می‌ماند چون نقش تازه با صفر امتیاز
     * شروع می‌شود.
     *
     * این سه کافی‌اند و بیشتر نیست:
     *  ۱. `CONNECT` — بدون این اصلاً وصل نمی‌شود.
     *  ۲. `USAGE`+`CREATE` روی شِما — برای ساخت جدول `{slug}_*`.
     *  ۳. `SELECT` روی رجیستری — نگهبان باید بداند چه نام‌هایی مجازند.
     *
     * عمداً `ALL TABLES` یا `CREATE` روی شِماهای دیگر داده نمی‌شود.
     *
     * ⚠️ **این متد DDL کاتالوگ است و داخل تراکنشِ باز کار نمی‌کند.**
     *
     * `REVOKE … ON ALL TABLES` قفلِ انحصاری روی `relacl` هر جدول می‌گیرد. یک
     * تراکنشِ بازِ تست قفلِ سطری روی همان جدول‌ها دارد، پس هر دو منتظرِ هم
     * می‌مانند. در عمل یعنی نصب ۱۵ دقیقه طول می‌کشد.
     *
     * این فقط محدودیتِ تست نیست: هر کدی که در تراکنشِ باز `grantMinimal` را
     * صدا بزند، همان قفل را می‌گیرد. پس فراخوانی باید بیرون از تراکنش باشد و
     * `PluginDdlTestCase` همین کار را می‌کند.
     *
     * ⚠️ و وظیفهٔ `revokeAll()` این است که دقیقاً همین‌ها را پس بگیرد. آن
     * فهرست قبلاً جدا نوشته شده بود و دو بار از قلم افتاد — یک بار `CONNECT` و
     * یک بار `SELECT` رجیستری — و هر بار `DROP ROLE` با
     * `Dependent objects still exist` شکست خورد.
     */
    public function grantMinimal(): void
    {
        $role = self::roleName();

        DB::unprepared(sprintf('GRANT CONNECT ON DATABASE %s TO %s', $this->quoteIdentifier($this->databaseName()), $role));
        DB::unprepared(sprintf('REVOKE ALL ON SCHEMA public FROM %s', $role));
        DB::unprepared(sprintf('GRANT USAGE, CREATE ON SCHEMA public TO %s', $role));
        DB::unprepared(sprintf('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM %s', $role));
        DB::unprepared('REVOKE ALL ON plugin_table_prefixes FROM PUBLIC');
        DB::unprepared(sprintf('GRANT SELECT ON plugin_table_prefixes TO %s', $role));

        // `ALTER DEFAULT PRIVILEGES` لازم نیست و نباید بود: دسترسی پیش‌فرض یعنی
        // دسترسی به جدول‌هایی که هنوز وجود ندارند، و هر جدول تازهٔ هسته هم
        // به افزونه داده می‌شد.
    }

    /**
     * پادزهرهای `grantMinimal()`.
     *
     * هر چیزی که آنجا داده می‌شود، اینجا پس می‌گیرد. فهرست قبلاً در دو جا جدا
     * نوشته شده بود و دو بار از قلم افتاد — یک بار `CONNECT` و یک بار `SELECT`
     * رجیستری — و هر بار `DROP ROLE` با `Dependent objects still exist` شکست
     * خورد.
     */
    private function revokeAll(): void
    {
        $role = self::roleName();

        DB::unprepared(sprintf(
            'REVOKE CONNECT ON DATABASE %s FROM %s',
            $this->quoteIdentifier($this->databaseName()),
            $role,
        ));
        DB::unprepared(sprintf('REVOKE ALL ON SCHEMA public FROM %s', $role));
        DB::unprepared(sprintf('REVOKE ALL ON plugin_table_prefixes FROM %s', $role));
    }

    /**
     * ثبت نام‌های نهاییِ مجاز برای یک slug.
     *
     * پیش از این، migration افزونه روی اتصال `cms` اجرا می‌شد، پس نگهبان
     * `current_user = cms` می‌دید و اصلاً وارد نمی‌شد. ترتیب اجرا عمداً این است:
     * رجیستری **اول** پر می‌شود، بعد migration، چون برعکسش یعنی قاعده‌ای که
     * دیرتر از نیازش آماده است.
     *
     * @param  array<int, string>  $declaredTables  نام‌های خام از `db.tables`
     */
    public function registerTables(string $slug, array $declaredTables): void
    {
        if ($declaredTables === []) {
            return;
        }

        $rows = [];
        $now = now();

        foreach ($declaredTables as $table) {
            $rows[] = [
                'slug' => $slug,
                'table_name' => PluginDbContract::prefixed($slug, $table),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // `upsert` نه `insert`: نصب دوبارهٔ همان نسخه نباید خطای یکتایی بدهد.
        DB::table('plugin_table_prefixes')->upsert($rows, ['slug', 'table_name'], ['updated_at']);
    }

    /**
     * پاک کردن رجیستری یک افزونه.
     *
     * فقط وقتی صدا زده می‌شود که جدول‌ها واقعاً حذف شده باشند — وگرنه رجیستری
     * خالی می‌شود در حالی که جدول‌ها مانده‌اند، و آن‌وقت افزونه هرگز نمی‌تواند
     * دوباره migrate شود ولی جدول‌هایش هستند.
     */
    public function unregisterTables(string $slug): void
    {
        DB::table('plugin_table_prefixes')->where('slug', $slug)->delete();
    }

    /**
     * آیا همهٔ نام‌های ثبت‌شده واقعاً روی دیسک هستند؟
     *
     * رجیستری می‌تواند از جدول جلو بیفتد (نصب نیمه‌کاره) یا عقب بماند (جدول
     * دستی حذف شده). حالت دوم خطرناک است: رجیستری نامی را مجاز نگه داشته که
     * افزونه می‌تواند دوباره بسازد و دادهٔ قبلی را بشکند.
     *
     * @return array<int, string> نام‌های ثبت‌شده‌ای که روی دیسک نیستند
     */
    public function danglingTables(string $slug): array
    {
        $registered = DB::table('plugin_table_prefixes')
            ->where('slug', $slug)
            ->pluck('table_name')
            ->all();

        if ($registered === []) {
            return [];
        }

        // `= any(?)` با یک آرایهٔ PHP کار نمی‌کند: PDO آن را به رشتهٔ
        // «Array» تبدیل می‌کند و خطای `Array to string conversion` می‌دهد.
        //
        // راه درست: `whereIn` لاراول. خودش bind جدا برای هر عضو می‌سازد و این
        // مسیر را بی‌نیاز از جزئیاتِ driver می‌کند. نسخهٔ اول `= any(?)` بود و در
        // تست قرمز شد.
        $existing = DB::table('pg_tables')
            ->where('schemaname', DB::raw('current_schema()'))
            ->whereIn('tablename', $registered)
            ->pluck('tablename')
            ->all();

        return array_values(array_diff($registered, $existing));
    }

    /**
     * حذف کامل نقش. برای uninstall و تست.
     *
     * اگر نقش نباشد، **کاری نمی‌کند** و خطا هم نمی‌دهد — چون `REVOKE` روی نقشِ
     * ناموجود با `Undefined object` شکست می‌خورد و این برای پاک‌سازیِ تست
     * بی‌معنی است. نسخهٔ اول همین‌جا خطا می‌داد و هر فراخوانیِ بی‌دلیل را
     * می‌ترکاند.
     */
    public function dropRole(): void
    {
        if (! $this->roleExists()) {
            return;
        }

        try {
            // ⚠️ قاعده: **هر امتیازی که `grantMinimal()` می‌دهد، `revokeAll()`
            // باید پس بگیرد.** فهرست قبلاً در دو جا جدا نوشته شده بود و دو بار
            // از قلم افتاد — یک بار `CONNECT` و یک بار `SELECT` رجیستری — و هر
            // بار `DROP ROLE` با `Dependent objects still exist` شکست خورد.
            $this->revokeAll();

            DB::unprepared(sprintf('DROP ROLE IF EXISTS %s', self::roleName()));
        } catch (Throwable $e) {
            // اگر جدولی هنوز مالکش باشد، `DROP ROLE` شکست می‌خورد. این باید
            // دیده شود نه اینکه در uninstall گم شود.
            Log::warning('plugin.ddl_role_drop_failed', [
                'role' => self::roleName(),
                'reason' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function roleExists(): bool
    {
        $row = DB::selectOne(
            'select 1 as ok from pg_roles where rolname = ?',
            [self::roleName()],
        );

        return $row !== null;
    }

    private static function roleName(): string
    {
        return PluginDdlConnection::ROLE;
    }

    private function databaseName(): string
    {
        $row = DB::selectOne('select current_database() as name');

        return (string) ($row->name ?? '');
    }

    private function password(): string
    {
        // از همان منبعی که اتصال می‌خواند — یک حقیقت، نه دو.
        return $this->connection->requirePassword();
    }

    /**
     * escape کردن رشته برای SQL با دوبل‌کردن تک‌گیومه — برای **مقدار**.
     *
     * استفاده از این برای شناسه (نام جدول/شِما/نقش) خطاست و PostgreSQL آن را
     * نمی‌پذیرد: `GRANT CONNECT ON DATABASE 'cms'` خطای نحوی می‌دهد چون
     * نامِ دیتابیس شناسه است نه رشته. نسخهٔ اول همین‌جا اشتباه بود و در
     * دیتابیسِ تست لو رفت.
     */
    private function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /** escape کردن شناسه با دوبل‌کردن گیومهٔ دوتایی — برای **نام**. */
    private function quoteIdentifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
}
