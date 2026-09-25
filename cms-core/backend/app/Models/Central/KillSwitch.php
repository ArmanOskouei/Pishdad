<?php

namespace App\Models\Central;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * لاگ غیرقابل حذف kill-switch (PLAN.md): هر UPDATE/DELETE در سطح مدل
 * مسدود است؛ فقط INSERT. حذف در سطح دیتابیس هم باید با GRANT محدود شود.
 */
class KillSwitch extends Model
{
    protected $fillable = ['executed_by', 'reason', 'confirmed', 'scope'];

    protected function casts(): array
    {
        return ['confirmed' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }
}
