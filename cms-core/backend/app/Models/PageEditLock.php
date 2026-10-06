<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * WF-H3 — قفلِ نرمِ ویرایشِ صفحه (soft lock).
 *
 * قفل به‌معنای «منع» نیست؛ هشدار است: اگر مدیرِ دیگری همان صفحه را باز کرده،
 * ویرایشگر بنر نشان می‌دهد و ذخیرهٔ خودکار را متوقف می‌کند تا overwrite نشود.
 * قفل با heartbeat تازه می‌ماند و پس از TTL خودبه‌خود کهنه می‌شود.
 */
class PageEditLock extends Model
{
    /** فاصلهٔ heartbeat فرانت ۲۵ ثانیه است؛ TTL ‏۹۰ ثانیه یعنی سه ضربِ ازدست‌رفته. */
    public const TTL_SECONDS = 90;

    protected $fillable = ['page_id', 'user_id', 'heartbeat_at'];

    protected function casts(): array
    {
        return ['heartbeat_at' => 'datetime'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** قفل کهنه = heartbeat قدیمی‌تر از TTL (قابل تصاحب بدون force). */
    public function isStale(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        return $this->heartbeat_at === null
            || $this->heartbeat_at->lt($now->copy()->subSeconds(self::TTL_SECONDS));
    }
}
