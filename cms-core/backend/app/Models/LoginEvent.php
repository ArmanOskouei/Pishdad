<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** WF-H8 — یک تلاشِ ورود (موفق/ناموفق) برای کارت امنیتِ پروفایل. */
class LoginEvent extends Model
{
    protected $fillable = [
        'user_id',
        'email',
        'ip',
        'user_agent',
        'successful',
    ];

    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
        ];
    }
}
