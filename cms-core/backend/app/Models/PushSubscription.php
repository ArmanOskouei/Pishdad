<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک اشتراک Web Push — یعنی «این مرورگر، این دستگاه».
 *
 * ## چرا این مدل با بقیه فرق دارد
 *
 * اعلان‌های داخلی (`notifications`) یک **کاربرِ شناخته‌شده** دارند و در
 * پنل می‌مانند. ولی بازدیدکنندهٔ سایت عمومی حساب ندارد، پس جایی برای نگه‌داری
 * مقصدِ push او وجود نداشت. این مدل همان «کاربرِ بی‌نام» است.
 *
 * ## چرا `endpoint` کلیدِ یکاست
 *
 * هر اشتراک یک endpoint یکتا دارد. اگر کاربر روی دو دستگاه باشد دو
 * endpoint دارد و **هر دو باید کار کنند** — پس یکتایی باید روی endpoint
 * باشد، نه روی چیز دیگر (کاربر نداریم).
 *
 * @property int $id
 * @property string $endpoint
 * @property string|null $p256dh
 * @property string|null $auth
 * @property string|null $vapid_public_key
 * @property int|null $user_id
 * @property string|null $locale
 * @property string|null $topic
 */
class PushSubscription extends Model
{
    protected $table = 'push_subscriptions';

    protected $fillable = [
        'endpoint',
        'p256dh',
        'auth',
        'vapid_public_key',
        'user_id',
        'locale',
        'topic',
    ];

    /**
     * `endpoint` یک URL است که می‌تواند طولانی باشد ⇒ ستون `text`.
     *
     * ⚠️ ولی **هیچ‌وقت** نباید در پیام خطا یا لاگ کامل چاپ شود: همان URL
     * تنها چیزی است که به ما اجازه می‌دهد به آن دستگاه push بفرستیم.
     */
    protected $hidden = ['endpoint', 'p256dh', 'auth'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
        ];
    }

    /** رابطه با کاربر — برای مدیری که وارد شده. nullable چون بازدیدکننده حساب ندارد. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * آیا این اشتراک کلید لازم برای رمزنگاری دارد؟
     *
     * بدون `p256dh` و `auth` نمی‌توان بدنه را طبق RFC 8291 رمز کرد؛
     * سرویس push در آن حالت رمزنگاری‌نشده می‌گیرد (که مجاز است ولی متن
     * در مسیر TLS قابل خواندن می‌ماند).
     */
    public function canEncrypt(): bool
    {
        return $this->p256dh !== null && $this->p256dh !== ''
            && $this->auth !== null && $this->auth !== '';
    }

    /**
     * مقصدِ وب‌پوش بدون افشای خودِ endpoint.
     *
     * برای پیام‌های خطا و لاگ. `endpoint` یک شناسهٔ یکتاست، پس از آن می‌توان
     * فهمید کدام اشتراک خراب است، بدون اینکه آدرس افشا شود.
     */
    public function targetHint(): string
    {
        $host = parse_url((string) $this->endpoint, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return 'endpoint#'.$this->id;
        }

        return $host.'/…#'.$this->id;
    }

    /**
 * نام ارائه‌دهنده، مشتق‌شده از `endpoint` — **ستون واقعی نیست**.
 *
 * دلیل ذخیره نکردنش: فقط یک تبدیل رشته‌ای است. اگر ستون بود باید موقع
 * نوشتن هم پر می‌شد و با تغییر سرویس‌های push از هماهنگ خارج می‌افتاد.
 *
 * ⚠️ در Laravel 13 کلاس `Attribute` حذف شده، پس از accessor کلاسیک
 * `get<Field>Attribute()` استفاده می‌شود.
 */
public function getProviderAttribute(): ?string
    {
        $endpoint = (string) $this->endpoint;

        return match (true) {
            str_contains($endpoint, 'push.services.mozilla.com') => 'mozilla',
            str_contains($endpoint, 'fcm.googleapis.com') => 'fcm',
            str_contains($endpoint, 'wns2-') || str_contains($endpoint, 'notify.windows.com') => 'edge',
            default => null,
        };
    }

    /** @param  Builder<PushSubscription>  $query */
    public function scopeForUser(Builder $query, ?int $userId): Builder
    {
        return $query->when(
            $userId === null,
            fn (Builder $q) => $q->whereNull('user_id'),
            fn (Builder $q) => $q->where('user_id', $userId),
        );
    }
}