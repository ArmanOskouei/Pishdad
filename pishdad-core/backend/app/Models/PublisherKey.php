<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * کلید عمومی ناشر — trust store چندناشره.
 *
 * جایگزین مدل «یک کلید واحد پلتفرم» (`config('plugins.public_key')`). آن مدل
 * اصالت و تأیید را یکی می‌گرفت: هر چیزی که با کلید پلتفرم امضا شده بود
 * هم‌زمان «معتبر» و «تأییدشده» تلقی می‌شد. اینجا فقط اصالت ثابت می‌شود؛
 * تأیید در `plugins.review_status` و جداگانه نگهداری می‌شود.
 */
class PublisherKey extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'name', 'slug', 'public_key', 'key_fingerprint', 'status', 'verified_at',
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function plugins(): HasMany
    {
        return $this->hasMany(Plugin::class, 'publisher_key_id', 'key_fingerprint');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** base64 → کلید عمومی خام؛ null اگر قالب ورودی غلط باشد. */
    public function decodedKey(): ?string
    {
        $raw = base64_decode((string) $this->public_key, true);

        return is_string($raw) && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            ? $raw
            : null;
    }

    /** fingerprint برای جلوگیری از ثبت تکراری یک کلید با نام متفاوت. */
    public static function fingerprintOf(string $publicKeyBase64): ?string
    {
        $raw = base64_decode($publicKeyBase64, true);
        if (! is_string($raw) || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return null;
        }

        return hash('sha256', $raw);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
