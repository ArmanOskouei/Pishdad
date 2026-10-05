<?php

namespace Pishdad\Plugins\Subscription\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * L-B12 — فاکتورِ مشتریِ محلی. جدولش `subscription_invoices` است.
 */
final class Invoice extends Model
{
    protected $table = 'subscription_invoices';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
