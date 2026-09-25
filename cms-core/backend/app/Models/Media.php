<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** رکورد فایل (تسک ۱.۱): باینری در MinIO/S3، سافت‌دیلیت = سطل زباله. */
class Media extends Model
{
    use SoftDeletes;

    protected $table = 'media';

    protected $fillable = [
        'user_id', 'disk', 'path', 'original_name', 'mime', 'size', 'alt',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
