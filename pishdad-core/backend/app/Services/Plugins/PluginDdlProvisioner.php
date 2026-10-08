<?php

namespace App\Services\Plugins;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
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
        // ولی امتیازهایش ناقص بود، `ensureRole` زودتر برمی‌گشت و **هرگز**
        // `grantMinimal` صدا نمی‌زد**. نتیجه: `permission denied for table
        // plugin_table_prefixes` — دقیقاً چیزی که تست لو داد.
        //
        // راه درست: `grantMinimal()` **همیشه** اجرا شود، چون کارش «ترمیم» است نه
        // «اعطا». بعد تصمیم بگیریم ساخت لازم است یا نه.
        //
        // ⚠️ **J5 — این «همیشه» دوباره برگشت، چون بدونش ۸ تست می‌مردند.**
        //
        // چرا اول پس گرفته شد: `PluginController::activate()` نوشته بود که ترمیمِ
        // امتیازها کارِ `plugin-ddl:reset-password` است، «نه اثر جانبیِ هر
        // فعال‌سازی» — و درست می‌گفت، چون `REVOKE … ON ALL TABLES` قفلِ انحصاری
        // روی `relacl`ِ تک‌تکِ جدول‌های هسته می‌گیرد و روی mountی که گزارش شده
        // نصب را ۱۵ دقیقه طول کشیده بود.
        //
        // ولی همان guard یک راهِ فرار ساخت که امتیازها هرگز ترمیم نمی‌شدند:
        // `dropRole()` (پاک‌سازیِ تست و uninstall) `revokeAll()` را صدا می‌زند که
        // امتیازِ نقش را از **همهٔ** دیتابیس‌ها می‌تراشد — از جمله رجیستری. بعد
        // نقش دوباره ساخته می‌شد ولی `roleExists()` true بود و `isAvailable()`
        // هم true بود، پس `ensureRole()` زودتر برمی‌گشت و `grantMinimal()` صدا
        // نمی‌شد. وضعیتِ نهایی: **نقش هست، وصل می‌شود، ولی رجیستری را نمی‌تواند
        // بخواند** — یعنی همان `permission denied for table plugin_table_prefixes`
        // که `PluginPrefixGuardTest` و `PluginDdlProvisioningFlowTest` را قرمز کرد.
        //
        // آن وضعیت «نیمه‌سالم» خطرناک‌ترین حالت است: نقش موجود بود پس کسی فکر
        // نمی‌کرد مشکل از امتیاز باشد.
        //
        // راه درست: ترمیم، ولی **فقط وقتی امن است** — بیرون از تراکنش. داخل
        // تراکنش هم ریسکِ قفل دارد و هم اصلاً commit نمی‌شود، پس بی‌فایده است.
        // قاعدهٔ `PluginController` («فقط وجودِ نقش را چک کن») دست‌نخورده ماند؛
        // آنجا یک تصمیمِ درست دربارهٔ **مسیرِ درخواست** است و اینجا تصمیمِ درست
        // دربارهٔ **سلامتِ نقش**.
        if ($this->roleExists() && ! $this->inTransaction()) {
            $this->grantMinimal();
        }

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
        if ($this->inTransaction()) {
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

    /**
     * تراکنشِ باز هست یا نه — برای تصمیمِ «ترمیمِ امتیازها امن است؟».
     *
     * جدا از متد است چون **دو** شاخه به آن نیاز دارند و یکی از آن‌ها استثنای صریح
     * می‌دهد؛ با یک فراخوانی درون‌خطی یکی از دو یادش می‌رفت — که دقیقاً همان باگی
     * است که این سطرها دارند درستش می‌کنند.
     */
    private function inTransaction(): bool
    {
        return DB::connection()->transactionLevel() > 0;
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
     * فقط **دو** امتیاز. بقیه **ناخواسته** بسته می‌ماند چون نقش تازه با صفر امتیاز
     * شروع می‌شود.
     *
     *  ۱. `USAGE`+`CREATE` روی شِما — برای ساخت جدول `{slug}_*`.
     *  ۲. `SELECT` روی رجیستری — نگهبان باید بداند چه نام‌هایی مجازند.
     *
     * ⚠️ **`GRANT CONNECT` عمداً داده نمی‌شود.**
     *
     * `PUBLIC` در PostgreSQL به‌طور پیش‌فرض `CONNECT` دارد، پس نقش تازه بدونِ هیچ
     * امتیازی وصل می‌شود. دادنِ `CONNECT` صریح فقط یک مشکل می‌ساخت: امتیازی
     * **سطح سرور** که در `pg_database` زندگی می‌کند — یعنی هر جا این کد اجرا شود
     * روی هر دیتابیسی امتیاز می‌ماند، و `DROP ROLE` می‌شکند با
     * `Dependent objects still exist: privileges for database …`.
     *
     * پس‌گرفتنش هم سه نسخه شکست خورد تا رسید به `to_regrole`، چون
     * `has_database_privilege` با `PUBLIC` هم true است حتی وقتی امتیاز صریح
     * نداریم — یعنی فهرستِ دیتابیس‌ها هیچ‌وقت خالی نمی‌شد و `REVOKE` بی‌نهایت
     * تکرار می‌شد. راه درست: اصلاً نده.
     *
     * عمداً `ALL TABLES` یا `CREATE` روی شِماهای دیگر داده نمی‌شود.
     *
     * ⚠️ **این متد DDL کاتالوگ است و داخل تراکنشِ باز کار نمی‌کند.**
     *
     * `REVOKE … ON ALL TABLES` قفلِ انحصاری روی `relacl` هر جدول می‌گیرد. یک
     * تراکنشِ بازِ تست قفلِ سطری روی همان جدول‌ها دارد، پس هر دو منتظرِ هم
     * می‌مانند. در عمل یعنی نصب ۱۵ دقیقه طول می‌کشد.
     */
    public function grantMinimal(): void
    {
        $role = self::roleName();

        DB::unprepared(sprintf('REVOKE ALL ON SCHEMA public FROM %s', $role));
        DB::unprepared(sprintf('GRANT USAGE, CREATE ON SCHEMA public TO %s', $role));
        DB::unprepared(sprintf('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM %s', $role));

        // ⚠️ ترتیب این دو **همان ترتیبِ اصلی** است و عمداً دست‌نخورده ماند.
        //
        // وسوسه‌انگیز بود که `GRANT SELECT` رجیستری را اول بیاوریم تا خطای
        // `REVOKE ALL ON ALL TABLES` نتواند جلویش را بگیرد — و J5 دقیقاً همین را
        // امتحان کرد. نتیجه: **۸ تست قرمز شد.**
        //
        // علتش این بود که `REVOKE ALL ON ALL TABLES IN SCHEMA public` امتیازهای نقش
        // را از **هر** جدولِ شِما می‌تراشد — از جمله از رجیستری، اگر نقش در
        // `relacl`ِ آن باشد. پس با `GRANT` اول، این `REVOKE` آن را بلافاصله
        // پس می‌گیرد و نتیجه‌ی خالص **بدتر** از ترتیبِ اول است: نه فقط خطا که
        // هرگز از دست نمی‌رفت، بلکه وضعیتی که «فقط اجرا شد» به نظر می‌رسد و در
        // واقعیت بی‌اثر است.
        //
        // یعنی این ترتیبِ ظاهراً نامنظم درست است: اول امتیازهای کلی پس گرفته
        // می‌شوند، **بعد** استثنای رجیستری داده می‌شود. ترتیب اینجا بارِ امنیتی
        // دارد و به خوشایندی جابه‌جا نمی‌شود.
        DB::unprepared('REVOKE ALL ON plugin_table_prefixes FROM PUBLIC');
        DB::unprepared(sprintf('GRANT SELECT ON plugin_table_prefixes TO %s', $role));

        // `ALTER DEFAULT PRIVILEGES` لازم نیست و نباید بود: دسترسی پیش‌فرض یعنی
        // دسترسی به جدول‌هایی که هنوز وجود ندارند، و هر جدول تازهٔ هسته هم
        // به افزونه داده می‌شد.
    }

    /**
     * پادزهرهای `grantMinimal()` در **دیتابیسِ جاری**.
     *
     * `CONNECT` عمداً اینجا نیست — امتیازی سطح سرور است و
     * `revokeConnectEverywhere()` همهٔ دیتابیس‌ها را پاک می‌کند. اگر اینجا هم
     * پاکش کنیم، دو بار کار تکرار می‌شود و `revokeAll` یک باگ دیگر می‌شود.
     *
     * هر امتیازِ دیگری که `grantMinimal()` می‌دهد باید اینجا پس گرفته شود. آن
     * فهرست قبلاً در دو جا جدا نوشته شده بود و دو بار از قلم افتاد — یک بار
     * `SELECT` رجیستری، یک بار `USAGE` شِما — و هر بار `DROP ROLE` با
     * `Dependent objects still exist` شکست خورد.
     */
    /**
     * پس گرفتنِ **همهٔ** امتیازهای نقش — در همهٔ دیتابیس‌ها.
     *
     * ⚠️ **ACLها هم سراسری‌اند.** همان دلیلی که `dropOwnedObjects` باید همهٔ
     * دیتابیس‌ها را بگردد، اینجا هم هست: `GRANT` روی جدول یا شِما در
     * `pg_class.relacl` / `pg_namespace.nspacl` زندگی می‌کند که **per-database**
     * است. نقش یکی است ولی امتیازش در هر دیتابیس جداگانه ثبت می‌شود.
     *
     * نسخهٔ قبلی فقط دیتابیسِ جاری را پاک می‌کرد، پس در محیطِ ما (CI روی
     * `pishdad_test`، agentها روی `pishdad_test_a`) دو امتیاز باقی می‌ماند و
     * `DROP ROLE` می‌شکست با:
     *
     *     DETAIL: 2 objects in database pishdad_test
     *
     * که دقیقاً همان `plugin_table_prefixes` (که `grantMinimal` به همهٔ دیتابیس‌ها
     * `SELECT` می‌دهد) و شِمای `public` بود.
     *
     * `CONNECT` در این فهرست نیست چون اصلاً داده نمی‌شود — `PUBLIC` در
     * PostgreSQL به‌طور پیش‌فرض `CONNECT` دارد و دادنِ صریحش فقط یک امتیازِ
     * سطحِ سرور می‌ساخت که `DROP ROLE` را می‌شکست.
     */
    private function revokeAll(): void
    {
        $role = self::roleName();
        $current = (string) DB::connection()->getDatabaseName();

        $this->revokeAllHere($role);

        foreach ($this->otherDatabases($current) as $database) {
            try {
                $this->revokeAllHere($role, $database);
            } catch (Throwable $e) {
                Log::warning('plugin.ddl_revoke_failed', [
                    'database' => $database,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }

    /** پس گرفتن امتیازها، روی اتصالِ فاکتوری لارavel. */
    private function revokeAllHere(string $role, ?string $database = null): void
    {
        $pdo = $database === null ? null : $this->connectTo($database);

        $run = static function (string $sql) use ($pdo): void {
            $pdo === null ? DB::unprepared($sql) : $pdo->exec($sql);
        };

        $quoted = $this->quoteLiteral($role);

        // `REGEXP_REPLACE` با `^pishdad_plugin_ddl=` — فقط entry خودِ نقش را
        // از ACL می‌تراشد. بقیهٔ grantها (مثلاً `cms=arwdDxtm/cms`) می‌مانند.
        //
        // `REVOKE` معمولی کافی است ولی اگر شیء از قبل نباشد خطا می‌دهد و این
        // مسیر باید روی هر دیتابیسِ قابل اتصال بی‌خطا باشد.
        $run('do $do$ declare r record; begin'.PHP_EOL
            .'  for r in select n.nspname as s, c.relname as o from pg_class c'.PHP_EOL
            .'      join pg_namespace n on n.oid = c.relnamespace'.PHP_EOL
            ."     where c.relacl::text like '%' || ".$quoted." || '%' loop".PHP_EOL
            ."    execute format('REVOKE ALL ON %I.%I FROM %s', r.s, r.o, ".$quoted.');'.PHP_EOL
            .'  end loop;'.PHP_EOL
            .'  for r in select n.nspname as s from pg_namespace n'.PHP_EOL
            ."     where n.nspacl::text like '%' || ".$quoted." || '%' loop".PHP_EOL
            ."    execute format('REVOKE ALL ON SCHEMA %I FROM %s', r.s, ".$quoted.');'.PHP_EOL
            .'  end loop;'.PHP_EOL
            .'end $do$');
    }

    /**
     * دیتابیس‌هایِ قابل اتصال، غیر از دیتابیسِ جاری.
     *
     * ⚠️ این مسیر فقط در `dropRole` اجرا می‌شود (uninstall و پاک‌سازیِ تست)،
     * پس گران بودنش مهم نیست. فهرستِ ساخته‌شده از خودِ سرور گرفته می‌شود نه از
     * حدس.
     *
     * @return list<string>
     */
    private function otherDatabases(string $current): array
    {
        $rows = DB::select(
            'select datname from pg_database where datallowconn and datname <> ? order by datname',
            [$current],
        );

        return array_map(static fn ($row): string => $row->datname, $rows);
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
     * اگر نقش نباشد، **کاری نمی‌کند** و خطا هم نمی‌دهد.
     *
     * ⚠️ ترتیب این متد دقیقاً به ترتیبِ `grantMinimal()` برعکس است، و هر
     * مرحله لازم است:
     *
     *  ۱. `revokeConnectEverywhere()` — `GRANT CONNECT ON DATABASE` سطحِ **سرور**
     *     است. یک نصبِ چند-دیتابیسی روی هر دیتابیسی که نصب کرده امتیاز
     *     می‌گذارد، و `DROP ROLE` بدون پس‌گرفتنِ همه‌شان شکست می‌خورد.
     *  ۲. `revokeAll()` — امتیازهای شِما و جدول.
     *  ۳. `dropOwnedObjects()` — نقش، **مالک** هر جدولی است که با `CREATE` شِما
     *     ساخته، و مالکِ sequence و indexهایش هم. مالکیت وابستگیِ سراسری است.
     *
     * حذفِ هر کدام از این‌ها باعث `Dependent objects still exist` می‌شود، و
     * نسخه‌های قبلی هر کدام را داشتند و در اجرا لو رفت.
     */
    public function dropRole(): void
    {
        if (! $this->roleExists()) {
            return;
        }

        try {
            $this->revokeAll();
            $this->dropOwnedObjects();

            DB::unprepared(sprintf('DROP ROLE IF EXISTS %s', self::roleName()));
        } catch (Throwable $e) {
            Log::warning('plugin.ddl_role_drop_failed', [
                'role' => self::roleName(),
                'reason' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * حذفِ شیءهایی که نقشِ افزونه مالکشان است.
     *
     * نقش DDL با `CREATE` شِما، **مالک** هر جدولی است که می‌سازد — و مالکِ
     * sequenceهای `serial` و indexهایش هم. مالکیت یک وابستگیِ سراسری است، پس
     * `DROP ROLE` تا وقتی این‌ها هستند شکست می‌خورد با `owner of sequence …`.
     *
     * ⚠️ این یک نشتِ واقعی بود، نه مشکلِ تست: اگر روزی حذفِ افزونه‌ای
     * `PluginMigrator` را صدا نزند (یعنی uninstall شکست بخورد)، جدول‌هایش
     * می‌مانند و **نقش هم دیگر هرگز حذف نمی‌شود**. یعنی یک خطای گذرا، یک
     * وابستگیِ دائمی می‌سازد.
     *
     * ترتیب: sequence و index و جدول. `CASCADE` روی هر کدام تا وابستگی‌های
     * خودشان هم بروند.
     */
    /**
     * پاک کردنِ شیءهایی که نقشِ افزونه مالکشان است — در **همهٔ** دیتابیس‌ها.
     *
     * ⚠️ **مالکیت سراسری است و `DROP` باید در همان دیتابیس اجرا شود.**
     *
     * `pg_class` سراسری است، پس یک اتصال می‌تواند *ببیند* که این نقش در
     * `pishdad_test` هم مالکِ شش شیء است — ولی `DROP` آن‌ها را باید از داخلِ همان
     * دیتابیس بزند. اجرای `DROP` از دیتابیسِ دیگر یا بی‌اثر است یا خطا.
     *
     * نسخهٔ اول فقط دیتابیسِ جاری را می‌پرسید و `DROP ROLE` با
     * `Dependent objects still exist: 6 objects in database pishdad_test` می‌شکست.
     *
     * راه درست بدون `dblink`: برای هر دیتابیسی که لازم است، یک اتصالِ تازه با
     * نامِ همان دیتابیس باز می‌شود و `DROP` از داخلش اجرا می‌شود.
     */
    private function dropOwnedObjects(): void
    {
        $role = self::roleName();
        $current = (string) DB::connection()->getDatabaseName();

        $databases = $this->databasesWithOwnedObjects($role);

        // دیتابیسِ جاری با اتصالِ فاکتوری، بقیه با اتصالِ تازه.
        if (in_array($current, $databases, true)) {
            $this->dropOwnedObjectsHere($role);
            $databases = array_values(array_diff($databases, [$current]));
        }

        foreach ($databases as $database) {
            try {
                $this->dropOwnedObjectsIn($database, $role);
            } catch (Throwable $e) {
                Log::warning('plugin.ddl_owned_object_drop_failed', [
                    'database' => $database,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * دیتابیس‌هایی که این نقش در آن‌ها چیزی دارد.
     *
     * ⚠️ این پرسش روی **سرورِ جاری** جواب می‌دهد، پس فقط همان دیتابیس را
     * می‌بیند. برای بقیه باید به خودِ آن‌ها وصل شویم.
     *
     * راه درست: فهرستِ همهٔ دیتابیس‌هایِ قابل اتصال و امتحان کردنِ هرکدام. گران است
     * ولی این مسیر فقط در `dropRole` اجرا می‌شود (uninstall و پاک‌سازیِ تست) که
     * اصلاً پرتکرار نیست.
     *
     * @return list<string>
     */
    private function databasesWithOwnedObjects(string $role): array
    {
        $databases = [];

        foreach ($this->otherDatabases((string) DB::connection()->getDatabaseName()) as $database) {
            try {
                $owned = $this->connectTo($database)->query(
                    'select 1 from pg_class c
                       join pg_namespace n on n.oid = c.relnamespace
                       join pg_roles r on r.oid = c.relowner
                      where r.rolname = '.$this->quoteLiteral($role)."
                        and n.nspname not in ('pg_catalog', 'information_schema')
                      limit 1"
                )->fetch();

                if ($owned !== false) {
                    $databases[] = $database;
                }
            } catch (Throwable) {
                // دیتابیسی که نمی‌شود وصل شد، نمی‌تواند مانعِ ما هم باشد.
            }
        }

        return $databases;
    }

    /**
     * حذفِ شیءهای مالکیتیِ نقش، روی اتصالِ فاکتوری لارavel.
     *
     * ⚠️ **ترتیب حیاتی است: `TABLE` اول، بعد `INDEX` و `SEQUENCE`.**
     *
     * چرا؟ `pg_depend` اجازه نمی‌دهد قبل از حذفِ وابسته، مستقل را حذف کنی.
     * جدول، مالکِ سه چیز است: خودش، **row type** خودش (`typname` = نام جدول،
     * و `_tablename`) و indexهای `PRIMARY KEY`.
     *
     * نسخهٔ قبلی `SEQUENCE`، `INDEX`، `TABLE` بود — پس:
     *  - `SEQUENCE` اول: هیچ‌وقت خطا نمی‌داد ولی هیچ‌وقت هم کار نمی‌کرد،
     *    چون هیچ sequence‌ای وجود نداشت.
     *  - `INDEX` اول: `DROP INDEX … CASCADE` جدول را می‌انداخت و `pg_depend`
     *    خطا می‌داد (`cannot drop index because table requires it`).
     *  - جدول هرگز اجرا نمی‌شد، پس **row type** باقی می‌ماند و `DROP ROLE`
     *    با `Dependent objects still exist` می‌شکست.
     *
     * حالا `TABLE … CASCADE` اول می‌آید: PostgreSQL خودش index و row type را
     * می‌اندازد. `INDEX`/`SEQUENCE` بعدش برای موارد یتیم (مثلاً
     * `bigserial`) باقی است.
     */
    private function dropOwnedObjectsHere(string $role): void
    {
        $rows = DB::select(
            "select n.nspname as schema_name, c.relname as object_name, c.relkind
               from pg_class c
               join pg_namespace n on n.oid = c.relnamespace
               join pg_roles r on r.oid = c.relowner
              where r.rolname = ?
                and n.nspname not in ('pg_catalog', 'information_schema')
                and c.relkind in ('r', 'p', 'S', 'i')
              order by case c.relkind
                          when 'r' then 0
                          when 'p' then 0
                          when 'S' then 1
                          when 'i' then 2
                       end",
            [$role],
        );

        foreach ($rows as $row) {
            $kind = match ($row->relkind) {
                'S' => 'SEQUENCE',
                'i' => 'INDEX',
                default => 'TABLE',
            };

            DB::unprepared(sprintf(
                'DROP %s IF EXISTS %s.%s CASCADE',
                $kind,
                $this->quoteIdentifier($row->schema_name),
                $this->quoteIdentifier($row->object_name),
            ));
        }
    }

    /** همان کار، ولی از داخلِ یک دیتابیسِ دیگر. */
    private function dropOwnedObjectsIn(string $database, string $role): void
    {
        $pdo = $this->connectTo($database);

        $rows = $pdo->query(
            'select n.nspname as schema_name, c.relname as object_name, c.relkind
               from pg_class c
               join pg_namespace n on n.oid = c.relnamespace
               join pg_roles r on r.oid = c.relowner
              where r.rolname = '.$this->quoteLiteral($role)."
                and n.nspname not in ('pg_catalog', 'information_schema')
                and c.relkind in ('r', 'p', 'S', 'i')
              order by case c.relkind
                          when 'r' then 0
                          when 'p' then 0
                          when 'S' then 1
                          when 'i' then 2
                       end"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $kind = match ($row['relkind']) {
                'S' => 'SEQUENCE',
                'i' => 'INDEX',
                default => 'TABLE',
            };

            $pdo->exec(sprintf(
                'DROP %s IF EXISTS %s.%s CASCADE',
                $kind,
                $this->quoteIdentifier($row['schema_name']),
                $this->quoteIdentifier($row['object_name']),
            ));
        }
    }

    /** اتصال خام به یک دیتابیسِ مشخص، با نقشِ هسته. */
    private function connectTo(string $database): PDO
    {
        return new PDO(
            sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                (string) config('database.connections.pgsql.host', '127.0.0.1'),
                (int) config('database.connections.pgsql.port', 5432),
                $database,
            ),
            (string) config('database.connections.pgsql.username', 'root'),
            (string) config('database.connections.pgsql.password', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
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
