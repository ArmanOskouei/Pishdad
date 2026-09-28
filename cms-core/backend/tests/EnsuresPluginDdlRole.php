<?php

namespace Tests;

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
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        static::ensureDdlRoleExists();
    }

    /** قطعِ اتصالِ افزونه تا تست بعدی تازه authenticate کند. */
    protected function tearDown(): void
    {
        app(\App\Services\Plugins\PluginDdlConnection::class)->disconnect();

        parent::tearDown();
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

        $role = \App\Services\Plugins\PluginDdlConnection::ROLE;
        $quoted = str_replace("'", "''", $password);

        $exists = $pdo->prepare('select 1 from pg_roles where rolname = ?');
        $exists->execute([$role]);

        if ($exists->fetchColumn() === false) {
            $pdo->exec(sprintf(
                "CREATE ROLE %s LOGIN PASSWORD '%s' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION",
                $role,
                $quoted,
            ));
        } else {
            // اگر رمز عوض شده باشد، نصب ساکت رد می‌شد و هیچ‌کس نمی‌فهمید چرا.
            $pdo->exec(sprintf("ALTER ROLE %s PASSWORD '%s'", $role, $quoted));
        }

        $pdo->exec(sprintf('GRANT CONNECT ON DATABASE %s TO %s', $database, $role));
        $pdo->exec(sprintf('REVOKE ALL ON SCHEMA public FROM %s', $role));
        $pdo->exec(sprintf('GRANT USAGE, CREATE ON SCHEMA public TO %s', $role));
        $pdo->exec(sprintf('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM %s', $role));
        $pdo->exec('REVOKE ALL ON plugin_table_prefixes FROM PUBLIC');
        $pdo->exec(sprintf('GRANT SELECT ON plugin_table_prefixes TO %s', $role));

        $pdo = null;
    }
}
