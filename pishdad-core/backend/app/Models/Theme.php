<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** قالب سایت مشترک نصب (تسک ۴.۲): user_id سازنده/حسابرسی؛ فقط یک قالب فعال در هر لحظه. */
class Theme extends Model
{
    protected $fillable = [
        'user_id', 'name', 'slug', 'version',
        'active', 'signature_valid', 'review_status', 'review_note',
        'submitted_at', 'reviewed_at', 'manifest', 'path',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'signature_valid' => 'boolean',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'manifest' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
