<?php

namespace App\Services\Webhooks;

use App\Models\Page;
use App\Models\Ticket;
use App\Services\Outbox\Outbox;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WF-L2 — تنها درگاهِ رویداد به وب‌هوک. همهٔ ناظرها (observers) فقط همین را صدا می‌زنند.
 *
 * ## ⭐⭐ چرا این کلاس هیچ‌وقت خطا نمی‌دهد
 *
 * این متد از داخل `Page::updated` و `Ticket::created` صدا زده می‌شود، یعنی **در
 * مسیرِ همان تراکنشی که کاربر منتظرش است**. یک استثنای اینجا یعنی:
 * انتشار صفحه ۵۰۰ می‌دهد (و کاربر فکر می‌کند منتشر شد، پس دوباره می‌زند) یا
 * ثبت تیکت شکست می‌خورد و فرستنده دوباره می‌فرستد.
 *
 * پس سه لایه، به این ترتیب:
 *  ۱) **گیتِ پیکربندی** — اگر نصب webhook را فعال نکرده، URL ندارد یا راز
 *     ندارد، **هیچ سطری ساخته نمی‌شود** (نه یک سطر که بعداً `failed` شود).
 *  ۲) **بلعیدنِ استثنا** — هر خطایی از `Outbox::enqueue()` (قفلِ DB، ستونِ
 *     سرریز، …) لاگ می‌شود و همین‌جا تمام. رویداد اصلی زنده می‌ماند.
 *  ۳) **خودِ HTTP بیرونی اصلاً اینجا نیست** — تحویل در `outbox:drain`
 *     (tick بیرونی) انجام می‌شود. پس حتی وب‌هوکی که ۳۰ ثانیه hang می‌کند
 *     هم پاسخِ کاربر را کند نمی‌کند.
 *
 * نتیجه: بدترین حالتِ ممکن این است که رویداد در صف بنشیند و بعد از ۳ تلاش
 * `failed` شود — که در پنل دیده می‌شود. «گم شدنِ بی‌صدا» نداریم.
 *
 * ## ⭐⭐ یک هشدارِ مهم دربارهٔ بلعیدنِ استثنا
 *
 * روی Postgres یک `INSERT` ناموفق **کلِ تراکنشِ باز را abort می‌کند** (`25P02`)
 * و هر فراخوانیِ بعدی تا پایانِ آن تراکنش خطا می‌گیرد. یعنی «بلعیدنِ استثنا» این
 * جا فقط جلوی exception را می‌گیرد، نهTransactionAborted را.
 *
 * سه مسیرِ واقعیِ فراخوانیِ این کلاس (`PageController::publish/unpublish/bulk`،
 * `ContactTicketController`، `TicketController`، `FormSubmissionService`) هیچ‌کدام
 * تراکنش نمی‌بندند، پس `enqueue` در autocommit اجرا می‌شود و یک INSERT شکست‌خورده
 * فقط همان statement را می‌کُشد. و تنها خطای واقع‌بینانهٔ enqueue — سرریزِ
 * `recipient` — با گیتِ طولِ URL بالا اصلاً رخ نمی‌دهد. اگر روزی این را از داخل
 * یک تراکنش صدا زدی، **همین گیت‌ها کافی نیستند** و باید enqueue را بیرونِ آن
 * تراکنش ببری.
 *
 * ## ⭐ چرا راز در سطرِ صف نیست
 *
 * سطر فقط `recipient` (همان URL) و `payload` (رویداد + داده) دارد. راز در
 * لحظهٔ ارسال از تنظیمات خوانده می‌شود — `WebhookSender` و `WebhookSettings`.
 *
 * ## چرا `dedupe_key` پایدار است ولی «یک‌بار برای همیشه» نیست
 *
 * قیدِ یکتای `dedupe_key` جلوی «دو بار enqueue در یک درخواست» را می‌گیرد، ولی
 * اگر کلید فقط `page.published:12` بود، **انتشارِ دومِ همان صفحه برای همیشه
 * حذف می‌شد**. پس یک بخشِ متغیر هم دارد:
 *  - انتشار: `published_revision_id` که در هر انتشار تازه عوض می‌شود.
 *  - لغو انتشار: زمانِ همان ذخیره (مهرِ زمانیِ تغییرِ وضعیت).
 *  - تیکت: خودِ `id` — تیکت یک بار ساخته می‌شود، پس همیشه یکتا است.
 */
final class WebhookDispatcher
{
    public const EVENT_PAGE_PUBLISHED = 'page.published';

    public const EVENT_PAGE_UNPUBLISHED = 'page.unpublished';

    public const EVENT_TICKET_CREATED = 'ticket.created';

    /** ارسالِ آزمایشیِ دکمهٔ «ارسال آزمایشی» — بیرون از صف، مستقیم. */
    public const EVENT_TEST = 'webhook.test';

