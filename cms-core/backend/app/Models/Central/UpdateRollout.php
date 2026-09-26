<?php

namespace App\Models\Central;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UpdateRollout extends Model
{
    protected $table = 'central_update_rollouts';

    protected $fillable = ['version', 'status', 'batch_size', 'created_by'];

    public function items(): HasMany
    {
        return $this->hasMany(UpdateRolloutItem::class, 'rollout_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
