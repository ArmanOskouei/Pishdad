<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PluginHook extends Model
{
    protected $fillable = ['plugin_id', 'hook', 'handler'];

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(Plugin::class);
    }
}
