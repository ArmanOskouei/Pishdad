<?php

namespace App\Console\Commands;

use App\Models\PageView;
use App\Models\PageVital;
use Illuminate\Console\Command;

/**
 * P-06 — نگه‌داشتِ ردیف‌های تحلیلی.
 *
 * `page_views`/`page_vitals` جدول‌های append-heavyاند: نقطهٔ نوشتنِ عمومی
 * (`site/analytics`) بدونِ ربات‌فیلتر و بدونِ هیچ نگه‌داشتی بود ⇒ رشدِ
 * بی‌مرز (و bloatِ ایندکس) به اندازهٔ ترافیک. این command ردیف‌های
 * قدیمی‌تر از سقف را پاک می‌کند؛ سقف از تنظیمات می‌آید تا نصب‌ها
 * بتوانند بسته به نیازشان کم/زیاد کنند.
 */
class AnalyticsPrune extends Command
{
    protected $signature = 'analytics:prune {--days= : بازهٔ نگه‌داشت (پیش‌فرض از config)}';

    protected $description = 'پاک‌سازی ردیف‌های قدیمیِ بازدید و Web Vitals';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('analytics.retention_days', 365));
        $days = max(7, $days);
        $cutoff = now()->subDays($days);

        $views = PageView::query()->where('created_at', '<', $cutoff)->delete();
        $vitals = PageVital::query()->where('created_at', '<', $cutoff)->delete();

        $this->info(sprintf('حذف شد: %d بازدید · %d vital (قدیمی‌تر از %d روز).', $views, $vitals, $days));

        return self::SUCCESS;
    }
}
