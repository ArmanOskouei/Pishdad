<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WF-M14 — یک نمونهٔ Web Vital از سایت عمومی.
 *
 * بدون کوکی و بدون PII: هیچ شناسهٔ پایدار، IP یا user-agent ذخیره نمی‌شود.
 * `updated_at` نداریم چون رکورد فقط یک‌بار نوشته می‌شود. `metric` یکی از
 * `lcp` (میلی‌ثانیه)، `inp` (میلی‌ثانیه) یا `cls` (بی‌بعد) است.
 */
class PageVital extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'page_id', 'path', 'slug', 'metric', 'value',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'value' => 'float',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
