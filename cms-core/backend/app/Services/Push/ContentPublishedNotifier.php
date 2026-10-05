<?php

namespace App\Services\Push;

use App\Models\Page;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;

/**
 * اعلامِ «محتوای تازه منتشر شد» به بازدیدکننده‌ها.
 *
 * ## چرا بازدیدکننده و نه فقط مدیر
 *
 * اعلان داخلی (`notifications`) برای کاربران پنل است. ولی خودِ کاربر خواسته
 * بود که **بازدیدکنندهٔ سایت** هم بتواند از صفحهٔ اصلی opt-in کند و اعلانِ
/// تازه‌ترین محتوا را بگیرد. این سرویس دقیقاً همان را انجام می‌دهد.
 *
 * ## چرا این سرویس بیرون از مدل است
 *
 * گذاشتن `booted()` روی `Page` برای dispatch کار می‌کند، ولی باعث می‌شود
 * *هر* بار که مدل ذخیره می‌شود (حتی در تست یا seed) اعلان بفرستد — که
/// آزاردهنده و غیرقابل پیش‌بینی است. اینجا صریح صدا زده می‌شود.
 */
class ContentPublishedNotifier
{
    public function __construct(private readonly PushSender $sender)
    {
    }

    /**
     * وقتی یک صفحه منتشر می‌شود، به همهٔ بی‌نام‌ها (زائران) اعلان می‌دهد.
     *
     * ‎⚠️ عمداً به **مدیران** اعلان نمی‌دهد: آن‌ها اعلان داخلیِ پنل را
     * دارند و اگر هر دو را بگیرند، برای هر انتشار دو اعلان می‌بینند.
     */
    public function announce(Page $page): void
    {
        // نباید برای هر ذخیره‌ای اعلان برود؛ فقط وقتی واقعاً منتشر شد.
        if ($page->status !== Page::STATUS_PUBLISHED) {
            return;
        }

        $subscribers = PushSubscription::query()->count();

        if ($subscribers === 0) {
            return;
        }

        $payload = [
            'title' => 'محتوای تازه در پیشداد',
            'body' => $this->excerpt($page->title),
            'url' => $this->pathFor($page),
            'tag' => 'page-'.$page->getKey(),
        ];

        try {
            $result = $this->sender->broadcastAll($payload);

            Log::info('push announcement sent', [
                'page' => $page->slug,
                'subscribers' => $subscribers,
                'sent' => $result['sent'],
                'failed' => $result['failed'],
            ]);
        } catch (\Throwable $e) {
            // ⚠️ انتشار صفحه نباید به‌خاطر اعلان شکست بخورد.
            Log::warning('push announcement failed', [
                'page' => $page->slug,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * مسیر عمومیِ صفحه.
     *
     * ‎⚠️ صفحهٔ خانه اسلاگش `home` است ولی در روت `/` سرو می‌شود
     * (حلِ خانه در `Site\PageController::homepage()` انجام می‌شود، نه با
     * اسلاگ). اگر اینجا `/home` برمی‌گرداندیم، کلیک روی اعلان به ۴۰۴
     * می‌خورد.
     */
    private function pathFor(Page $page): string
    {
        return $page->slug === 'home'
            ? '/'
            : '/'.$page->slug;
    }

    private function excerpt(?string $title): string
    {
        $title = trim((string) $title);

        if ($title === '') {
            return 'یک صفحهٔ تازه منتشر شد.';
        }

        // اعلان‌های مرورگر معمولاً دو خط بیشتر را نشان نمی‌دهند.
        return mb_strlen($title) > 90
            ? mb_substr($title, 0, 87).'…'
            : $title;
    }
}