<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * WF-M11 — یک عبارت جستجوی عمومی.
 *
 * فقط متن عبارت، شمار نتیجه و زمان؛ بدون PII. `updated_at` نداریم چون رکورد
 * فقط یک‌بار نوشته می‌شود.
 */
class SearchQuery extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['query', 'results_count'];

    protected function casts(): array
    {
        return [
            'results_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
