<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Page;
use App\Models\PageRevision;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WF-H9 — گزارش فعالیت محتوا.
 *
 * خواندن پشت `perm:users.view` است: ماژول در `config/modules.php` کلیدش
 * `users` است (نه `managers`) و پرمیشنِ واقعی `users.view` ساخته می‌شود.
 * بازگردانی پشت `perm:pages.edit` و با واگذاری به `PageController::restore`
 * انجام می‌شود تا تاریخچهٔ append-only و ثبتِ رویداد یک مسیرِ واحد بمانند.
 */
class ActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'action' => ['nullable', 'string', 'max:40'],
            'subject_type' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ActivityLog::query()->with('user:id,name,email');

        if (! empty($validated['user_id'])) {
            $query->where('user_id', (int) $validated['user_id']);
        }
        if (! empty($validated['action'])) {
            $query->where('action', $validated['action']);
        }
        if (! empty($validated['subject_type'])) {
            $query->where('subject_type', $validated['subject_type']);
        }
        if (! empty($validated['from'])) {
            $query->where('created_at', '>=', Carbon::parse($validated['from'])->startOfDay());
        }
        if (! empty($validated['to'])) {
            $query->where('created_at', '<=', Carbon::parse($validated['to'])->endOfDay());
        }

        $paginator = $query->latest('id')->paginate((int) ($validated['per_page'] ?? 20));

        $paginator->getCollection()->transform(fn (ActivityLog $a): array => [
            'id' => $a->id,
            'user_id' => $a->user_id,
            'user' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name] : null,
            'subject_type' => $a->subject_type,
            'subject_id' => $a->subject_id,
            'action' => $a->action,
            'summary' => $a->summary,
            'diff' => $a->diff,
            'restorable_revision_id' => $a->restorableRevisionId(),
            'created_at' => $a->created_at?->toIso8601String(),
        ]);

        return response()->json($paginator);
    }

    /**
     * WF-H9 — بازگردانیِ نسخهٔ قبل از یک رویدادِ صفحه.
     *
     * همان مکانیزمِ `PageController::restore`: کپیِ بلوک‌های نسخهٔ قدیمی در
     * یک revision جدید (تاریخچه حفظ می‌شود). خودِ `restore` رویداد را ثبت
     * می‌کند، پس اینجا لاگِ جداگانه‌ای لازم نیست.
     */
    public function restore(Request $request, ActivityLog $activity): JsonResponse
    {
        if ($activity->subject_type !== ActivityLog::SUBJECT_PAGE || ! $activity->subject_id) {
            return response()->json(['message' => 'فقط رویدادهای صفحه قابل بازگردانی‌اند.'], 422);
        }

        $revisionId = $activity->restorableRevisionId();
        if ($revisionId === null) {
            return response()->json(['message' => 'نسخهٔ قبلی برای این رویداد ثبت نشده است.'], 422);
        }

        $page = Page::query()->find($activity->subject_id);
        if (! $page) {
            return response()->json(['message' => 'صفحهٔ این رویداد دیگر موجود نیست.'], 404);
        }

        $revision = PageRevision::query()
            ->where('page_id', $page->id)
            ->whereKey($revisionId)
            ->firstOrFail();

        return app(PageController::class)->restore($request, $page, $revision);
    }
}
