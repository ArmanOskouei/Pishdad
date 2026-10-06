<?php

namespace App\Services\Market;

use App\Models\MarketLicense;
use App\Models\MarketOrder;
use App\Models\MarketReview;
use App\Models\Plugin;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * WF-H18 — امتیاز و نظرات آیتم بازار + شمار «نصب فعال».
 *
 * ## چرا «خریدار تأییدشده» از دو منبع می‌آید
 *
 * لایسنسِ فعال یعنی کاربر افزونه را نصب/خرید کرده. ولی خریدارِ پولی ممکن است
 * پیش از نصب هم بخواهد نظر بدهد؛ برای او سفارشِ `paid` هم معتبر است. هیچ‌کدام
 * ساخته نمی‌شود — هر دو از رکوردهای واقعی خوانده می‌شوند.
 *
 * ## «نصب فعال» چطور شمرده می‌شود
 *
 * تعداد لایسنس‌های `active` روی همان افزونه. عددی ساخته یا حدس زده نمی‌شود؛
 * فقط وقتی داده‌ای نیست، صفر برمی‌گردد.
 */
class MarketReviewService
{
    /** آیا کاربر مجاز به ثبت نظر است؟ (لایسنس فعال یا سفارش پرداخت‌شده) */
    public function hasPurchased(Plugin $plugin, User $user): bool
    {
        $license = MarketLicense::query()
            ->where('user_id', $user->id)
            ->where('plugin_id', $plugin->id)
            ->first();

        if ($license !== null && $license->coversCore()) {
            return true;
        }

        return MarketOrder::query()
            ->where('user_id', $user->id)
            ->where('plugin_id', $plugin->id)
            ->where('status', MarketOrder::STATUS_PAID)
            ->exists();
    }

    public function myReview(Plugin $plugin, User $user): ?MarketReview
    {
        return MarketReview::query()
            ->where('plugin_id', $plugin->id)
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * ثبت نظر. فقط برای خریدارانِ تأییدشده و تنها یک‌بار برای هر افزونه.
     *
     * @throws MarketException 403 (خریدار نیست) | 422 (امتیاز نامعتبر) | 409 (تکراری).
     */
    public function submit(Plugin $plugin, User $user, int $rating, ?string $comment): MarketReview
    {
        if (! $this->hasPurchased($plugin, $user)) {
            throw new MarketException('فقط خریدارانِ تأییدشده می‌توانند امتیاز و نظر ثبت کنند.', 403);
        }

        if ($rating < MarketReview::MIN_RATING || $rating > MarketReview::MAX_RATING) {
            throw new MarketException('امتیاز باید بین ۱ تا ۵ باشد.', 422);
        }

        if ($this->myReview($plugin, $user) !== null) {
            throw new MarketException('برای این افزونه قبلاً نظر ثبت کرده‌اید.', 409);
        }

        $comment = is_string($comment) ? trim($comment) : null;

        return MarketReview::query()->create([
            'plugin_id' => $plugin->id,
            'user_id' => $user->id,
            'rating' => $rating,
            'comment' => $comment === '' ? null : $comment,
            'status' => MarketReview::STATUS_PENDING,
        ]);
    }

    /**
     * نظرهای تأییدشدهٔ یک افزونه، تازه‌ترین اول.
     *
     * @return array<int, array{id: int, rating: int, comment: string|null, author: string|null, created_at: string|null}>
     */
    public function approvedFor(Plugin $plugin, int $limit = 50): array
    {
        return MarketReview::query()
            ->where('plugin_id', $plugin->id)
            ->where('status', MarketReview::STATUS_APPROVED)
            ->with('user:id,name')
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (MarketReview $r) => [
                'id' => $r->id,
                'rating' => (int) $r->rating,
                'comment' => $r->comment,
                'author' => $r->user?->name,
                'created_at' => $r->reviewed_at?->toIso8601String() ?? $r->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * میانگین/شمار امتیازِ تأییدشده + نصب فعال — همه از دادهٔ واقعی.
     *
     * @return array{rating: float|null, rating_count: int, active_installs: int}
     */
    public function stats(Plugin $plugin): array
    {
        $row = MarketReview::query()
            ->where('plugin_id', $plugin->id)
            ->where('status', MarketReview::STATUS_APPROVED)
            ->selectRaw('COUNT(*) AS c, AVG(rating) AS a')
            ->first();

        $count = (int) ($row?->c ?? 0);

        return [
            'rating' => $count > 0 ? round((float) $row->a, 1) : null,
            'rating_count' => $count,
            'active_installs' => $this->activeInstalls($plugin),
        ];
    }

    /** شمار نصب‌های فعالِ افزونه — از لایسنس‌های فعال، نه عدد ثابت. */
    public function activeInstalls(Plugin $plugin): int
    {
        return (int) MarketLicense::query()
            ->where('plugin_id', $plugin->id)
            ->where('active', true)
            ->count();
    }

    /** صف بازبینی مرکزی. */
    public function pending(int $perPage = 20, ?string $status = null): LengthAwarePaginator
    {
        $status = in_array($status, MarketReview::STATUSES, true) ? $status : MarketReview::STATUS_PENDING;

        return MarketReview::query()
            ->where('status', $status)
            ->with([
                'plugin:id,name,slug,version',
                'user:id,name,email',
            ])
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @throws MarketException */
    public function approve(MarketReview $review, ?string $note = null): MarketReview
    {
        if ($review->status !== MarketReview::STATUS_PENDING) {
            throw new MarketException('فقط نظرهای در انتظار را می‌توان تأیید کرد.', 422);
        }

        $review->forceFill([
            'status' => MarketReview::STATUS_APPROVED,
            'moderation_note' => $note,
            'reviewed_at' => now(),
        ])->save();

        return $review->fresh();
    }

    /** @throws MarketException */
    public function reject(MarketReview $review, ?string $note = null): MarketReview
    {
        if ($review->status !== MarketReview::STATUS_PENDING) {
            throw new MarketException('فقط نظرهای در انتظار را می‌توان رد کرد.', 422);
        }

        $review->forceFill([
            'status' => MarketReview::STATUS_REJECTED,
            'moderation_note' => $note,
            'reviewed_at' => now(),
        ])->save();

        return $review->fresh();
    }
}
