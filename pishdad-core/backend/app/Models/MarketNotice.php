<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketNotice extends Model
{
    public const TYPE_YANK = 'yank';

    public const TYPE_UNYANK = 'unyank';

    public const TYPE_INFO = 'info';

    protected $fillable = ['plugin_id', 'type', 'message'];

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(Plugin::class);
    }
}
