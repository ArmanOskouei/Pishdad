<?php

namespace App\Console\Commands;

use App\Services\External\DependencyChecker;
use App\Services\External\DependencyStatus;
use Illuminate\Console\Command;

/**
 * `pishdad:doctor` — گزارشِ وضعیتِ همهٔ وابستگی‌های خارجی.
 *
 * ## چرا این فرمان وجود دارد
 *
 * سه چیز در این پروژه **بی‌صدا** از کار می‌افتند و هیچ‌کس نمی‌فهمد:
 *
 *  ۱. ایمیل (E2) — اگر `MAIL_MAILER=log` باشد، هیچ خطایی نیست؛ فقط رمزِ عبور
 *     «ارسال می‌شود» و هرگز نمی‌رسد.
 *  ۲. پیامک (E3) — اگر درایور `null` باشد، هیچ پیامکی نمی‌رود و هیچ ردّی نیست.
 *  ۳. صفِ outbox — اگر `schedule:run` در cron نباشد، پیام‌ها در جدول می‌مانند و
 *     سایت کاملاً سالم به نظر می‌رسد.
 *
 * این فرمان هر سه را در یک نگاه نشان می‌دهد، **قبل از** اینکه مشتری خبردار شود.
 *
 * ## خروجی و exit code
 *
 *  - `MISSING` ⇒ تنظیمِ ناخواسته ناقص است (درایورِ واقعی انتخاب شده ولی کلیدش
 *    نیست). exit code **1**. این تنها حالتی است که باید دیپلوی را متوقف کند.
 *  - `warn` ⇒ خاموشیِ آگاهانه (`null`/`log`/`stub`) یا چیزی که fallback دارد.
 *    سایت سالم است. exit code **0** — مگر `--strict`.
 *  - `ok` ⇒ همه‌چیز هست.
 */
class PishdadDoctor extends Command
{
    protected $signature = 'pishdad:doctor
        {--strict : هر «warn» را هم خطا حساب کن (برای CI)}
        {--json : خروجیِ JSON برای پایشِ خودکار}';

    protected $description = 'گزارشِ وضعیتِ وابستگی‌های خارجی: ایمیل، پیامک، چابکان، MinIO، Redis، نقشِ DDL، کلیدهای مهر، صفِ تحویل';

    public function handle(DependencyChecker $checker): int
    {
        $statuses = $checker->all();

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(
                static fn (DependencyStatus $s): array => [
                    'key' => $s->key,
                    'label' => $s->label,
                    'state' => $s->state,
                    'detail' => $s->detail,
                    'missing_keys' => $s->missingKeys,
                    'remedy' => $s->remedy,
                ],
                $statuses,
            ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $this->exitCode($statuses);
        }

        $this->newLine();
        $this->line('  گزارشِ وابستگی‌های پیش‌داد — '.$this->envLine());
        $this->newLine();

        foreach ($statuses as $status) {
            $this->renderOne($status);
        }

        $this->newLine();
        $this->renderSummary($statuses);

        return $this->exitCode($statuses);
    }

    private function renderOne(DependencyStatus $status): void
    {
        // `match` با `line()` نمی‌شود چون `line()` مقدار `void` می‌دهد نه closure —
        // یعنی بازوی دوم و سوم هم اجرا می‌شدند. پس فقط یک `match` است که
        // دقیقاً یکی از سه خط را چاپ می‌کند.
        match ($status->state) {
            DependencyStatus::OK => $this->line("  <fg=green>ok</>       {$status->label}"),
            DependencyStatus::WARN => $this->line("  <fg=yellow>warn</>     {$status->label}"),
            default => $this->line("  <fg=red;options=bold>MISSING</>  {$status->label}"),
        };

        $this->line("           {$status->detail}");

        if ($status->missingKeys !== []) {
            $this->line('           کلید(های) لازم: '.implode('، ', $status->missingKeys));
        }
        if ($status->remedy !== '') {
            $this->line("           <fg=gray>راه‌حل: {$status->remedy}</>");
        }

        $this->newLine();
    }

    /**
     * @param  list<DependencyStatus>  $statuses
     */
    private function renderSummary(array $statuses): void
    {
        $ok = count(array_filter($statuses, static fn (DependencyStatus $s): bool => $s->isOk()));
        $warn = count(array_filter($statuses, static fn (DependencyStatus $s): bool => $s->state === DependencyStatus::WARN));
        $missing = count(array_filter($statuses, static fn (DependencyStatus $s): bool => $s->isBlocking()));

        $this->line("  خلاصه: {$ok} ok · {$warn} warn · {$missing} MISSING");

        if ($missing > 0) {
            $this->line('  <fg=red>تنظیمِ ناقص وجود دارد؛ تا پر کردن کلید(های) بالا هیچ پیامی بیرون نمی‌رود.</>');
        } elseif ($warn > 0) {
            $this->line('  <fg=yellow>همه‌چیز سالم است ولی چند قابلیت آگاهانه خاموش است (جزئیات بالا).</>');
        } else {
            $this->line('  <fg=green>همهٔ وابستگی‌ها کامل‌اند.</>');
        }
    }

    /**
     * @param  list<DependencyStatus>  $statuses
     */
    private function exitCode(array $statuses): int
    {
        foreach ($statuses as $status) {
            if ($status->isBlocking()) {
                return self::FAILURE;
            }
            if ($this->option('strict') && $status->state === DependencyStatus::WARN) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function envLine(): string
    {
        return sprintf(
            'APP_ENV=%s · APP_DEBUG=%s · محیط: %s',
            (string) env('APP_ENV', '?'),
            env('APP_DEBUG') ? 'true' : 'false',
            app()->isProduction() ? 'پروداکشن' : 'غیرپروداکشن',
        );
    }
}