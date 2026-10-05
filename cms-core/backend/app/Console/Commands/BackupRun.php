<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupRunner;
use Illuminate\Console\Command;
use Throwable;

/**
 * E5 — `php artisan backup:run` → یک dump چرخشی از دیتابیس.
 *
 * ## چرا فقط یک کار می‌کند و شش تا نه
 *
 * این فرمان یک عملیات اتمیک است: یا یک فایل قابل‌بازگردانی + مانیفست روی دیسک
 * هست، یا هیچ چیز نیست. گزینه‌های `--keep`/`--path` فقط override کانفیگ‌اند تا
 * بشود از cron بیرون از کانفیگ هم کنترلش کرد — نه یک سیستم چندوجهی که خودش
 * سیاست پشتیبان‌گیری را هم در خودش پنهان کند.
 *
 * خروجی همیشه یکی از دو حالت است: `SUCCESS` با پیام فارسی، یا `FAILURE` با پیام
 * فارسی که *علت* را می‌گوید. `backup:run` هرگز `0` برنمی‌گرداند وقتی کاری نکرده.
 */
class BackupRun extends Command
{
    protected $signature = 'backup:run
        {--keep= : تعداد نسخه‌هایی که روی دیسک بماند (پیش‌فرض: کانفیگ backup.keep)}
        {--path= : مسیر ذخیرهٔ فایل‌ها (پیش‌فرض: کانفیگ backup.path یا storage/app/backups)}';

    protected $description = 'پشتیبان‌گیری دیتابیس با pg_dump + چرخش نسخه‌ها + مانیفست JSON';

    public function handle(BackupRunner $runner): int
    {
        $keep = $this->option('keep');
        $path = $this->option('path');

        $keep = $keep === null || $keep === '' ? null : (int) $keep;
        $path = $path === null || $path === '' ? null : (string) $path;

        if ($keep !== null && $keep < 0) {
            $this->error('گزینهٔ --keep نمی‌تواند منفی باشد.');

            return self::FAILURE;
        }

        try {
            $result = $runner->run($keep, $path);
        } catch (Throwable $e) {
            // fail-closed: یک استثنای پیش‌بینی‌نشده هم باید پیام فارسی بدهد، نه
            // رد گم‌شده در stack trace تنها.
            $this->error('پشتیبان‌گیری انجام نشد: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $result['ok']) {
            $this->error($result['message']);

            return self::FAILURE;
        }

        $this->info($result['message']);

        if ($result['removed'] !== []) {
            foreach ($result['removed'] as $file) {
                $this->line('حذف نسخهٔ قدیمی: '.$file);
            }
        }

        return self::SUCCESS;
    }
}
