<?php

namespace Pishdad\Plugins\Subscription\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * L-B12 — اشتراکِ کاربرِ محلی. جدولش `subscription_subscriptions` است.
 *
 * `central_client_id` فقط یک **اشارهٔ اختیاری** است: در محیطی که مرکز هم هست
 * پر می‌شود، ولی نبودنش هیچ چیزی را نمی‌شکند.
 */
final class Subscription extends Model
{
    use SoftDeletes;

    protected $table = 'subscription_subscriptions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'suspended_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'subscription_id');
    }

    /** وضعیت مؤثر، مستقل از اینکه رکورد حذف نرم شده باشد یا نه. */
    public function effectiveStatus(): string
    {
        if ($this->trashed()) {
            return 'deleted';
        }

        return in_array($this->status, ['suspended', 'deleted', 'past_due'], true)
            ? $this->status
            : 'active';
    }

    public function isActive(): bool
    {
        return $this->effectiveStatus() === 'active';
    }

    /**
     * سررسیدِ نزدیک — مبنای برچسب `due_soon`.
     *
     * ⚠️ فقط وقتی در بازهٔ *آینده* باشد درست است. یک اشتراکِ سررسیدشده
     * (`due_at` در گذشته) «نزدیک» نیست، «گذشته» است — و نباید برچسبِ
     * «۳۰ روز باقی‌مانده» بگیرد.
     */
    public function isDueSoon(\DateTimeInterface $now, int $days = 14): bool
    {
        if ($this->due_at === null) {
            return false;
        }

        $now = Carbon::instance($now);
        $due = $this->due_at->copy();

        if ($due->lessThan($now)) {
            return false;
        }

        return $due->lessThanOrEqualTo($now->copy()->addDays($days));
    }

    public function remainingDays(\DateTimeInterface $now): int
    {
        if ($this->due_at === null) {
            return 0;
        }

        $now = Carbon::instance($now);

        return (int) max(0, floor($now->diffInDays($this->due_at, false)));
    }
}
