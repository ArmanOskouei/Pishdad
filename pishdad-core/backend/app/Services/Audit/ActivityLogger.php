<?php

namespace App\Services\Audit;

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Page;

/**
 * WF-H9 — تنها نقطهٔ نوشتنِ گزارش فعالیت محتوا.
 *
 * فقط خلاصه ذخیره می‌شود؛ `diff` هرگز HTML بلوک‌ها را در بر نمی‌گیرد.
 * `user_id` می‌تواند `null` باشد (عملیات سیستمیِ زمان‌بندِ انتشار).
 */
class ActivityLogger
{
    public function log(
        ?int $userId,
        string $action,
        ?string $subjectType,
        ?int $subjectId,
        string $summary,
        ?array $diff = null,
    ): ActivityLog {
        return ActivityLog::query()->create([
            'user_id' => $userId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => $action,
            'summary' => $summary,
            'diff' => $diff,
        ]);
    }

    public function page(?int $userId, string $action, Page $page, string $summary, ?array $diff = null): ActivityLog
    {
        return $this->log($userId, $action, ActivityLog::SUBJECT_PAGE, (int) $page->id, $summary, $diff);
    }

    public function media(?int $userId, string $action, Media $media, string $summary, ?array $diff = null): ActivityLog
    {
        return $this->log($userId, $action, ActivityLog::SUBJECT_MEDIA, (int) $media->id, $summary, $diff);
    }
}
