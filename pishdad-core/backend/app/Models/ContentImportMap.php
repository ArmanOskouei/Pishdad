<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * WF-C6 — نگاشت شناسهٔ منبعِ درون‌بری (وردپرس) به ردیفِ هسته.
 *
 * کلیدِ idempotencyِ درون‌بری: پیش از ساخت هر مورد با `findTarget` چک می‌شود
 * که آیا این شناسهٔ منبع قبلاً نگاشته شده یا نه.
 */
class ContentImportMap extends Model
{
    protected $fillable = ['source', 'source_id', 'target_type', 'target_id'];

    protected function casts(): array
    {
        return ['target_id' => 'integer'];
    }

    /** شناسهٔ مقصدِ یک شناسهٔ منبع، یا null اگر قبلاً نگاشته نشده باشد. */
    public static function findTarget(string $source, string $sourceId, string $targetType): ?int
    {
        $row = static::query()
            ->where('source', $source)
            ->where('source_id', $sourceId)
            ->where('target_type', $targetType)
            ->first();

        return $row ? (int) $row->target_id : null;
    }
}
