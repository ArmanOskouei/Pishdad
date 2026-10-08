<?php

namespace Tests\Support;

use App\Services\Plugins\PluginDdlConnection;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * بازگردانیِ نقش DDL **بعد از کلِ اجرای سوئیت**.
 *
 * ## چه چیزی خراب می‌شد
 *
 * نقش `pishdad_plugin_ddl` در PostgreSQL یک شیءِ **کلاستری** است:
 * `CREATE ROLE`/`ALTER ROLE` روی کلِ سرور اثر دارد، نه فقط یک دیتابیس. پس
 * نقش بین `cms` (واقعی) و `pishdad_test` (تست) **مشترک** است.
 *
 * `EnsuresPluginDdlRole` در `setUpBeforeClass` یک `ALTER ROLE` با رمزِ تست
 * می‌زند — و `phpunit.xml` آن رمز را با `force="true"` تزریق می‌کند.
 *
 * نتیجهٔ ناگزیر: هر بار که `php artisan test` اجرا می‌شد، رمزِ نقشِ محیطِ
 * توسعه عوض می‌شد و بعد از پایانِ تست، پنل با خطای «رمز پیکربندی به نقش
 * وصل نمی‌شود» می‌افتاد. **همهٔ تست‌ها سبز بودند.** یعنی یک دستورِ محلیِ
 * بی‌خطر، کلِ پنلِ توسعه را از کار انداخته بود — و آن‌قدر تکرار شد که به
 * یک بن‌بست رسید: برای تست کردن باید پنل را خراب می‌کردی، و برای درست
 * کردنِ پنل باید تست نمی‌کردی.
 *
 * ## چرا extension
 *
 * `tearDownAfterClass` جواب نمی‌دهد: پنج کلاسِ تست از آن trait استفاده
 * می‌کنند و کلاسِ بعدی بلافاصله دوباره `ALTER` می‌زند. `register_shutdown_function`
 * هم داخل پروسه‌ای اجرا می‌شود که PHPUnit در آن است، نه پروسهٔ
 * `php artisan test` — پس باز هم قبل از خروجِ فرمانِ اصلی اتفاق نمی‌افتد.
 *
 * `ExecutionFinished` تنها رویدادی است که می‌گوید «سوئیت تمام شد».
 */
final class RestoresPluginDdlRole implements Extension, ExecutionFinishedSubscriber
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber($this);
    }

    public function notify(ExecutionFinished $event): void
    {
        self::restore();
    }

    /**
     * بازگردانیِ واقعی، بیرون از تراکنشِ تست و مستقل از متغیرهای تست.
     *
     * ⚠️ ملاک، رمزِ **محیطِ واقعی** است نه آنچه `phpunit.xml` تزریق کرده. پس
     * اول می‌پرسیم «آیا رمزِ محیط کار می‌کند؟» و اگر آری، هیچ کاری نمی‌کنیم
     * — حتی اگر `$event->testSuite()->wasSuccessful()` نباشد، چون چیزی که
     * لازم داریم «پاک بودنِ وضعیت» است نه «سبز بودنِ تست‌ها».
     */
    public static function restore(): void
    {
        try {
            $password = self::envPassword();

            if ($password === null) {
                return;
            }

            if (self::envPasswordWorks($password)) {
                return;
            }

            $pdo = self::corePdo();

            if ($pdo === null) {
                return;
            }

            $pdo->exec(sprintf(
                "ALTER ROLE %s PASSWORD '%s'",
                PluginDdlConnection::ROLE,
                str_replace("'", "''", $password),
            ));

            $pdo = null;
        } catch (\Throwable $e) {
            // بازگردانی بهترین تلاش است؛ نباید خطای تست را جابه‌جا کند.
            error_log('plugin ddl role suite restore failed: '.$e->getMessage());
        }
    }

    /**
     * رمزِ محیط، با یک پیچ مهم.
     *
     * `phpunit.xml` مقدارِ تست را با `force="true"` در `$_ENV`/`getenv()`
     * می‌گذارد — پس `getenv('PLUGIN_DDL_PASSWORD')` **همان رمزِ تست** است،
     * نه رمزِ محیط. اگر به آن تکیه کنیم، «بازگردانی» بی‌اثر می‌شود.
     *
     * راه درست: خودِ `.env` را بخوانیم، چون آن‌جا مقدارِ واقعی نوشته شده.
     */
    private static function envPassword(): ?string
    {
        $file = dirname(__DIR__, 2).'/.env';

        if (! is_readable($file)) {
            return null;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);

            if ($key !== 'PLUGIN_DDL_PASSWORD') {
                continue;
            }

            $value = trim($value);
            // مقدارِ کوتسه یا خالی ⇒ چیزی برای برگرداندن نیست.
            if ($value === '' || strlen($value) < 8) {
                return null;
            }

            return trim($value, "\"'");
        }

        return null;
    }

    private static function envPasswordWorks(string $password): bool
    {
        try {
            new \PDO(
                self::dsn(),
                PluginDdlConnection::ROLE,
                $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function dsn(): string
    {
        return sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            self::host(),
            (string) (getenv('DB_PORT') ?: '5432'),
            // ⚠️ دیتابیسِ **واقعی**. `phpunit.xml` این را روی `pishdad_test` می‌گذارد
            // و سنجیدنِ رمز روی دیتابیسِ تست یعنی سنجیدنِ چیزِ اشتباه.
            'cms',
        );
    }

    private static function host(): string
    {
        $host = (string) (getenv('DB_HOST') ?: '');

        return $host === '' ? 'db' : $host;
    }

    private static function corePdo(): ?\PDO
    {
        try {
            return new \PDO(
                sprintf(
                    'pgsql:host=%s;port=%s;dbname=%s',
                    self::host(),
                    (string) (getenv('DB_PORT') ?: '5432'),
                    'cms',
                ),
                (string) (getenv('DB_USERNAME') ?: 'cms'),
                (string) (getenv('DB_PASSWORD') ?: ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
