<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    protected $table = 'central_subscriptions';

    public const ACTIVE = 'active';

    public const PAST_DUE = 'past_due';

    public const SUSPENDED = 'suspended';

    public const DELETED = 'deleted';

    protected $fillable = [
        'client_id', 'site_id', 'plan_id', 'status',
        'due_at', 'suspended_at', 'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'suspended_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'subscription_id');
    }

    /** روز باقی‌مانده تا حذف نهایی (سقف ۷ روز از لحظه تعلیق). */
    public function daysRemaining(): int
    {
        if ($this->status !== self::SUSPENDED || ! $this->suspended_at) {
            return 0;
        }

        $purgeDays = (int) config('billing.purge_after_suspended_days', 7);
        // نکته Carbon 3: diffInDays float برمی‌گرداند — به روز کامل floor می‌کنیم.
        $elapsedDays = (int) $this->suspended_at->diffInDays(now());

        return max(0, $purgeDays - $elapsedDays);
    }
}
