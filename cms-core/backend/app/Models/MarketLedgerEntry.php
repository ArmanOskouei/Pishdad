<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketLedgerEntry extends Model
{
    public const KIND_SALE = 'sale';

    public const KIND_PLATFORM_FEE = 'platform_fee';

    public const KIND_PUBLISHER_SHARE = 'publisher_share';

    public const KIND_PAYOUT = 'payout';

    protected $fillable = [
        'order_id', 'plugin_id', 'publisher_key_id', 'kind', 'amount', 'currency', 'meta',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketOrder::class, 'order_id');
    }

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(Plugin::class);
    }

    public function publisherKey(): BelongsTo
    {
        return $this->belongsTo(PublisherKey::class, 'publisher_key_id');
    }
}
