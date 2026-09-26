<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UpdateRolloutItem extends Model
{
    protected $table = 'central_update_rollout_items';

    protected $fillable = [
        'rollout_id', 'client_id', 'from_version', 'to_version',
        'previous_version', 'status', 'batch_index',
    ];

    public function rollout(): BelongsTo
    {
        return $this->belongsTo(UpdateRollout::class, 'rollout_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
