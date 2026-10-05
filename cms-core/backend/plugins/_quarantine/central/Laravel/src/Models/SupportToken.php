<?php

namespace Pishdad\Plugins\Central\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportToken extends Model
{
    protected $table = 'central_support_tokens';

    protected $fillable = ['client_id', 'token_hash', 'expires_at', 'used_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function isConsumable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
