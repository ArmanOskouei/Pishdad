<?php

namespace App\Services\Push;

use App\Models\Page;
use App\Models\PushSubscription;
use App\Services\Outbox\Outbox;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * اعلامِ «محتوای تازه منتشر شد» به بازدیدکننده‌ها.
 *
 * ## چرا بازدیدکننده و نه فقط مدیر
 *
 * اعلان داخلی (`notifications`) برای کاربران پنل است. ولی خودِ کاربر خواسته
 * بود که **بازدیدکنندهٔ سایت** هم بتواند از صفحهٔ اصلی opt-in کند و اعلانِ
 * تازه‌ترین محتوا را بگیرد. این سرویس دقیقاً همان را انجام می‌دهد.
 *
 * ## ⭐⭐ چرا این سرویس فقط صف می‌سازد و خودش چیزی نمی‌فرستد
 *
 * نسخهٔ اول `broadcastAll()` را **درونِ درخواستِ انتشار** صدا می‌زد. با ۱۰۰
 * مشترک یعنی تا ۱۰۰ درخواستِ HTTPِ پشت‌سرهم، هرکدام با timeout ده‌ثانیه‌ای —
 * یعنی انتشارِ صفحه می‌توانست **صد ثانیه** معطل بماند و اگر کاربر پس از
 * timeout دکمه را دوباره بزند، دو انتشار اتفاق می‌افتد.
 *
 * حالا فقط یک سطر در `notification_deliveries` ساخته می‌شود به‌ازای هر
 * اشتراک، و `outbox:drain` تحویل را انجام می‌دهد. سه چیز یکجا به دست می‌آید:
 * پاسخِ کاربر به prompt برمی‌گردد، تلاشِ ناموفق **دیده** و retry می‌شود، و یک
 * سرویسِ push که ۵ ثانیه hang می‌کند هیچ اثری روی انتشار ندارد.
 *
 * ## چرا این سرویس بیرون از مدل است
 *
 * گذاشتن `booted()` روی `Page` برای dispatch کار می‌کند، ولی باعث می‌شود
 * *هر* بار که مدل ذخیره می‌شود (حتی در تست یا seed) اعلان بفرستد — که
 * آزاردهنده و غیرقابل پیش‌بینی است. اینجا صریح صدا زده می‌شود.
 *
 * ## ⭐⭐ چرا خودِ enqueue هم fail-soft است
 *
 * این متد از داخل `PagePublisher::publish()` صدا زده می‌شود، یعنی در مسیرِ
 * همان درخواستی که کاربر منتظرش است. یک استثنا یعنی انتشار صفحه ۵۰۰ می‌دهد و
 * کاربر فکر می‌کند منتشر شده، پس دوباره می‌زند.
 *
 * همان استدلالِ `WebhookDispatcher` دربارهٔ Postgres (`25P02`) اینجا هم صدق
 * می‌کند و باید صریح گفته شود: بلعیدنِ استثنا فقط جلوی exception را می‌گیرد،
 * نه TransactionAborted را. مسیرهای واقعیِ فراخوانی
 * (`PageController::publish/bulk/restore`، `PublishScheduledPages`) هیچ‌کدام
 * تراکنش نمی‌بندند، پس `enqueue` در autocommit اجرا می‌شود و یک INSERT
 * شکست‌خورده فقط همان statement را می‌کُشد. و تنها خطای واقع‌بینانهٔ enqueue —
 * سرریزِ `recipient` — اصلاً رخ نمی‌دهد چون `recipient` اینجا شناسهٔ عددیِ
 * اشتراک است، نه endpoint. اگر روزی این را از داخل تراکنش صدا زدی، همین
 * گیت‌ها کافی نیستند و باید enqueue را بیرونِ آن تراکنش ببری.
 */
class ContentPublishedNotifier
{
    /**
     * سقفِ مشترک در هر انتشار.
     *
     * یک enumِ سختِ ۵۰۰ یعنی سایتی با ۵۰۰+ مشترک عملاً اعلان نمی‌گیرد. عدد
     * حفظ شده، ولی دیگر **بی‌صدا** نمی‌ماند: اگر پنجره واقعاً پر شود یک لاگِ
     * **warning** با تعدادِ کلِ واجدشرایط نوشته می‌شود تا معلوم باشد چند نفر
     * از قلم افتاده‌اند (نه فقط اینکه «سقف ۵۰۰ است»).
     */
    private const ENQUEUE_LIMIT = 500;

