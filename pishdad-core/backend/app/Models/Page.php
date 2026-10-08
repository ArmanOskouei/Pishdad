<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** صفحه (تسک ۱.۱): blocks = پیش‌نویس جاری؛ انتشار فقط via published_revision_id. */
class Page extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    /** F4.5 — زبان پیش‌فرضِ محتوا (زبانِ اصلی نصب). */
    public const LOCALE_DEFAULT = 'fa';

    protected $fillable = [
        'user_id', 'title', 'slug', 'locale', 'status', 'is_single', 'blocks', 'meta',
        'published_revision_id', 'published_at', 'scheduled_at',
        'left_preset_id', 'right_preset_id', 'left_enabled', 'right_enabled',
    ];

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'meta' => 'array',
            'published_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'is_single' => 'boolean',
            'left_enabled' => 'boolean',
            'right_enabled' => 'boolean',
        ];
    }

    public function leftPreset(): BelongsTo
    {
        return $this->belongsTo(SidePreset::class, 'left_preset_id');
    }

    public function rightPreset(): BelongsTo
    {
        return $this->belongsTo(SidePreset::class, 'right_preset_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PageRevision::class)->orderBy('version');
    }

    /** WF-H3 — قفلِ نرمِ ویرایشِ هم‌زمانِ این صفحه. */
    public function editLock(): HasOne
    {
        return $this->hasOne(PageEditLock::class);
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(PageRevision::class, 'published_revision_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_revision_id !== null;
    }

    /** اسنپشات append-only جدید و جلو بردن پیش‌نویس. */
    public function snapshot(array $blocks, ?array $meta, ?int $userId, ?string $note = null): PageRevision
    {
        $version = (int) ($this->revisions()->max('version') ?? 0) + 1;

        $revision = $this->revisions()->create([
            'version' => $version,
            'blocks' => $blocks,
            'meta' => $meta,
            'created_by' => $userId,
            'note' => $note,
        ]);

        $this->forceFill(['blocks' => $blocks, 'meta' => $meta])->save();

        return $revision;
    }
}
