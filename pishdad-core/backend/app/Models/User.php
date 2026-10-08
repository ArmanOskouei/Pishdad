<?php

namespace App\Models;

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
        'telegram_chat_id',
        'notification_daily_digest',
        'avatar_media_id',
        'first_name',
        'last_name',
        'bio',
        'role',
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
            'notification_daily_digest' => 'boolean',
            'recovery_codes' => 'array',
            'avatar_media_id' => 'integer',
        ];
    }

    /** WF-M8 — آیا دست‌کم یکی از نقش‌های این کاربر «۲FA اجباری» را مطالبه می‌کند؟ */
    public function rolesRequireTwoFactor(): bool
    {
        return $this->roles()->where('requires_2fa', true)->exists();
    }
}
