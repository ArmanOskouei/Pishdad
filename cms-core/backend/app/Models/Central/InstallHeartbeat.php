<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class InstallHeartbeat extends Model
{
    protected $table = 'central_install_heartbeats';

    protected $fillable = ['site_id', 'status', 'version', 'payload', 'last_seen_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'last_seen_at' => 'datetime'];
    }

    public function isSilent(int $minutes): bool
    {
        return ! $this->last_seen_at || $this->last_seen_at->lt(now()->subMinutes($minutes));
    }
}
