<?php

namespace Pishdad\Plugins\Central\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    protected $table = 'central_subscriptions';

    public const ACTIVE = 'active';

    public const PAST_DUE = 'past_due';

    public const SUSPENDED = 'suspended';

    public const DELETED = 'deleted';

    protected $fillable = [
        'client_id', 'site_id', 'plan_id', 'status',
        'due_at', 'suspended_at', 'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'suspended_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'subscription_id');
    }

    /**
     * ⭐ روز باقی‌مانده تا سررسیدِ دورهٔ پرداخت.
     *
     * ## چرا این متد وجود دارد و چرا معنایش عوض شد
     *
     * نسخهٔ اول فقط یک کار می‌کرد: «چند روز تا حذف نهایی، برای اشتراکِ
     * معلق». ولی داشبوردِ پنل همین متد را به‌عنوان «چند روز تا پایان دورهٔ
     * پرداخت» مصرف می‌کرد — و چون برای هر اشتراکِ `active` صفر برمی‌گرداند،
     * **هر مشترکِ فعال همیشه «۰ روز مانده» و «۱۰۰٪ گذشته» می‌دید** در حالی
     * که تاریخ سررسیدش ۲۸ روز دورتر بود.
     *
     * آن تناقض یک باگِ واقعی و دیده‌شدنی بود: کارت هم‌زمان «۲۸ روز مانده»
     * و «۰ روز باقی‌مانده» را نشان می‌داد.
     *
     * حالا دو مفهومِ کاملاً متفاوت، دو متدِ جدا دارند و هیچ‌کس نمی‌تواند
     * اشتباهشان را مصرف کند. نام قدیمیِ مبهم هم به `purgeDaysRemaining()`
     * تغییر کرد تا مصرف‌کنندهٔ قدیمی **خطای کامپایل** بدهد، نه رفتار غلط.
     */
    public function daysUntilDue(): int
    {
        if ($this->due_at === null) {
            return 0;
        }

        // Carbon 3: `diffInDays` می‌تواند منفی بدهد (سررسید گذشته). برای
        // نمایش «چند روز مانده» هرگز منفی نباید دیده شود.
        $days = (int) ceil(now()->startOfDay()->diffInDays($this->due_at->startOfDay(), false));

        return max(0, $days);
    }

    /** روز باقی‌مانده تا حذف نهایی (سقف ۷ روز از لحظه تعلیق). */
    public function purgeDaysRemaining(): int
    {
        if ($this->status !== self::SUSPENDED || $this->suspended_at === null) {
            return 0;
        }

        $purgeDays = (int) config('billing.purge_after_suspended_days', 7);
        // نکته Carbon 3: diffInDays float برمی‌گرداند — به روز کامل floor می‌کنیم.
        $elapsedDays = (int) $this->suspended_at->diffInDays(now());

        return max(0, $purgeDays - $elapsedDays);
    }
}
