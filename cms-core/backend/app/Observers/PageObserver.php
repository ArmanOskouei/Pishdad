<?php

namespace App\Observers;

use App\Models\Page;
use App\Services\Webhooks\WebhookDispatcher;

/**
 * WF-L2 — وب‌هوکِ انتشار/لغو انتشارِ صفحه، از راه رویدادِ مدل.
 *
 * ## ⭐ چرا observer و نه یک خط داخل کنترلر
 *
 * انتشارِ صفحه سه مسیر دارد: تکی (`publish`)، گروهی (`bulk`)، و زمان‌بند
 * (`publish-scheduled`). اگر webhook را در `Admin\PageController` می‌گذاشتیم،
 * باید هر سه را دست می‌زدیم و مسیرِ چهارمی که فردا اضافه شد بی‌سروصدا از قلم
 * می‌افتاد. `PagePublisher` مسیرِ انتشار را می‌پوشاند ولی لغوِ انتشار فقط در
 * کنترلر است و **نباید** دست‌به‌دستِ کنترلرِ در حال تغییرِ دیگران می‌شد.
 *
 * `updated` تنها نقطه‌ای است که **هر** این مسیرها از آن رد می‌شوند.
 *
 * ## ⭐ چرا گیتِ `wasChanged('status')` حیاتی است
 *
 * `ContentPublishedNotifier` درست می‌گوید که `booted()` روی `Page` یعنی «هر
 * ذخیره اعلان می‌فرستد». این observer برعکسِ همان است: فقط **گذارِ وضعیت**
 * را می‌بیند. ویرایشِ متن، لاگینِ بازدید، یا ذخیره در seed هیچ‌کدام webhook
 * نمی‌فرستند.
 *
 * نکتهٔ فنی: در لحظهٔ رویدادِ `updated`، `original` هنوز **پیش از ذخیره** است
 * (`syncOriginal()` بعد از `saved` صدا زده می‌شود) ⇒ `getRawOriginal('status')`
 * مقدارِ پیشین را می‌دهد. بعد از آن، `original` بازنویسی می‌شود و دیگر قابل
 * اتکا نیست.
 */
class PageObserver
{
    public function updated(Page $page): void
    {
        if (! $page->wasChanged('status')) {
            return;
        }

        $from = (string) $page->getRawOriginal('status');
        $to = (string) $page->status;

        if ($from === $to) {
            return;
        }

        if ($to === Page::STATUS_PUBLISHED) {
            WebhookDispatcher::pagePublished($page);

            return;
        }

        // فقط «منتشرشده ← پیش‌نویس» یک لغوِ انتشار است. تغییرِ وضعیتِ دیگر
        // (مثلاً صفحه‌ای که هرگز منتشر نشد) رویدادِ عمومی نیست.
        if ($from === Page::STATUS_PUBLISHED) {
            WebhookDispatcher::pageUnpublished($page);
        }
    }
}