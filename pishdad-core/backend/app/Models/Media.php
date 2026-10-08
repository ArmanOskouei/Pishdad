<?php

namespace App\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** رکورد فایل (تسک ۱.۱): باینری در MinIO/S3، سافت‌دیلیت = سطل زباله. */
class Media extends Model
{
    use SoftDeletes;

    protected $table = 'media';

    protected $fillable = [
        'user_id', 'folder_id', 'disk', 'path', 'original_name', 'mime', 'size', 'alt', 'variants',
        'focal_x', 'focal_y',
    ];

    /**
     * `url` همیشه در خروجی می‌آید تا فرانت مجبور نباشد از پایهٔ ثابتِ S3
     * استفاده کند — پایه بر پایهٔ دیسکِ خود رکورد انتخاب می‌شود.
     *
     * @var list<string>
     */
    protected $appends = ['url'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'variants' => 'array',
            // WF-L1 — نرمالِ 0..1؛ در خروجی JSON هم عدد می‌ماند، نه رشته.
            'focal_x' => 'float',
            'focal_y' => 'float',
        ];
    }

    public function getUrlAttribute(): ?string
    {
        return MediaUrl::for((string) $this->disk, (string) $this->path);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(MediaTag::class, 'media_media_tag', 'media_id', 'media_tag_id');
    }
}
