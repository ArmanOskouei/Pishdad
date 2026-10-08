<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * پریست ستون کناری — واحد استفاده مجدد با ارجاع زنده (نه کپی).
 * blocks = آرایه بلوک‌ها [{type, data}]؛ ویرایش آن همه صفحات
 * استفاده‌کننده را هم‌زمان به‌روز می‌کند (resolve در خروجی عمومی).
 */
class SidePreset extends Model
{
    public const SIDE_LEFT = 'left';

    public const SIDE_RIGHT = 'right';

    public const SIDES = [self::SIDE_LEFT, self::SIDE_RIGHT];

    protected $fillable = ['user_id', 'name', 'side', 'blocks'];

    protected function casts(): array
    {
        return ['blocks' => 'array'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function leftPages(): HasMany
    {
        return $this->hasMany(Page::class, 'left_preset_id');
    }

    public function rightPages(): HasMany
    {
        return $this->hasMany(Page::class, 'right_preset_id');
    }

    /** تعداد صفحات نصب که از این پریست استفاده می‌کنند. */
    public function usageCount(): int
    {
        return Page::query()
            ->where(fn ($q) => $q->where('left_preset_id', $this->id)->orWhere('right_preset_id', $this->id))
            ->count();
    }
}
