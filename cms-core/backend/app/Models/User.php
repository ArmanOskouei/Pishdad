<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar_media_id',
        'first_name',
        'last_name',
        'bio',
        'role',
        'is_super_admin',
        'is_hidden',
        'google2fa_secret',
        'google2fa_enabled',
        'recovery_codes',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'google2fa_secret',
        'recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'google2fa_enabled' => 'boolean',
            'is_super_admin' => 'boolean',
            'is_hidden' => 'boolean',
            'recovery_codes' => 'array',
            'avatar_media_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Superadmin can never be deleted (PLAN.md: hidden, non-deletable).
        static::deleting(function (User $user) {
            if ($user->is_super_admin) {
                return false;
            }
        });
    }

    /** Regular listings must never include the hidden superadmin. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_hidden', false);
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }
}
