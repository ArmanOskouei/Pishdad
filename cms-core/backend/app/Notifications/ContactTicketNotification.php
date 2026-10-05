<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * E6 — «یک نفر از فرم تماس پیام داد» به‌صورت اعلان داخلِ پنل.
 *
 * ## چرا اعلان دیتابیسی و نه ایمیل؟
 *
 * چون در نصبِ عمومی **هیچ SMTP مطمئنی وجود ندارد** (F0.10 فقط پیکربندی است و
 * E2 هنوز در انتظارِ اعتبارنامهٔ واقعی). ایمیلی که به SMTP ناموجود فرستاده شود
 * یا گم می‌شود یا worker را تا `timeout` نگه می‌دارد. اعلانِ دیتابیسی در همان
 * جدولی می‌نشیند که drawer پنل از آن می‌خواند، پس «پیام فرم تماس رسید» بدون
 * هیچ سرویس بیرونی و همیشه دیده می‌شود.
 *
 * اگر بعداً outbox اضافه شد، همین کلاس `via()` را عوض می‌کند و بقیهٔ مسیر
 * (متن‌ها، شدت، گیرنده) دست‌نخورده می‌ماند.
 */
class ContactTicketNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $name,
        private string $email,
        private string $subject,
        private ?int $ticketId,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'پیام جدید از فرم تماس',
            'body' => $this->name.' ('.$this->email.') — '.$this->subject,
            'severity' => 'info',
            'notification_id' => null,
            'action_label' => null,
        ];
    }

    public function ticketId(): ?int
    {
        return $this->ticketId;
    }
}
