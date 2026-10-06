<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Services\Pages\PagePublisher;
use Illuminate\Console\Command;

/**
 * WF-C3 — انتشار صفحه‌های زمان‌بندی‌شده‌ای که موعدشان رسیده است.
 *
 * هر دقیقه از `routes/console.php` صدا زده می‌شود. فقط پیش‌نویس‌هایی را می‌بیند
 * که `scheduled_at` پر و `<= now()` دارند، و هر کدام را از همان مسیر
 * `PagePublisher::publish()` منتشر می‌کند (snapshot + revalidate + اعلان).
 * یک ردیفِ خراب کل tick را متوقف نمی‌کند.
 */
class PublishScheduledPages extends Command
{
    protected $signature = 'pages:publish-scheduled';

    protected $description = 'انتشار صفحه‌های زمان‌بندی‌شده‌ای که موعدشان رسیده است';

    public function handle(PagePublisher $publisher): int
    {
        $pages = Page::query()
            ->where('status', Page::STATUS_DRAFT)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get();

        $published = 0;
        $failed = 0;

        foreach ($pages as $page) {
            try {
                $publisher->publish($page, null, 'انتشار زمان‌بندی‌شده');
                $published++;
            } catch (\Throwable $e) {
                $failed++;
                $this->error("انتشار زمان‌بندی‌شدهٔ صفحه #{$page->id} ناموفق بود: {$e->getMessage()}");
            }
        }

        $this->info(sprintf('زمان‌بندی‌شده — منتشر: %d · ناموفق: %d', $published, $failed));

        return self::SUCCESS;
    }
}
