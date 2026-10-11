<?php

namespace App\Jobs;

use App\Services\Backup\BackupRunStore;
use App\Services\Backup\BackupRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * P-04 — پشتیبانِ دیتابیس، بیرون از مسیرِ درخواست.
 *
 * پیش از این `BackupSettingsController::store()` مستقیم `BackupRunner::run()`
 * را صدا می‌زد؛ `pg_dump` تا ۶۰۰ ثانیه یک ورکرِ FPM را اشغال می‌کرد. حالا
 * همین Job است و کنترلر ۲۰۲ برمی‌گرداند.
 *
 * `ShouldBeUnique` لایهٔ دومِ ضدِ دو pg_dumpِ هم‌زمان است (لایهٔ اول
 * `withoutOverlapping` روی زمان‌بند). `tries = 1` چون یک dumpِ نیمه‌کاره
 * ارزشِ تلاشِ دوباره ندارد؛ وضعیت از `BackupRunStore` خوانده می‌شود تا پnل
 * بتواند نتیجه را نشان دهد.
 */
class RunDatabaseBackup implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(
        public ?int $keep = null,
        public ?string $directory = null,
        public ?int $requestedBy = null,
    ) {}

    public function handle(BackupRunner $runner): void
    {
        $startedAt = now()->toIso8601String();

        BackupRunStore::record([
            'status' => 'running',
            'started_at' => $startedAt,
            'requested_by' => $this->requestedBy,
        ], $this->directory);

        try {
            $result = $runner->run($this->keep, $this->directory);

            BackupRunStore::record([
                'status' => $result['ok'] ? 'done' : 'failed',
                'ok' => $result['ok'],
                'message' => $result['message'],
                'file' => $result['file'] !== null ? basename((string) $result['file']) : null,
                'removed' => $result['removed'],
                'started_at' => $startedAt,
                'finished_at' => now()->toIso8601String(),
                'requested_by' => $this->requestedBy,
            ], $this->directory);
        } catch (Throwable $e) {
            BackupRunStore::record([
                'status' => 'failed',
                'ok' => false,
                'message' => $e->getMessage(),
                'started_at' => $startedAt,
                'finished_at' => now()->toIso8601String(),
                'requested_by' => $this->requestedBy,
            ], $this->directory);

            throw $e;
        }
    }
}
