<?php

namespace Pishdad\Plugins\Central\Models;

use Illuminate\Database\Eloquent\Model;

class CentralLog extends Model
{
    public const LEVELS = ['debug', 'info', 'warning', 'error', 'critical'];

    protected $fillable = ['site_id', 'level', 'message', 'context'];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    /** سقف نگهداری لاگ متمرکز: ۳۰ روز (OWASP: محدودسازی داده). */
    public static function retentionCutoff(): \DateTimeInterface
    {
        return now()->subDays(30);
    }
}
