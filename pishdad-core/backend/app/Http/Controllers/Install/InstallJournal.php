<?php

namespace App\Http\Controllers\Install;

/**
 * F1.1.B — ژورنال نصب + قفل نصب.
 *
 * - `storage/install/journal.json`: کدام گام‌ها تمام شده‌اند (resume نصب نیمه‌کاره).
 * - `storage/install/install.lock`: نصب تمام شده؛ تا هست، مسیرهای /install قفل‌اند.
 * - `install_token`: توکن تصادفیَِ سمت‌سرور برای POSTهای نصب (بدون web middleware،
 *   پس نه session نه CSRF؛ این توکن جای آن را می‌گیرد).
 *
 * مسیرها از `config('installer.*')` می‌آیند تا تست‌ها بتوانند به پوشه موقت بروند؛
 * در پروداکشن همان `storage_path('install')` است.
 */
class InstallJournal
{
    public const STEPS = ['preflight', 'database', 'app_key', 'migrate', 'superadmin', 'finalize'];

    public static function basePath(): string
    {
        return (string) (config('installer.path') ?? storage_path('install'));
    }

    public static function journalPath(): string
    {
        return self::basePath().DIRECTORY_SEPARATOR.'journal.json';
    }

    public static function lockPath(): string
    {
        return self::basePath().DIRECTORY_SEPARATOR.'install.lock';
    }

    public static function isInstalled(): bool
    {
        return is_file(self::lockPath());
    }

    /** @return array<string, mixed> */
    public static function read(): array
    {
        $path = self::journalPath();
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function isStepDone(string $step): bool
    {
        $journal = self::read();

        return isset($journal['steps'][$step]['at']);
    }

    public static function firstIncompleteStep(): ?string
    {
        foreach (self::STEPS as $step) {
            if (! self::isStepDone($step)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * توکن نصب (ساخته و ماندگار می‌شود). مقایسه همیشه با hash_equals در کنترلر.
     */
    public static function token(): string
    {
        $journal = self::read();
        if (! empty($journal['token']) && is_string($journal['token'])) {
            return $journal['token'];
        }
        $token = bin2hex(random_bytes(32));
        self::write(array_merge($journal, ['token' => $token]));

        return $token;
    }

    public static function markStepDone(string $step, array $data = []): void
    {
        $journal = self::read();
        $journal['started_at'] ??= now()->toIso8601String();
        $journal['steps'] ??= [];
        $journal['steps'][$step] = array_merge(['at' => now()->toIso8601String()], $data);
        self::write($journal);
    }

    /** @param array<string, mixed> $data */
    public static function put(string $key, mixed $data): void
    {
        $journal = self::read();
        $journal[$key] = $data;
        self::write($journal);
    }

    /** @return array<string, mixed> */
    public static function get(string $key, array $default = []): array
    {
        $journal = self::read();
        $value = $journal[$key] ?? $default;

        return is_array($value) ? $value : $default;
    }

    /** @param array<string, mixed> $meta */
    public static function writeLock(array $meta = []): void
    {
        self::ensureDir();
        $payload = json_encode(array_merge([
            'installed_at' => now()->toIso8601String(),
            'app' => config('app.name'),
        ], $meta), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $tmp = self::lockPath().'.tmp';
        file_put_contents($tmp, $payload);
        @chmod($tmp, 0644);
        rename($tmp, self::lockPath());
    }

    /**
     * برداشتنِ قفل نصب (و ژورنال) — یعنی «این محیط دیگر نصب‌شده نیست».
     *
     * فقط برای ابزارهای صریح: حذفِ نصب و تست. کدِ عادی هرگز قفل را پاک
     * نمی‌کند، چون نبودِ همین فایل دلیلِ نیست که نصب انجام نشده — نگهبانِ نصب
     * (InstallGuard) این را خودش می‌سنجد.
     */
    public static function forget(): void
    {
        @unlink(self::lockPath());
        @unlink(self::journalPath());
    }

    /** @param array<string, mixed> $journal */
    private static function write(array $journal): void
    {
        self::ensureDir();
        $tmp = self::journalPath().'.tmp';
        file_put_contents($tmp, json_encode($journal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        // ژورنال ممکن است رمز DB را داشته باشد: فقط مالک بخواند.
        @chmod($tmp, 0600);
        rename($tmp, self::journalPath());
    }

    private static function ensureDir(): void
    {
        if (! is_dir(self::basePath())) {
            mkdir(self::basePath(), 0755, true);
        }
    }
}
