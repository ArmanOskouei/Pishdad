<?php

namespace App\Models;

use App\Services\Market\CoreMajor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketLicense extends Model
{
    protected $fillable = [
        'user_id', 'plugin_id', 'order_id', 'valid_until_major', 'active',
        'delivered_at', 'delivered_checksum',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'delivered_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(Plugin::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketOrder::class, 'order_id');
    }

    /**
     * K0.9 — valid until the end of the current core MAJOR; minor/patch free.
     * Only the major number is compared, so 1.5.x -> 1.9.0 keeps working.
     */
    public function coversCore(?string $coreVersion = null): bool
    {
        if (! $this->active) {
            return false;
        }

        return CoreMajor::of($coreVersion ?? CoreMajor::currentVersion()) === (int) $this->valid_until_major;
    }
}
