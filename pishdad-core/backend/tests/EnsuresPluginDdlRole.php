<?php

namespace Tests;

use App\Services\Plugins\PluginDdlConnection;

/**
 * K5.12 — ساختِ نقش DDL و امتیازهایش، مشترک بین همهٔ پایه‌های تست.
 *
 * ## ⚠️ چرا `PDO` خام و نه `app()`
 *
 * ساختِ نقش باید جایی باشد که تراکنشی باز نیست. در `setUpBeforeClass` اپ لاراول
 * هنوز ساخته نشده، پس `app()` کار نمی‌کند.
 *
 * نسخهٔ اول اپ را دستی bootstrap کرد تا به `DB` facade برسد. نتیجه:
 * `restore_error_handler()` صدا زده شد و PHPUnit **۴۵۵ تست** را «risky» گزارش
 * کرد، چون handlerهای استاندارد PHP دستکاری شده بودند — و این برای کلِ سوئیت،
 * نه فقط برای تست‌های K5.12.
 *
 * `PDO` خام هیچ global state‌ای درگیر نمی‌کند و برای `CREATE ROLE` و چند `GRANT`
 * کاملاً کافی است.
 *
 * ## ⚠️ چرا `config/database.php` را نمی‌خوانیم
 *
 * آن فایل `databasePath()` صدا می‌زند که به app نیاز دارد. نسخهٔ اول
 * `require` کرد و `Call to undefined method Container::databasePath()` داد.
 * مقادیر از متغیرهای محیطی می‌آیند که `phpunit.agenta.xml` با `force=true`
 * ست می‌کند — پس دقیقاً همان چیزی است که اپ می‌بیند.
 */
trait EnsuresPluginDdlRole
{
    /** قطعِ اتصالِ افزونه تا تست بعدی تازه authenticate کند. */
    protected function tearDown(): void
    {
        app(PluginDdlConnection::class)->disconnect();

        parent::tearDown();
    }

    /**
     * برگرداندنِ نقش DDL به وضعیتِ پیش از اجرای این کلاس تست.
     *
     * چرا لازم است: نقش یک شیءِ **کلاستری** است، پس `ALTER ROLE` در
     * `ensureDdlRoleExists()` رمزِ دیتابیسِ واقعیِ `cms` را هم عوض می‌کند.
     * بدون این بازگردانی، هر اجرای تست پنلِ توسعه را از کار می‌اندازد.
     *
     * `parent::tearDownAfterClass()` صدا زده می\u200cشود **اول** تا اگر خودش
     * خطا داد، بازگردانیِ نقش جا نماند.
     */
    public static function tearDownAfterClass(): void
    {
        try {
            parent::tearDownAfterClass();
        } finally {
            static::restoreDdlRole();
        }
    }

