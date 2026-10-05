<?php

namespace Pishdad\Plugins\Central\Models;

use Illuminate\Database\Eloquent\Model;

class CoreVersion extends Model
{
    protected $table = 'central_core_versions';

    protected $fillable = ['version', 'notes', 'is_latest', 'released_at'];

    protected function casts(): array
    {
        return ['is_latest' => 'boolean', 'released_at' => 'datetime'];
    }
}
