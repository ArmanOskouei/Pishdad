<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CoreVersion extends Model
{
    protected $fillable = ['version', 'notes', 'is_latest', 'released_at'];

    protected function casts(): array
    {
        return ['is_latest' => 'boolean', 'released_at' => 'datetime'];
    }
}
