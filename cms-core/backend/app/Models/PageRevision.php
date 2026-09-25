<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageRevision extends Model
{
    protected $fillable = [
        'page_id', 'version', 'blocks', 'meta', 'created_by', 'note',
    ];

    protected function casts(): array
    {
        return ['blocks' => 'array', 'meta' => 'array'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
