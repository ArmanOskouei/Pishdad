<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** WF-H6 — پوشهٔ درختی رسانه (parent_id خودارجاع). */
class MediaFolder extends Model
{
    protected $fillable = ['name', 'parent_id'];

    protected function casts(): array
    {
        return ['parent_id' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'folder_id');
    }
}
