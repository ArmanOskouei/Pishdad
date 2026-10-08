<?php

namespace App\Observers;

use App\Models\Ticket;
use App\Services\Webhooks\WebhookDispatcher;

/**
 * WF-L2 — وب‌هوکِ «تیکت تازه»، از راه رویدادِ مدل.
 *
 * ⭐ چرا این observer ارزشِ خودش را دارد: تیکت **سه** جای متفاوت ساخته می‌شود
 * — فرمِ تماسِ عمومی، پنلِ مدیر، و فرم‌های سازنده (`FormSubmissionService`). هر
 * سه به `Ticket::query()->create(...)` می‌رسند، پس `created` تنها جایی است که
 * هر سه را بدون دست‌زدن به هیچ‌کدام می‌گیرد. یک خط در هر کنترلر سه خطِ موازی
 * بود که فردا یکی‌شان جا می‌ماند.
 */
class TicketObserver
{
    public function created(Ticket $ticket): void
    {
        WebhookDispatcher::ticketCreated($ticket);
    }
}