    /**
     * بازگردانیِ نهایی، دقیقاً یک بار برای کلِ اجرای سوئیت.
     *
     * `tearDownAfterClass` به‌تنهایی کافی نیست: پنج کلاسِ تست از این trait
     * استفاده می‌کنند و هرکدام در `setUpBeforeClass` دوباره `ALTER ROLE`
     * می‌زنند. پس اگر بعد از هر کلاس برگردانیم، کلاسِ بعدی خرابش می‌کند و
     * دیگر چیزی برای برگرداندن نمی‌ماند.
     *
     * `register_shutdown_function` تنها نقطه‌ای است که می‌دانیم هیچ تستی در
     * حال اجرا نیست — چه اجرا کامل باشد، چه با `--stop-on-failure` متوقف شود.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        static::ensureDdlRoleExists();
        static::ensureSuiteShutdownHook();
    }

    protected static bool $suiteHookRegistered = false;

    protected static function ensureSuiteShutdownHook(): void
    {
        if (static::$suiteHookRegistered) {
            return;
        }

        static::$suiteHookRegistered = true;

        register_shutdown_function(static function (): void {
            static::restoreDdlRoleAfterSuite();
        });
    }

    public static function ensureDdlRoleExists(): void
    {
        $password = (string) (getenv('PLUGIN_DDL_PASSWORD') ?: '');

        if ($password === '') {
            throw new \LogicException('PLUGIN_DDL_PASSWORD باید در phpunit.agenta.xml تعریف شود.');
        }

        $host = (string) (getenv('DB_HOST') ?: '127.0.0.1');
        $port = (string) (getenv('DB_PORT') ?: '5432');
        $database = (string) (getenv('DB_DATABASE') ?: '');
        $user = (string) (getenv('DB_USERNAME') ?: 'root');
        $corePassword = (string) (getenv('DB_PASSWORD') ?: '');

        $pdo = new \PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database),
            $user,
            $corePassword,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );

        $role = PluginDdlConnection::ROLE;
        $quoted = str_replace("'", "''", $password);

        $exists = $pdo->prepare('select 1 from pg_roles where rolname = ?');
        $exists->execute([$role]);

        // ── نقش DDL یک شیءِ کلاستری است، نه یک شیءِ دیتابیس ──────────────
        //
        // ⚠️ این تنها جایی بود که اجرای تست‌ها محیطِ توسعه را خراب می‌کرد.
        //
        // `phpunit.xml` رمزِ تستِ خودش (`phpunit-ddl-secret-*`) را با
        // `force=true` تزریق می‌کند و اینجا `ALTER ROLE` می‌زد. ولی
        // `CREATE ROLE`/`ALTER ROLE` در PostgreSQL **سطح کلاستر** است: نقش
        // بین `cms` (واقعی) و `pishdad_test` مشترک است. پس هر بار که تست‌ها
        // اجرا می‌شدند، رمزِ نقشِ محیطِ توسعه عوض می‌شد و بعد از پایانِ
        // تست، پنل با خطای «رمز پیکربندی به نقش وصل نمی‌شود» می‌افتاد —
        // در حالی که همهٔ تست‌ها سبز بودند.
        //
        // پس وضعیتِ پیش از دستکاری را نگه می‌داریم و در `restoreDdlRole()`
        // (از `tearDownAfterClass`) برمی‌گردانیم.
        //
        // ⚠️ **فقط بارِ اول** این را ثبت می‌کند. پنج کلاسِ تست از این trait
        // استفاده می‌کنند و `setUpBeforeClass` هرکدام صدا زده می‌شود؛ اگر هر
        // بار وضعیت را بازنویسی کنیم، کلاسِ دوم هشِ *تست* را به‌عنوان «وضعیتِ
        // اولیه» ثبت می‌کند و آخرین کلاس همان را برمی‌گرداند — یعنی باز هم
        // محیطِ توسعه خراب می‌ماند. پس `!== null` شرطِ کلیدی است.
        if (static::$createdRole === null) {
            static::$createdRole = $exists->fetchColumn() === false;
        }

        if (static::$createdRole) {
            $pdo->exec(sprintf(
                "CREATE ROLE %s LOGIN PASSWORD '%s' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION",
                $role,
                $quoted,
            ));
        } else {
            // فقط بارِ اولِ واقعی. بعدش دیگر `null` نیست و دست نمی‌خوریم.
            if (static::$previousPassword === null) {
                static::$previousPassword = static::currentRolePassword($pdo, $role);
            }

            // اگر رمز عوض شده باشد، تست ساکت رد می‌شد و هیچ‌کس نمی‌فهمید چرا.
            $pdo->exec(sprintf("ALTER ROLE %s PASSWORD '%s'", $role, $quoted));
        }

        // ── `GRANT CONNECT` عمداً داده نمی‌شود ─────────────────────────
        //
        // `PUBLIC` در PostgreSQL به‌طور پیش‌فرض `CONNECT` دارد، پس نقش تازه بدون
        // هیچ امتیازی وصل می‌شود. دادنِ `CONNECT` صریح فقط یک مشکل می‌سازد:
        // امتیازی **سطح سرور** که در `pg_database` زندگی می‌کند، پس هر جا این
        // اجرا شود روی هر دیتابیسی امتیاز می‌ماند و `DROP ROLE` می‌شکند با
        // `Dependent objects still exist: privileges for database …`.
        //
        // و پس‌گرفتنش سه نسخه شکست خورد تا رسید به `to_regrole` — چون
        // `has_database_privilege` با `PUBLIC` هم true است حتی وقتی امتیاز صریح
        // نداریم. یعنی فهرستِ دیتابیس‌ها هیچ‌وقت خالی نمی‌شد.
        //
        // راه درست: اصلاً نده. `PUBLIC` کافی است و `REVOKE` هم لازم نمی‌شود.
        $pdo->exec(sprintf('REVOKE ALL ON SCHEMA public FROM %s', $role));
        $pdo->exec(sprintf('GRANT USAGE, CREATE ON SCHEMA public TO %s', $role));
        $pdo->exec(sprintf('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM %s', $role));
        $pdo->exec('REVOKE ALL ON plugin_table_prefixes FROM PUBLIC');
        $pdo->exec(sprintf('GRANT SELECT ON plugin_table_prefixes TO %s', $role));

        $pdo = null;
    }

    /** @var bool|null آیا این اجرا نقش را از صفر ساخت؟ `null` = هنوز نامعلوم. */
    protected static ?bool $createdRole = null;

