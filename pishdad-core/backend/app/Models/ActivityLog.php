<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WF-H9 — یک ردیفِ گزارش فعالیتِ محتوا.
 *
 * `subject_type` نامِ کوتاهِ موضوع است (`page` | `media`) نه FQCN؛ چون
 * morphTo نداریم و مقایسه در کنترلر و فرانت با رشتهٔ کوتاه صریح‌تر است.
 */
class ActivityLog extends Model
{
    protected $table = 'activity_log';

    public const SUBJECT_PAGE = 'page';

    public const SUBJECT_MEDIA = 'media';

    public const ACTION_PAGE_CREATE = 'page.create';

    public const ACTION_PAGE_UPDATE = 'page.update';

    public const ACTION_PAGE_PUBLISH = 'page.publish';

    public const ACTION_PAGE_UNPUBLISH = 'page.unpublish';

    public const ACTION_PAGE_DELETE = 'page.delete';

    public const ACTION_PAGE_RESTORE = 'page.restore';

    public const ACTION_PAGE_FORCE_DELETE = 'page.force_delete';

    public const ACTION_MEDIA_UPLOAD = 'media.upload';

    public const ACTION_MEDIA_REPLACE = 'media.replace';

    public const ACTION_MEDIA_DELETE = 'media.delete';

    protected $fillable = [
        'user_id', 'subject_type', 'subject_id', 'action', 'summary', 'diff',
    ];

    protected function casts(): array
    {
        return ['diff' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** نسخهٔ قابلِ بازگردانی برای این رویداد (فقط رویدادهای صفحه). */
    public function restorableRevisionId(): ?int
    {
        if ($this->subject_type !== self::SUBJECT_PAGE) {
            return null;
        }

        $id = $this->diff['previous_revision_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }
}
