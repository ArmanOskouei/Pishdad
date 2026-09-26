<?php

namespace App\Models\Central;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperatorActivity extends Model
{
    protected $table = 'central_operator_activities';

    protected $fillable = ['user_id', 'action', 'ip', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(?User $user, string $action, array $meta = [], ?string $ip = null): self
    {
        return self::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'ip' => $ip ?? request()->ip(),
            'meta' => $meta,
        ]);
    }
}