    /**
     * هشِ رمزِ نقش **پیش از** دستکاری این کلاس تست.
     *
     * `null` یعنی «نامعلوم» — یا اصلاً خوانده نشد، یا چیزی برای برگرداندن نبود.
     * در آن حالت `restoreDdlRole()` عمداً دست نمی\u200cزند.
     */
    protected static ?string $previousPassword = null;

    /**
     * هشِ رمزِ فعلیِ نقش، یا `null` اگر خوانده نشد.
     *
     * `pg_authid.rolpassword` فقط برای superuser قابل خواندن است. اگر دسترسی
     * نبود `null` می‌دهیم و آن‌وقت وضعیت «نامعلوم» تلقی می‌شود — یعنی
     * `restoreDdlRole()` دست نمی‌زند. بهتر است محیط خراب نشود تا اینکه
     * رمزِ اشتباه بنویسیم.
     */
    protected static function currentRolePassword(\PDO $pdo, string $role): ?string
    {
        try {
            $stmt = $pdo->prepare('select rolpassword from pg_authid where rolname = ?');
            $stmt->execute([$role]);
            $hash = $stmt->fetchColumn();

            return is_string($hash) && $hash !== '' ? $hash : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * برگرداندنِ وضعیتِ نقش به آنچه **پیش از** اجرای تست بود.
     *
     * سه حالت داریم:
     *  ۱) نقش نبود ⇒ ساخته شد ⇒ باید `DROP` شود، وگرنه نقشِ یک‌بارمصرف در
     *     محیطِ توسعه می‌ماند و `DROP ROLE` را بعداً سخت می‌کند.
     *  ۲) رمزِ پیشین خوانده شد ⇒ `ALTER` و برگرداندن.
     *  ۳) رمزِ پیشین «نامعلوم» بود ⇒ دست نمی‌زنیم.
     */
    public static function restoreDdlRole(): void
    {
        // ⚠️ این فقط **آخرین** کلاسِ تست می‌خواند، ولی `setUpBeforeClass` هر
        // کلاس دوباره `ALTER` می‌کند. پس اگر اینجا وضعیت را پاک کنیم، کلاسِ
        // بعدی دوباره `setUpBeforeClass` می‌رود و یک `ALTER` دیگر می‌زند —
        // و بعد از پایانِ آن دیگر هیچ `tearDownAfterClass`ای نمی‌ماند که
        // برگرداندن را انجام دهد. پس وضعیت را **نگه می‌داریم** و فقط یک بار
        // در `tearDownLastTest()` (سوئیت‌تمام) برمی‌گردانیم.
        if (static::$restored) {
            return;
        }

        $previous = static::$previousPassword;

        if (static::$createdRole === true) {
            static::withCorePdo(static function (\PDO $pdo): void {
                $pdo->exec(sprintf('DROP ROLE IF EXISTS %s', PluginDdlConnection::ROLE));
            });

            static::$restored = true;
            static::$createdRole = null;
            static::$previousPassword = null;

            return;
        }

        if ($previous === null) {
            // «نامعلوم» ⇒ بهتر است محیط خراب نشود تا اینکه رمز اشتباه بنویسیم.
            return;
        }

        static::withCorePdo(static function (\PDO $pdo) use ($previous): void {
            $literal = "'".str_replace("'", "''", $previous)."'";

            $pdo->exec(sprintf('ALTER ROLE %s PASSWORD %s', PluginDdlConnection::ROLE, $literal));
        });

        static::$restored = true;
    }

    /**
     * بازگردانیِ نهایی، بعد از **آخرین** تست.
     *
     * `tearDownAfterClass` برای هر کلاس اجرا می‌شود و کافی نیست: کلاسِ
     * بعدی دوباره رمزِ تست را می‌نویسد. تنها جایی که می‌شود مطمئن شد که هیچ
     * تستی در حال اجرا نیست، همین‌جاست.
     */
    public static function restoreDdlRoleAfterSuite(): void
    {
        static::restoreDdlRole();
    }

    protected static bool $restored = false;

    protected static function withCorePdo(callable $work): void
    {
        try {
            $pdo = new \PDO(
                sprintf(
                    'pgsql:host=%s;port=%s;dbname=%s',
                    (string) (getenv('DB_HOST') ?: '127.0.0.1'),
                    (string) (getenv('DB_PORT') ?: '5432'),
                    (string) (getenv('DB_DATABASE') ?: ''),
                ),
                (string) (getenv('DB_USERNAME') ?: 'root'),
                (string) (getenv('DB_PASSWORD') ?: ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );

            $work($pdo);
        } catch (\Throwable $e) {
            // بازگرداندن، بهترین تلاش است؛ نباید تست را قربانی کند. ولی ساکت
            // هم از کنارش نمی‌گذریم.
            error_log('plugin ddl role restore failed: '.$e->getMessage());
        }
    }
}
