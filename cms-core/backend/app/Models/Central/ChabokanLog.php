<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

/** ردپای حسابرسی اکشن‌های چابکان (تسک ۳.۳): همه اکشن‌ها این‌جا لاگ می‌شوند. */
class ChabokanLog extends Model
{
    protected $table = 'central_chabokan_logs';

    protected $fillable = ['action', 'service', 'user_id', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
