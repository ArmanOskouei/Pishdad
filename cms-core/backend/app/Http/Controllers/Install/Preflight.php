<?php

namespace App\Http\Controllers\Install;

use PDO;
use Throwable;

/**
 * F1.1.B — پیش‌نیازهای نصب.
 *
 * هر چک: ['key','label','status' => pass|warn|fail,'detail','remedy'].
 *
 * دو چک عمداً «واقعی» هستند (نه extension_loaded / has_database_privilege):
 * - sodium: رمزنگاری+رمزگشایی واقعی (native یا sodium_compat).
 * - collation: ساخت و حذف واقعی `CREATE COLLATION` با نام تصادفی.
 */
class Preflight
{
    public const PHP_MINIMUM = '8.3.0';

    /**
     * @param array<string, mixed> $dbConfig خالی = از env خوانده می‌شود.
     * @return list<array{key:string,label:string,status:string,detail:string,remedy:string}>
     */
    public static function run(array $dbConfig = []): array
    {
        $checks = [];
        $checks[] = self::phpCheck();
        $checks[] = self::sodiumCheck();
        foreach (['mbstring', 'openssl', 'pdo_pgsql', 'intl', 'fileinfo'] as $ext) {
            $checks[] = self::extensionCheck($ext);
        }
        $checks[] = self::storageCheck();
        $checks[] = self::envWritableCheck();
        $checks[] = self::appKeyCheck();
        $checks[] = self::dbConnectionCheck($dbConfig);
        $checks[] = self::collationPrivilegeCheck($dbConfig);

        return $checks;
    }