    /**
     * وقتی یک صفحه منتشر می‌شود، به **همهٔ** بی‌نام‌ها (زائران) و مدیرانِ
     * opt-in کرده اعلان می‌دهد.
     *
     * ‎⚠️ عمداً اعلان داخلیِ پنل تکرار نمی‌شود: هر دو گروه صریحاً در سایت
     * opt-in کرده‌اند، ولی «اعلانِ محتوا به بازدیدکننده» و «اعلانِ رویدادِ
     * پنل» دو چیز متفاوت‌اند و یکی‌کردنشان فقط حسِ تکرار می‌دهد.
     *
     * ⚠️ این متد **هیچ HTTP بیرونی** نمی‌زند — فقط سطرِ صف می‌سازد.
     */
    public function announce(Page $page): void
    {
        // نباید برای هر ذخیره‌ای اعلان برود؛ فقط وقتی واقعاً منتشر شد.
        if ($page->status !== Page::STATUS_PUBLISHED) {
            return;
        }

        try {
            /**
             * ⭐ انتخابِ مشترک‌ها **پیش** از ساختنِ صف انجام می‌شود.
             *
             * اگر این کار داخل حلقهٔ enqueue بود، یک سطرِ صف برای هر ردیفِ
             * نامرتبط ساخته می‌شد و آن‌ها بعداً در هر tick دوباره ادعا و دوباره
             * «ناموفق» می‌شدند. یعنی spam هم برای کاربر، هم برای خودمان.
             *
             * ⭐⭐ و `limit` به‌تنهایی نمی‌گوید سقف **پر شده** یا نه.
             *
             * یک ردیفِ بیشتر می‌گیریم و اگر واقعاً بیشتر از سقف بود، یعنی
             * مشترک‌هایی بیرونِ این پنجره جا مانده‌اند. بدون این تشخیص، سایتی
             * با ۵۰۰+ مشترک فقط یک عدد در لاگِ info می‌داشت و هیچ نشانهٔ
             * دیگری نداشت که اعلان برای بقیه **نرفت**.
             */
            $subscriptions = PushSubscription::query()
                ->interestedIn($this->localeFor($page), $this->topicFor($page))
                ->orderBy('id')
                ->limit(self::ENQUEUE_LIMIT + 1)
                ->get(['id']);

            $capped = $subscriptions->count() > self::ENQUEUE_LIMIT;

            if ($capped) {
                $subscriptions = $subscriptions->take(self::ENQUEUE_LIMIT);
            }

            if ($subscriptions->isEmpty()) {
                return;
            }

            /**
             * ⭐⭐⭐ حذفِ بی‌صدا باید **دیده** شود.
             *
             * تا وقتی این فقط info بود، هیچ‌کس نمی‌فهمید چند نفر از قلم افتاده‌اند.
             * `total` هم کلِ مشترک‌های واجدشرایط است (نه اندازهٔ پنجره)، وگرنه عدد
             * هیچ اطلاعاتی نمی‌داد.
             */
            if ($capped) {
                Log::warning('push announcement truncated at the subscriber cap', [
                    'page' => $page->slug,
                    'locale' => $this->localeFor($page),
                    'topic' => $this->topicFor($page),
                    'selected' => $subscriptions->count(),
                    'total' => $this->interestedTotal($page),
                    'limit' => self::ENQUEUE_LIMIT,
                ]);
            }

            $payload = [
                'title' => 'محتوای تازه در پیشداد',
                'body' => $this->excerpt($page->title),
                'url' => $this->pathFor($page),
                'tag' => 'page-'.$page->getKey(),
            ];

            $enqueued = 0;

            foreach ($subscriptions as $subscription) {
                /**
                 * ⭐⭐ کلیدِ dedupe **باید** شناسهٔ اشتراک را داشته باشد.
                 *
                 * قیدِ یکتا روی `dedupe_key` است. اگر کلید فقط به صفحه اشاره
                 * کند، همهٔ مشترک‌ها در **یک** سطر جمع می‌شوند: enqueue اول
                 * موفق است و بقیه `null` می‌گیرند — یعنی دقیقاً یک نفر اعلان
                 * می‌گیرد و بقیه هرگز. این بدترین شکلِ «کار می‌کند» است.
                 *
                 * بخشِ متغیر هم لازم است: اگر کلید فقط `page:{id}` بود، انتشارِ
                 * دومِ همان صفحه برای همیشه dedupe می‌شد. پس مثل وب‌هوک، مهرِ
                 * انتشار (`published_revision_id`) هم داخل کلید می‌رود.
                 */
                $id = Outbox::push(
                    (int) $subscription->id,
                    $this->dedupeKey($page, (int) $subscription->id),
                    $payload,
                );

                // `null` یعنی این سطر قبلاً در صف بوده (idempotent) — خطا نیست.
                if ($id !== null) {
                    $enqueued++;
                }
            }

            Log::info('push announcement queued', [
                'page' => $page->slug,
                'locale' => $this->localeFor($page),
                'topic' => $this->topicFor($page),
                'subscribers' => $subscriptions->count(),
                'enqueued' => $enqueued,
                'limit' => self::ENQUEUE_LIMIT,
                // ⭐ پرچمِ سقف در خودِ خطِ عادی هم هست تا هر دو کنار هم خوانده شوند.
                'capped' => $capped,
            ]);
        } catch (Throwable $e) {
            // ⚠️ انتشار صفحه نباید به‌خاطر صف ساختن اعلان شکست بخورد.
            Log::warning('push announcement failed', [
                'page' => $page->slug,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * کلِ مشترک‌های واجدِ شرایط — برای گفتن اینکه **چند نفر** از قلم افتادند.
     *
     * فقط در مسیرِ «سقف پر شد» صدا زده می‌شود، پس هزینهٔ این `COUNT` روی
     * مسیرِ عادی (که همه‌چیز زیرِ سقف است) صفر می‌ماند.
     */
    private function interestedTotal(Page $page): int
    {
        return PushSubscription::query()
            ->interestedIn($this->localeFor($page), $this->topicFor($page))
            ->count();
    }

    /**
     * کلیدِ dedupe برای یک (صفحه، اشتراک) — **پایدار**.
     *
     * اگر `uniqid()` باشد قیدِ یکتا هیچ چیزی را dedupe نمی‌کند و کل ادعای
     * idempotency توخالی است؛ همان قاعدهٔ `Outbox::email()`.
     */
    private function dedupeKey(Page $page, int $subscriptionId): string
    {
        $stamp = $page->published_revision_id ?? $page->updated_at?->format('YmdHis') ?? '0';

        return "push:page:{$page->id}:{$stamp}:{$subscriptionId}";
    }

    /**
     * زبانِ صفحه، به شکلی که با `push_subscriptions.locale` قابل مقایسه باشد.
     *
     * ‎`pages.locale` پیش‌فرض `fa` دارد ولی روی ردیفِ قدیمی می‌تواند خالی باشد،
     * و خالی یعنی «فیلتر نکن» (قاعدهٔ `scopeInterestedIn`).
     */
    private function localeFor(Page $page): ?string
    {
        $locale = trim((string) ($page->locale ?? ''));

        return $locale !== '' ? $locale : null;
    }

    /**
     * نوعِ صفحه به‌عنوان topic.
     *
     * ⚠️ عمداً فقط چیزی که **واقعاً** در داده هست: `meta.page_type`.
     *
     * فرانت هیچ `topic`ای نمی‌فرستد، پس اگر topic را از چیزی مثل «همهٔ صفحه‌ها»
     * یا slug می‌ساختیم، هر مشترکی که topic ندارد باز هم همه را می‌گرفت و
     * فیلتر عملاً بی‌اثر می‌شد. با `page_type`، مشترکی که `topic = 'blog'`
     * دارد فقط پست‌های بلاگ را می‌گیرد — که دقیقاً معنایی است که کاربر از
     * نامِ ستون انتظار دارد.
     */
    private function topicFor(Page $page): ?string
    {
        $meta = $page->meta ?? [];

        if (! is_array($meta)) {
            return null;
        }

        $declared = $meta['page_type'] ?? $meta['_page_type'] ?? null;

        if (! is_string($declared)) {
            return null;
        }

        $declared = trim($declared);

        return $declared !== '' ? $declared : null;
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
        if ($page->slug === 'home') {
            return '/';
        }

        // ⭐⭐ F5.3-f — لایهٔ دفاعِ دوم در برابر slugِ ناامن.
        //
        // قاعدهٔ `regex` روی `slug` جلوی ذخیرهٔ اسلاگِ بد را می‌گیرد، ولی
        // ردیف‌های قدیمی (یا داده‌ای که از مسیرِ دیگری — importer، seed،
        // اسکریپتِ بیرونی — آمده) از آن رد شده‌اند. چون این رشته مستقیم
        // در `clients.openWindow` سرویس‌ورکر می‌نشیند، slugِ `//evil.example/x`
        // یک ریدایرکتِ protocol-relative می‌شد: کاربر با یک کلیک از سایت
        // بیرون می‌رفت. پس اینجا هم اگر اسلاگ URL-safe نبود، به خانه می‌رویم.
        $slug = (string) $page->slug;

        if (preg_match('/^[\p{L}\p{N}._~-]+$/u', $slug) !== 1) {
            Log::warning('push announcement skipped unsafe slug', [
                'page' => $page->id,
                'slug' => $slug,
            ]);

            return '/';
        }

        return '/'.$slug;
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
