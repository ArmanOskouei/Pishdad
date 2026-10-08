<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevalidateLog extends Model
{
    protected $fillable = ['page_id', 'tags', 'nonce', 'signature'];

    protected function casts(): array
    {
        return ['tags' => 'array'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