    public static function blockingFailures(array $checks): bool
    {
        foreach ($checks as $check) {
            if (($check['status'] ?? '') === 'fail') {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public static function dbConfig(array $override = []): array
    {
        $journal = InstallJournal::get('db');
        $config = [
            'host' => $override['host'] ?? $journal['host'] ?? env('DB_HOST', '127.0.0.1'),
            'port' => $override['port'] ?? $journal['port'] ?? env('DB_PORT', '5432'),
            'database' => $override['database'] ?? $journal['database'] ?? env('DB_DATABASE', ''),
            'username' => $override['username'] ?? $journal['username'] ?? env('DB_USERNAME', ''),
            'password' => $override['password'] ?? $journal['password'] ?? env('DB_PASSWORD', ''),
        ];

        return $config;
    }

    /**
     * بررسی رانتایم واقعی sodium: یک roundtrip رمزنگاری.
     * عمداً `extension_loaded('sodium')` کافی نیست — ممکن است لود باشد ولی خراب.
     *
     * @return array{ok:bool,backend:string}
     */
    public static function sodiumRuntime(): array
    {
        try {
            $nonce = random_bytes(24);
            $key = random_bytes(32);
            $plain = 'cms-install-probe';

            if (function_exists('sodium_crypto_secretbox') && function_exists('sodium_crypto_secretbox_open')) {
                $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
                $opened = sodium_crypto_secretbox_open($cipher, $nonce, $key);

                return ['ok' => $opened === $plain, 'backend' => 'ext-sodium (native)'];
            }

            if (class_exists(\ParagonIE_Sodium_Compat::class)) {
                $cipher = \Sodium\crypto_secretbox($plain, $nonce, $key);
                $opened = \Sodium\crypto_secretbox_open($cipher, $nonce, $key);

                return ['ok' => $opened === $plain, 'backend' => 'sodium_compat (pure-PHP)'];
            }

            return ['ok' => false, 'backend' => 'none'];
        } catch (Throwable) {
            return ['ok' => false, 'backend' => 'error'];
        }
    }

    /** @return array{key:string,label:string,status:string,detail:string,remedy:string} */
    private static function phpCheck(): array
    {
        $ok = version_compare(PHP_VERSION, self::PHP_MINIMUM, '>=');

        return [
            'key' => 'php_version',
            'label' => 'نسخه PHP (حداقل ۸.۳)',
            'status' => $ok ? 'pass' : 'fail',
            'detail' => 'نسخه فعلی: '.PHP_VERSION,
            'remedy' => $ok ? '' : 'PHP را به نسخه ۸.۳ یا جدیدتر ارتقا دهید (مبنای composer.json).',
        ];
    }

    /** @return array{key:string,label:string,status:string,detail:string,remedy:string} */
    private static function sodiumCheck(): array
    {
        $result = self::sodiumRuntime();

        return [
            'key' => 'sodium_runtime',
            'label' => 'رمزنگاری sodium (تست واقعی roundtrip)',
            'status' => $result['ok'] ? 'pass' : 'fail',
            'detail' => $result['ok']
                ? 'رمزنگاری و رمزگشایی آزمایشی موفق بود ('.$result['backend'].').'
                : 'تست واقعی رمزنگاری sodium ناموفق بود.',
            'remedy' => $result['ok'] ? '' : 'اکستنشن php-sodium را نصب کنید یا بسته paragonie/sodium_compat را به‌روز نگه دارید.',
        ];
    }

    /** @return array{key:string,label:string,status:string,detail:string,remedy:string} */
    private static function extensionCheck(string $ext): array
    {
        $ok = extension_loaded($ext);

        return [
            'key' => 'ext_'.$ext,
            'label' => 'اکستنشن '.$ext,
            'status' => $ok ? 'pass' : 'fail',
            'detail' => $ok ? 'فعال است.' : 'یافت نشد.',
            'remedy' => $ok ? '' : 'اکستنشن php-'.$ext.' را نصب و PHP را ری‌استارت کنید.',
        ];
    }

    /** @return array{key:string,label:string,status:string,detail:string,remedy:string} */
    private static function storageCheck(): array
    {
        // پوشه نصب از ژورنال می‌آید تا تست‌ها پوشه واقعی نسازند.
        $paths = [storage_path(), InstallJournal::basePath(), base_path('bootstrap/cache')];
        $bad = [];
        foreach ($paths as $path) {
            if (is_dir($path)) {
                if (! is_writable($path)) {
                    $bad[] = $path;
                }
            } elseif (! @mkdir($path, 0755, true) && ! is_dir($path)) {
                $bad[] = $path.' (ساختنی نیست)';
            }
        }
        $ok = $bad === [];

        return [
            'key' => 'storage_writable',
            'label' => 'دسترسی نوشتن storage و کش',
            'status' => $ok ? 'pass' : 'fail',
            'detail' => $ok ? 'مسیرهای storage و bootstrap/cache قابل نوشتن‌اند.' : 'غیرقابل نوشتن: '.implode('، ', $bad),
            'remedy' => $ok ? '' : 'مالکیت/دسترسی مسیرها را به کاربر وب‌سرور بدهید (مثلاً chown).',
        ];
    }

    /** @return array{key:string,label:string,status:string,detail:string,remedy:string} */
    private static function envWritableCheck(): array
    {
        $envPath = (string) (config('installer.env_path') ?? base_path('.env'));
        if (is_file($envPath)) {
            $ok = is_writable($envPath);
            $detail = $ok ? '.env قابل به‌روزرسانی است.' : '.env قابل نوشتن نیست.';
        } else {
            $ok = is_writable(dirname($envPath));
            $detail = $ok ? '.env هنوز ساخته نشده ولی پوشه قابل نوشتن است.' : 'پوشه .env قابل نوشتن نیست.';
        }

        return [
            'key' => 'env_writable',
            'label' => 'قابلیت نوشتن فایل .env',
            'status' => $ok ? 'pass' : 'fail',
            'detail' => $detail,
            'remedy' => $ok ? '' : 'دسترسی نوشتن .env را به نصب‌کننده بدهید.',
        ];
    }

    /** @return array{key:string,label:string,status:string,detail:string,remedy:string} */
    private static function appKeyCheck(): array
    {
        $key = (string) (config('app.key') ?? env('APP_KEY', ''));
        $ok = str_starts_with($key, 'base64:') && strlen(base64_decode(substr($key, 7), true) ?: '') === 32;

        return [
            'key' => 'app_key',
            'label' => 'کلید APP_KEY',
            'status' => $ok ? 'pass' : 'warn',
            'detail' => $ok ? 'کلید معتبر تنظیم شده است.' : 'کلید خالی/نامعتبر است؛ در گام «کلید برنامه» ساخته می‌شود.',
            'remedy' => $ok ? '' : 'ادامه دهید؛ نصب‌کننده کلید را می‌سازد (بدون نیاز به اقدام دستی).',
        ];
    }

    /** @return array{key:string,label:string,status:string,detail:string,remedy:string} */
    private static function dbConnectionCheck(array $dbConfig): array
    {
        $config = self::dbConfig($dbConfig);
        if (($config['database'] ?? '') === '') {
            return [
                'key' => 'db_connection',
                'label' => 'اتصال پایگاه داده',
                'status' => 'fail',
                'detail' => 'نام دیتابیس مشخص نیست.',
                'remedy' => 'در گام «پایگاه داده» مشخصات اتصال را وارد کنید.',
            ];
        }
        try {
            self::pdo($config)->query('SELECT 1');

            return [
                'key' => 'db_connection',
                'label' => 'اتصال پایگاه داده',
                'status' => 'pass',
                'detail' => "اتصال به {$config['host']}:{$config['port']}/{$config['database']} برقرار شد.",
                'remedy' => '',
            ];
        } catch (Throwable $e) {
            return [
                'key' => 'db_connection',
                'label' => 'اتصال پایگاه داده',
                'status' => 'fail',
                'detail' => 'اتصال ناموفق: '.self::shortError($e),
                'remedy' => 'هاست/پورت/نام/کاربر/رمز را بررسی کنید و مطمئن شوید Postgres در دسترس است.',
            ];
        }
    }

    /** @return array{key:string,label:string,status:string,detail:string,remedy:string} */
    private static function collationPrivilegeCheck(array $dbConfig): array
    {
        $config = self::dbConfig($dbConfig);
        if (($config['database'] ?? '') === '') {
            return [
                'key' => 'db_collation_privilege',
                'label' => 'مجوز CREATE COLLATION (تست واقعی)',
                'status' => 'fail',
                'detail' => 'بدون اتصال سالم، مجوز سنجیده نمی‌شود.',
                'remedy' => 'اول اتصال پایگاه داده را درست کنید.',
            ];
        }
        $probe = 'cms_probe_'.bin2hex(random_bytes(4));
        try {
            $pdo = self::pdo($config);
            // تست واقعی با شیء واقعی و پاک‌سازی فوری (نه has_database_privilege).
            $pdo->exec('CREATE COLLATION "'.$probe.'" (provider = icu, locale = \'und\')');
            try {
                $pdo->exec('DROP COLLATION "'.$probe.'"');
            } catch (Throwable) {
                // ساخت موفق بود؛ حذف ناموفق را نادیده نگیر ولی خطا نده — گزارش بده.
                return [
                    'key' => 'db_collation_privilege',
                    'label' => 'مجوز CREATE COLLATION (تست واقعی)',
                    'status' => 'warn',
                    'detail' => "ساخت موفق ولی حذف پروب «{$probe}» ناموفق بود؛ دستی حذفش کنید.",
                    'remedy' => "DROP COLLATION \"{$probe}\"; را روی دیتابیس اجرا کنید.",
                ];
            }

            return [
                'key' => 'db_collation_privilege',
                'label' => 'مجوز CREATE COLLATION (تست واقعی)',
                'status' => 'pass',
                'detail' => 'ساخت و حذف آزمایشی collation موفق بود.',
                'remedy' => '',
            ];
        } catch (Throwable $e) {
            return [
                'key' => 'db_collation_privilege',
                'label' => 'مجوز CREATE COLLATION (تست واقعی)',
                'status' => 'fail',
                'detail' => 'تست واقعی ناموفق: '.self::shortError($e),
                'remedy' => 'به کاربر دیتابیس مجوز CREATE روی اسکیما بدهید (GRANT CREATE ON SCHEMA public).',
            ];
        }
    }

    /** @param array<string, mixed> $config */
    private static function pdo(array $config): PDO
    {
        return new PDO(
            "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']};connect_timeout=5",
            (string) $config['username'],
            (string) $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private static function shortError(Throwable $e): string
    {
        $message = $e->getMessage();
        // پیام‌های PDO حجیم‌اند؛ فقط خط اول (بدون نشت جزئیات حساس).
        $first = strtok($message, "\n") ?: $message;

        return mb_substr($first, 0, 200);
    }
}
