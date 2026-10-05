<?php

namespace App\Console\Commands;

use App\Services\Outbox\Outbox;
use Illuminate\Console\Command;

/**
 * F1.3 — تخلیهٔ صفِ تحویل.
 *
 * ## ⭐ چرا command است و نه cron
 *
 * `Q7` قید کرده: **هیچ کرونی وجود ندارد.** صف را یک tick بیرونی خالی می‌کند که
 * خودش `schedule:run` دیرهنگام لاراول را هم اجرا می‌کند. یعنی این command باید
 * «بی‌خطر بودن در تکرار» را خودش تضمین کند، نه اینکه به فاصلهٔ زمانی تکیه کند.
 *
 * ## چرا سه کار در یک command
 *
 * `reapStale` قبل از `claim`، و `prune` بعد از آن. جدا کردنشان یعنی یک مسیرِ
 * فراخوانی که یکی را فراموش می‌کند — و هر سه به هم وابسته‌اند: بدون reap،
 * سطرهای گیرکرده جمع می‌شوند؛ بدون prune، جدول بی‌مهار بزرگ می‌شود.
 *
 * ⚠️ ترتیب هم قید است: reap **قبل از** claim است تا سطرِ گیرکرده در همین
 * tick برگردد، نه tickِ بعدی.
 */
class OutboxDrain extends Command
{
    protected $signature = 'outbox:drain
        {--batch= : سطر در هر tick (پیش‌فرض: config outbox.batch)}
        {--no-prune : فقط تحویل؛ بدون پاک‌کردنِ سطرهای قدیمی}';

    protected $description = 'تخلیهٔ صفِ تحویلِ بیرونی (ایمیل/SMS) — فراخوانِ tick';

    public function handle(): int
    {
        $batch = (int) ($this->option('batch') ?: config('outbox.batch', 50));
        $batch = max(1, min(500, $batch));

        $maxAttempts = (int) config('outbox.max_attempts', 3);
        $staleSeconds = (int) config('outbox.stale_claim_seconds', 600);

        // ۱) سطرهای جاافتاده از tickِ مرده، قبل از ادعا.
        $reaped = Outbox::reapStale($staleSeconds, $maxAttempts);

        // ۲) ادعای اتمیک. ⭐ اینجاست که idempotency واقعاً اتفاق می‌افتد:
        //    tick دومِ همزمان، این سطرها را نمی‌بیند.
        $claimed = Outbox::claim($batch);

        $sent = 0;
        $failed = 0;

        foreach ($claimed as $row) {
            // `deliver` خودش استثنا را می‌گیرد و `failed` را ثبت می‌کند؛ اینجا
            // فقط شمارش است. یک سطرِ خراب نباید کل tick را متوقف کند.
            Outbox::deliver($row) ? $sent++ : $failed++;
        }

        // ۳) پاک‌سازی، بعد از تحویل — تا سطرهای همین tick را نشوییم.
        $pruned = $this->option('no-prune') ? 0 : Outbox::prune((int) config('outbox.retention_days', 30));

        $this->info(sprintf(
            'ادعا: %d · ارسال: %d · ناموفق: %d · بازپس‌گرفته: %d · پاک‌شده: %d',
            count($claimed),
            $sent,
            $failed,
            $reaped,
            $pruned,
        ));

        return self::SUCCESS;
    }
}
