<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** WF-H6 — برچسب رسانه (چندبه‌چند با media). */
class MediaTag extends Model
{
    protected $fillable = ['name', 'color'];

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'media_media_tag', 'media_tag_id', 'media_id');
    }
}
