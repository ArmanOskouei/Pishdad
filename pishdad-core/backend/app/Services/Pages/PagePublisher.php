<?php

namespace App\Services\Pages;

use App\Models\Page;
use App\Models\RevalidateLog;
use App\Models\Setting;
use App\Services\Push\ContentPublishedNotifier;
use App\Services\RevalidateDispatcher;
use App\Services\RevalidateSigner;

/**
 * WF-C3 — منطق مشترکِ انتشار صفحه.
 *
 * قبلاً این منطق داخل `PageController::publish()` بود. کامندِ زمان‌بند هم باید
 * دقیقاً همان کار را بکند (snapshot + published + revalidate امضاشده + ابطال کش
 * + اعلان)، پس استخراج شد تا دو نسخهٔ واگرا نداشته باشیم.
 *
 * `$userId` هنگام انتشار دستی = کاربرِ لاگین‌شده، و هنگام انتشارِ زمان‌بند = null
 * (سیستم). `scheduled_at` بعد از انتشار موفق پاک می‌شود.
 */
class PagePublisher
{
    public function __construct(
        private readonly RevalidateSigner $signer,
        private readonly RevalidateDispatcher $dispatcher,
        private readonly ContentPublishedNotifier $pushNotifier,
    ) {}

    /** انتشار = revision جدید + published + ثبت لاگ revalidate امضاشده. */
    public function publish(Page $page, ?int $userId, ?string $note = 'انتشار'): Page
    {
        $revision = $page->snapshot(
            $page->blocks ?? [],
            $page->meta,
            $userId,
            $note,
        );

        $page->forceFill([
            'status' => Page::STATUS_PUBLISHED,
            'published_revision_id' => $revision->id,
            'published_at' => now(),
            'scheduled_at' => null,
        ])->save();

        $signed = $this->signer->sign(["page:{$page->slug}"]);
        RevalidateLog::query()->create([
            'page_id' => $page->id,
            'tags' => $signed['tags'],
            'nonce' => $signed['nonce'],
            'signature' => $signed['signature'],
        ]);

        // ابطال فوری کش ISR فرانت (fire-and-forget — خرابی فرانت انتشار را نمی‌شکند).
        // L-B9 — انتشار _صفحهٔ خانه_ باید `site-homepage` را هم باطل کند.
        $this->dispatcher->dispatch($this->tagsFor($page));

        // اعلام به زائرانی که opt-in کرده‌اند (P1.10) — بیرون از مسیر اصلی و
        // خودش استثنا را می‌گیرد؛ انتشار نباید به‌خاطر اعلان شکست بخورد.
        $this->pushNotifier->announce($page->fresh());

        return $page->fresh();
    }

    /**
     * تگ‌های کش یک صفحه (پایه + اسلاگ + صفحهٔ خانه).
     *
     * @return list<string>
     */
    public function tagsFor(Page $page): array
    {
        return array_values(array_unique(array_merge(
            ['pages', "page:{$page->slug}"],
            $this->homepageTags($page),
        )));
    }

    /**
     * L-B9 — تگ‌های اختصاصیِ صفحهٔ خانه، اگر این صفحه خانهٔ سایت باشد.
     *
     * «صفحهٔ خانه» دو راه تعریف دارد (همان ترتیب `Site/PageController::homepage()`):
     * تنظیم `homepage_page_id` یا اسلاگِ `home`.
     *
     * @return list<string>
     */
    private function homepageTags(Page $page): array
    {
        return $this->isHomepage($page) ? ['site-homepage', 'page:home'] : [];
    }

    private function isHomepage(Page $page): bool
    {
        if ($page->slug === 'home') {
            return true;
        }

        $site = Setting::get('site', 'global', []);

        return is_array($site)
            && (int) ($site['homepage_page_id'] ?? 0) === (int) $page->id;
    }
}
