<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** WF-H2 — لینکِ اشتراکِ پیش‌نویسِ یک صفحه. فقط هشِ توکن ذخیره می‌شود. */
class PageShareLink extends Model
{
    protected $fillable = [
        'page_id', 'revision_id', 'token_hash', 'expires_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(PageRevision::class, 'revision_id');
    }
}
