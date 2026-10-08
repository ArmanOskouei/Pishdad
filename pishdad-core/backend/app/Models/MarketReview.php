<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WF-H18 — امتیاز/نظر خریدارِ تأییدشده روی یک آیتم بازار.
 *
 * چرخه: pending → approved|rejected در بازبینی مرکزی. فقط approved به‌صورت
 * عمومی در صفحهٔ جزئیات و در میانگین امتیاز شمرده می‌شود.
 */
class MarketReview extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    public const MIN_RATING = 1;

    public const MAX_RATING = 5;

    protected $fillable = [
        'plugin_id', 'user_id', 'rating', 'comment', 'status', 'moderation_note', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(Plugin::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
