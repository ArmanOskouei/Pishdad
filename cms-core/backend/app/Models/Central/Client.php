<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Client extends Model
{
    protected $table = 'central_clients';

    protected $fillable = [
        'name', 'domain', 'tier', 'status', 'owner_user_id', 'site_id', 'health',
        'install_token', 'current_version',
    ];

    protected $hidden = ['install_token'];

    protected static function booted(): void
    {
        // توکن per-install نصب (تسک ۵.۵): هنگام ساخت تولید می‌شود، هرگز در لیست‌ها لو نمی‌رود.
        static::creating(function (Client $client) {
            if (empty($client->install_token)) {
                $client->install_token = bin2hex(random_bytes(32));
            }
        });
    }

    protected function casts(): array
    {
        return ['health' => 'array'];
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class, 'client_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'client_id');
    }

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class, 'client_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_user_id');
    }

    /** سلامت نمایشی برای فیلتر لیست: از وضعیت اشتراک استخراج می‌شود. */
    public function healthStatus(): string
    {
        $status = $this->subscription?->status ?? $this->status;

        return match ($status) {
            'active' => 'healthy',
            'past_due' => 'warning',
            'suspended' => 'critical',
            default => 'unknown',
        };
    }
}
