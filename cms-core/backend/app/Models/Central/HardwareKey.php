<?php

namespace App\Models\Central;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HardwareKey extends Model
{
    protected $table = 'central_operator_hardware_keys';

    protected $fillable = ['user_id', 'name', 'credential'];

    protected $hidden = ['credential'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
