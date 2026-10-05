<?php

namespace Pishdad\Plugins\Central\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Backup extends Model
{
    protected $table = 'central_backups';

    protected $fillable = ['client_id', 'type', 'status', 'path', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
