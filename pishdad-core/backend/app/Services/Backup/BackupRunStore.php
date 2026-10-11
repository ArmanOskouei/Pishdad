<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\File;

/**
 * P-04 — وضعیتِ آخرین اجرای پشتیبان، برای نمایش در پنل.
 *
 * `BackupRunner` داخلِ درخواست اجرا می‌شد و نتیجه را هم در پاسخ می‌داد.
 * حالا اجرا صف‌شد است، پس پnل باید result را از جایی بخواند: یک
 * `last-run.json` کنارِ خروجی‌ها. نوشتن اتمیک است (tmp + rename) تا خوانندهٔ
 * هم‌زمان فایلِ نیمه‌کاره نبیند.
 */
class BackupRunStore
{
    public const FILE = 'last-run.json';

    public static function path(?string $directory = null): string
    {
        // `config('backup.path')` وقتی کلید هست ولی مقدار null است (حالتِ
        // عادیِ نصب: `BACKUP_PATH` در .env نیست)، خودِ null را برمی‌گرداند —
        // نه مقدارِ پیش‌فرضِ دومِ config(). با `??` همان null می‌ماند و
        // `rtrim('', '/').'/last-run.json'` می‌شود `/last-run.json` در ریشهٔ
        // فایل‌سیستم (زودگذر، و بینِ app و worker جدا). پس زنجیرهٔ `?:`.
        $dir = $directory ?: config('backup.path') ?: storage_path('app/backups');

        return rtrim((string) $dir, '/').'/'.self::FILE;
    }

    /** @param array<string, mixed> $state */
    public static function record(array $state, ?string $directory = null): void
    {
        $path = self::path($directory);
        $dir = dirname($path);

        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        File::put($path.'.'.getmypid().'.tmp', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        File::move($path.'.'.getmypid().'.tmp', $path);
    }

    /** @return array<string, mixed> */
    public static function read(?string $directory = null): array
    {
        $path = self::path($directory);
        if (! File::isFile($path)) {
            return [];
        }
        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
