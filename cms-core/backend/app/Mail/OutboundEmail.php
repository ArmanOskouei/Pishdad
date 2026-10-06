<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\HtmlString;

/**
 * F1.3 — تنها Mailableِ قابلِ تحویل از outbox.
 *
 * ## ⭐ چرا `withSymfonyMessage` و نه `Content(text:)`
 *
 * `Mailable::text()` **نامِ یک view** می‌گیرد، نه متن. یعنی `Content(text: $body)`
 * یعنی «این متن را به‌عنوان نامِ قالب رندر کن» و نتیجه‌اش
 * `View [متن-شما] not found` است — یعنی هر ایمیل `failed` می‌شد و دلیلش
 * هم بی‌ربط به نظر می‌رسید.
 *
 * راهِ درست برای متنِ خام، `withSymfonyMessage()` است: بدنه را مستقیم روی پیام
 * می‌نشاند، بدون resolverِ view. این تنها جایی است که می‌شود تضمین کرد بدنه
 * **همان متن** است — نه رندرِ قالبی که فردا عوض می‌شود.
 *
 * ## ⭐ چرا این‌قدر ساده
 *
 * نه view دارد، نه Blade، نه `markdown()`. این عمدی است:
 *  - render کردنِ view **در لحظهٔ ارسال** اتفاق می‌افتد نه لحظهٔ enqueue. یعنی
 *    اگر قالب خراب شود، خطا در drainer می‌افتد نه جایی که پیام ساخته شد — و
 *    آن‌وقت پیام در retry می‌ماند.
 *  - یک قالبِ HTML برای کانالِ SMS بی‌معنی است ⇒ دو قالب برای یک پیام.
 *
 * اگر روزی ایمیلِ HTML لازم شد، Mailableِ جدا با viewِ خودش — نه قابلیتِ همین.
 */
class OutboundEmail extends Mailable
{
    use Queueable;

    /**
     * نام‌ها **عمداً** `subject`/`body` نیستند: `Illuminate\Mail\Mailable`
     * خودش `$subject` دارد و propertyِ readonly نمی‌تواند دوباره اعلام شود
     * (`Cannot redeclare`). این یک fatal است، نه یک هشدار.
     */
    public function __construct(
        public readonly string $mailSubject,
        public readonly string $mailBody,
        public readonly bool $isHtml = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    /**
     * ⭐ `['raw' => …]` مسیرِ متنِ خامِ خودِ لاروال است.
     *
     * `Mailer::parseView()` سه کلید را می‌شناسد و `addContent()` برای `raw`
     * دقیقاً `$message->text($raw)` می‌زند — بدون resolverِ view و بدون
     * تولیدِ HTML. این تنها راهِ پشتیبانی‌شده برای «متنِ خام» است.
     *
     * گزینه‌های ردشده و دلیلشان:
     *  - `Content(text: $body)` → `Mailable::text()` نامِ **view** می‌گیرد ⇒
     *    `View [متن] not found` و هر ایمیل `failed`.
     *  - `withSymfonyMessage()` تنها callback می‌افزاید؛ `buildView()` باز هم
     *    فراخوانی می‌شود و `Invalid view.` می‌دهد.
     *  - `Content(html: …)` مسیرِ Mailable است؛ اینجا عمداً از کلیدِ `html`
     *    فقط وقتی `isHtml` روشن باشد استفاده می‌شود (WF-H11 — قالب‌های HTML).
     *
     * @return array<string, string>
     */
    protected function buildView(): array
    {
        return $this->isHtml
            ? ['html' => new HtmlString($this->mailBody)]
            : ['raw' => $this->mailBody];
    }
}
