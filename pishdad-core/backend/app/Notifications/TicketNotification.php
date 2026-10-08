<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * تسک ۵.۳ — اعلان داخلی تیکت (کانال database).
 * TODO: کانال پیامک (پس از انتخاب پنل پیامکی) — فعلاً فقط رکورد داخلی.
 */
class TicketNotification extends Notification
{
    use Queueable;

    public function __construct(
        private Ticket $ticket,
        private TicketMessage $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_subject' => $this->ticket->subject,
            'message_id' => $this->message->id,
            'author_type' => $this->message->author_type,
            'excerpt' => mb_substr($this->message->body, 0, 140),
            // TODO پیامک: پس از اتصال پنل پیامکی، همین‌جا via += ['sms'].
        ];
    }
}
