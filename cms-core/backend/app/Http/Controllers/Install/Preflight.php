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
            'label' => __('install.checks.php_version.label'),
            'status' => $ok ? 'pass' : 'fail',
            'detail' => __('install.checks.php_version.'.($ok ? 'detail_ok' : 'detail_fail'), ['version' => PHP_VERSION]),
            'remedy' => $ok ? '' : __('install.checks.php_version.remedy'),
        ];
    }

    /** @return array{key:string,label:string,status:string,detail:string,remedy:string} */
    private static function sodiumCheck(): array
    {
        $result = self::sodiumRuntime();

        return [
            'key' => 'sodium_runtime',
            'label' => __('install.checks.sodium_runtime.label'),
            'status' => $result['ok'] ? 'pass' : 'fail',
            'detail' => $result['ok']
                ? __('install.checks.sodium_runtime.detail_ok', ['backend' => $result['backend']])
                : __('install.checks.sodium_runtime.detail_fail'),
            'remedy' => $result['ok'] ? '' : __('install.checks.sodium_runtime.remedy'),
        ];
    }

    /** @return array{key:string,label:string,status:string,detail:string,remedy:string} */
    private static function extensionCheck(string $ext): array
    {
        $ok = extension_loaded($ext);

        return [
            'key' => 'ext_'.$ext,
            'label' => __('install.checks.ext.label', ['ext' => $ext]),
            'status' => $ok ? 'pass' : 'fail',
            'detail' => __('install.checks.ext.'.($ok ? 'detail_ok' : 'detail_fail')),
            'remedy' => $ok ? '' : __('install.checks.ext.remedy', ['ext' => $ext]),
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
            'label' => __('install.checks.storage_writable.label'),
            'status' => $ok ? 'pass' : 'fail',
            'detail' => $ok
                ? __('install.checks.storage_writable.detail_ok')
                : __('install.checks.storage_writable.detail_fail', ['paths' => implode(', ', $bad)]),
            'remedy' => $ok ? '' : __('install.checks.storage_writable.remedy'),
        ];
    }

    /** @return array{key:string,label:string,status:string,detail:string,remedy:string} */
    private static function envWritableCheck(): array
    {
        $envPath = (string) (config('installer.env_path') ?? base_path('.env'));
        if (is_file($envPath)) {
            $ok = is_writable($envPath);
            $detail = __('install.checks.env_writable.'.($ok ? 'detail_file_ok' : 'detail_file_fail'));
        } else {
            $ok = is_writable(dirname($envPath));
            $detail = __('install.checks.env_writable.'.($ok ? 'detail_dir_ok' : 'detail_dir_fail'));
        }

        return [
            'key' => 'env_writable',
            'label' => __('install.checks.env_writable.label'),
            'status' => $ok ? 'pass' : 'fail',
            'detail' => $detail,
            'remedy' => $ok ? '' : __('install.checks.env_writable.remedy'),
        ];
    }

    /** @return array{key:string,label:string,status:string,detail:string,remedy:string} */
    private static function appKeyCheck(): array
    {
        $key = (string) (config('app.key') ?? env('APP_KEY', ''));
        $ok = str_starts_with($key, 'base64:') && strlen(base64_decode(substr($key, 7), true) ?: '') === 32;

        return [
            'key' => 'app_key',
            'label' => __('install.checks.app_key.label'),
            'status' => $ok ? 'pass' : 'warn',
            'detail' => __('install.checks.app_key.'.($ok ? 'detail_ok' : 'detail_warn')),
            'remedy' => $ok ? '' : __('install.checks.app_key.remedy'),
        ];
    }

    /** @return array{key:string,label:string,status:string,detail:string,remedy:string} */
    private static function dbConnectionCheck(array $dbConfig): array
    {
        $config = self::dbConfig($dbConfig);
        if (($config['database'] ?? '') === '') {
            return [
                'key' => 'db_connection',
                'label' => __('install.checks.db_connection.label'),
                'status' => 'fail',
                'detail' => __('install.checks.db_connection.detail_empty'),
                'remedy' => __('install.checks.db_connection.remedy_empty'),
            ];
        }
        try {
            self::pdo($config)->query('SELECT 1');

            return [
                'key' => 'db_connection',
                'label' => __('install.checks.db_connection.label'),
                'status' => 'pass',
                'detail' => __('install.checks.db_connection.detail_ok', [
                    'host' => $config['host'], 'port' => $config['port'], 'database' => $config['database'],
                ]),
                'remedy' => '',
            ];
        } catch (Throwable $e) {
            return [
                'key' => 'db_connection',
                'label' => __('install.checks.db_connection.label'),
                'status' => 'fail',
                'detail' => __('install.checks.db_connection.detail_fail', ['error' => self::shortError($e)]),
                'remedy' => __('install.checks.db_connection.remedy_fail'),
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
                'label' => __('install.checks.db_collation_privilege.label'),
                'status' => 'fail',
                'detail' => __('install.checks.db_collation_privilege.detail_empty'),
                'remedy' => __('install.checks.db_collation_privilege.remedy_empty'),
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
                    'label' => __('install.checks.db_collation_privilege.label'),
                    'status' => 'warn',
                    'detail' => __('install.checks.db_collation_privilege.detail_drop_warn', ['probe' => $probe]),
                    'remedy' => __('install.checks.db_collation_privilege.remedy_drop_warn', ['sql' => 'DROP COLLATION "'.$probe.'";']),
                ];
            }

            return [
                'key' => 'db_collation_privilege',
                'label' => __('install.checks.db_collation_privilege.label'),
                'status' => 'pass',
                'detail' => __('install.checks.db_collation_privilege.detail_ok'),
                'remedy' => '',
            ];
        } catch (Throwable $e) {
            return [
                'key' => 'db_collation_privilege',
                'label' => __('install.checks.db_collation_privilege.label'),
                'status' => 'fail',
                'detail' => __('install.checks.db_collation_privilege.detail_fail', ['error' => self::shortError($e)]),
                'remedy' => __('install.checks.db_collation_privilege.remedy_fail'),
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
