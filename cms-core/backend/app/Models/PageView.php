<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WF-H12 — یک بازدیدِ صفحه در سایت عمومی.
 *
 * خودمیزبان و بدون کوکی: هیچ IP و user-agent خامی ذخیره نمی‌شود. `device` و
 * `country` مشتق‌شده‌اند و `country` فقط وقتی پر می‌شود که لبه واقعاً آن را
 * اعلام کرده باشد. `updated_at` نداریم چون رکورد فقط یک‌بار نوشته می‌شود.
 */
class PageView extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'page_id', 'path', 'slug', 'referrer', 'country', 'device',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
