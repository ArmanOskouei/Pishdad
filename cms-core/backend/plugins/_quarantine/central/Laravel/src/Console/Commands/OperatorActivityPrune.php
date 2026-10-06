<?php

namespace Pishdad\Plugins\Central\Console\Commands;

use Illuminate\Console\Command;
use Pishdad\Plugins\Central\Models\OperatorActivity;

/**
 * WF-L4 — نگهداشت لاگِ فعالیتِ اپراتور: هر ردیفِ قدیمی‌تر از پنجرهٔ نگهداشت حذف می‌شود.
 *
 * ## چرا ۹۰ روز و نه «هرگز»
 *
 * لاگِ اپراتور برای ممیزی است، نه آرشیو بی‌نهایت. جدول رشد می‌کند و هیچ‌کس نمی‌پرسد
 * «دو سال پیش چه شد» — ولی اگر نگهداشت نباشد، هزینهٔ هر `DELETE` و هر
 * `COUNT(*)` فهرست با گذشت زمان بالا می‌رود و همان جدول تبدیل به ریسک می‌شود.
 * نود روز هم سه فصل است: یعنی هر incident بررسی‌شدنی می‌ماند.
 *
 * ⚠️ این تنها نقطهٔ حذفِ این جدول است. هیچ مسیرِ HTTP‌ای ردیفی را پاک نمی‌کند.
 *
 * ## پنجره چطور بازنویسی می‌شود (سه راه، به‌ترتیب اولویت)
 *
 *  ۱. `--days=180` روی خودِ فرمان (اجرای دستی).
 *  ۲. `OPERATOR_ACTIVITY_RETENTION_DAYS=180` در `.env` (پیش‌فرضِ زمان‌بندیِ روزانه).
 *  ۳. پیش‌فرضِ کد: ۹۰ روز.
 *
 * `--dry-run` فقط می‌گوید چند ردیف حذف می‌شد و چیزی نمی‌نویسد — برای سنجیدنِ
 * اثرِ پنجرهٔ بلندتر، قبل از اعتماد به زمان‌بندی.
 *
 * نکته: پنجرهٔ نگهداشت (اینجا، ۹۰ روز) با پنجرهٔ پیش‌فرضِ **فهرست** (۳۰ روز، در
 * `OperatorController`) فرق دارد و نباید یکی شوند: فهرست باید تازه‌ترین‌ها را
 * راحت نشان دهد، ولی این فرمان باید دیتابیس را کوچک نگه دارد.
 */
class OperatorActivityPrune extends Command
{
    /** پنجرهٔ پیش‌فرض — سه فصل. `config()` نه، env: این فرمان مالِ افزونه است و core نباید بداند. */
    public const DEFAULT_RETENTION_DAYS = 90;

    protected $signature = 'operator:prune
        {--days= : پنجرهٔ نگهداشت بر حسب روز (پیش‌فرض: OPERATOR_ACTIVITY_RETENTION_DAYS یا ۹۰)}
        {--dry-run : فقط شمارش کند، چیزی حذف نکند}';

    protected $description = 'حذف ردیف‌های لاگ فعالیت اپراتور که قدیمی‌تر از پنجرهٔ نگهداشت‌اند (پیش‌فرض ۹۰ روز)';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: env('OPERATOR_ACTIVITY_RETENTION_DAYS', self::DEFAULT_RETENTION_DAYS));

        if ($days < 1) {
            $this->error('پنجرهٔ نگهداشت باید دست‌کم ۱ روز باشد.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $dryRun = $this->option('dry-run') === true;
        $deleted = 0;

        if ($dryRun) {
            $this->info(sprintf(
                '%s ردیف کهنه‌تر از %s بود — چیزی حذف نشد (--dry-run).',
                (int) OperatorActivity::query()->where('created_at', '<', $cutoff)->count(),
                $cutoff->toDateTimeString(),
            ));

            return self::SUCCESS;
        }

        // chunkById نه برای سرعت، که برای قفلِ کوتاه: یک `DELETE` بزرگ روی
        // جدولی که هم‌زمان لاگ می‌نویسد، می‌تواند نوشتن را چند ثانیه قفل کند.
        OperatorActivity::query()
            ->where('created_at', '<', $cutoff)
            ->chunkById(500, function ($rows) use (&$deleted): void {
                $ids = $rows->modelKeys();

                if ($ids === []) {
                    return;
                }

                OperatorActivity::query()->whereIn('id', $ids)->delete();
                $deleted += count($ids);
            }, 'id');

        $this->info(sprintf(
            'مرز نگهداشت %s — حذف شد: %d ردیف (پنجره: %d روز)',
            $cutoff->toDateTimeString(),
            $deleted,
            $days,
        ));

        return self::SUCCESS;
    }
}