    /**
     * رویدادِ عمومی. اگر نصب پیکربندی نشده باشد یا enqueue شکست بخورد، فقط لاگ.
     *
     * @param  array<string, mixed>  $data
     */
    public static function publish(string $event, array $data, string $dedupeSuffix): void
    {
        try {
            $url = WebhookSettings::url();

            /**
             * ⭐ گیت **قبل** از `enqueue`. ساختنِ سطر برای مقصدی که وجود ندارد
             * یعنی یک سطرِ تحویل که هرگز `sent` نمی‌شود ولی در جدول می‌نشیند
             * و هر tick دوباره ادعا و دوباره شکست می‌خورد.
             */
            if ($url === null || ! WebhookSettings::configured()) {
                return;
            }

            /**
             * ⭐⭐ طولِ URL هم یک گیت است، نه یک تصادفِ کم‌احتمال.
             *
             * ستونِ `recipient` برابرِ `varchar(200)` است و `url:http,https` هم
             * طول را می‌بیند — ولی این متد از کنترلر صدا زده نمی‌شود. اگر نشانی
             * بلندتر somehow ذخیره شود، `INSERT` خطا می‌دهد و روی Postgres آن
             * خطا **کلِ تراکنشِ فراخواننده را abort می‌کند** (`25P02`) — یعنی
             * انتشارِ صفحه با خطای گنگویی می‌میرد، در حالی که فکر می‌کنیم فقط
             * وب‌هوک خراب شده. یک `strlen` اینجا ارزان‌ترین بیمهٔ پروژه است.
             */
            if (mb_strlen($url) > WebhookSettings::URL_MAX) {
                return;
            }

            Outbox::enqueue(
                Outbox::CHANNEL_WEBHOOK,
                $url,
                "webhook:{$event}:{$dedupeSuffix}",
                [
                    'event' => $event,
                    'occurred_at' => now()->toIso8601String(),
                    'data' => $data,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('webhook.dispatch.failed', [
                'event' => $event,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    public static function pagePublished(Page $page): void
    {
        self::publish(
            self::EVENT_PAGE_PUBLISHED,
            self::pageData($page),
            // `published_revision_id` در هر انتشار تازه عوض می‌شود ⇒ کلید یکتا.
            $page->id.':'.(string) ($page->published_revision_id ?? 'x'),
        );
    }

    public static function pageUnpublished(Page $page): void
    {
        self::publish(
            self::EVENT_PAGE_UNPUBLISHED,
            self::pageData($page),
            $page->id.':'.self::statusStamp($page),
        );
    }

    public static function ticketCreated(Ticket $ticket): void
    {
        self::publish(self::EVENT_TICKET_CREATED, self::ticketData($ticket), (string) $ticket->id);
    }

    /**
     * دادهٔ صفحه. عمداً فقط ستون‌هایی که گیرنده برای sync لازم دارد.
     *
     * ⚠️ `blocks`/`meta` **نیست**: بدنهٔ صفحه می‌تواند صدها کیلوبایت باشد و
     * آن را در `payload` ستونِ `text` می‌نوشت. مسیرِ عمومی (`path`) داده می‌شود
     * تا گیرنده خودش محتوا را بگیرد.
     *
     * @return array<string, mixed>
     */
    private static function pageData(Page $page): array
    {
        return [
            'id' => (int) $page->id,
            'title' => (string) $page->title,
            'slug' => (string) $page->slug,
            'status' => (string) $page->status,
            'locale' => (string) ($page->locale ?? ''),
            'published_at' => $page->published_at?->toIso8601String(),
            'path' => self::publicPath($page),
        ];
    }

    /**
     * دادهٔ تیکت.
     *
     * ⚠️ `contact_email`/`contact_name` **هست** و این عمدی است: تیکتِ فرمِ
     * تماس برای یک اتوماسیون (مثلاً CRM) بدون این‌ها بی‌معنی است. این یک
     * تصمیمِ آگاهانهٔ خروجِ داده است: مقصد را مدیرِ نصب خودش در پنل تعیین
     * می‌کند و با روشن‌کردن کلید، این را پذیرفته.
     *
     * @return array<string, mixed>
     */
    private static function ticketData(Ticket $ticket): array
    {
        return [
            'id' => (int) $ticket->id,
            'subject' => (string) $ticket->subject,
            'status' => (string) $ticket->status,
            'priority' => (string) ($ticket->priority ?? 'normal'),
            'source' => (string) ($ticket->source ?? ''),
            'user_id' => $ticket->user_id !== null ? (int) $ticket->user_id : null,
            'contact_name' => $ticket->contact_name !== null ? (string) $ticket->contact_name : null,
            'contact_email' => $ticket->contact_email !== null ? (string) $ticket->contact_email : null,
        ];
    }

    /**
     * همان قاعدهٔ `ContentPublishedNotifier::pathFor`: صفحهٔ خانه اسلاگش
     * `home` است ولی در روت `/` سرو می‌شود.
     */
    private static function publicPath(Page $page): string
    {
        return $page->slug === 'home' ? '/' : '/'.$page->slug;
    }

    /**
     * مهرِ تغییرِ وضعیت برای کلیدِ dedupe. دومرتبه‌ای کافی است: یک چرخهٔ
     * «لغو انتشار ← انتشار ← لغو انتشار» در یک ثانیه از طریق API ممکن نیست.
     */
    private static function statusStamp(Page $page): string
    {
        return $page->updated_at?->format('YmdHis') ?? '0';
    }
}
