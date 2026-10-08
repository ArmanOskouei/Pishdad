<?php

namespace App\Models;

use App\Services\Plugins\ManifestRegistry;
use App\Services\Plugins\PluginRouteTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $review_status pending|approved|rejected|unverified
 * @property bool $publisher_verified آیا امضای ناشر معتبر بوده (اصالت، نه تأیید)
 * @property string|null $content_digest یکپارچگی محتوا؛ null یعنی «هرگز تأیید نشده»
 * @property string $source local|market|core
 */
class Plugin extends Model
{
    /** پلاگین آپلود دستی توسط کاربر — هرگز از سوی پلتفرم تأیید نمی‌شود. */
    public const SOURCE_LOCAL = 'local';

    /** نصب‌شده از بازار رسمی. */
    public const SOURCE_MARKET = 'market';

    /** همراه هسته (قالب مرکزی). */
    public const SOURCE_CORE = 'core';

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    /** حالت پیش‌فرض هر پلاگینی که از مسیر بازار نیامده. */
    public const REVIEW_UNVERIFIED = 'unverified';

    /**
     * K0.8 — سقف پلاگین فعال (تصمیم محصول: ۲۰).
     * فقط پلاگین‌های غیرسیستمی شمرده می‌شوند؛ مرکزی/سیستمی همیشه جا دارد.
     * دلیل عددی: بودجهٔ تجمیعی opcache (K5.10) — ۲۰ × ۵۰۰۰ فایل سقفِ امن است.
     */
    public const MAX_ACTIVE_PLUGINS = 20;

    /**
     * هر تغییر در مجموعه پلاگین‌های فعال کش مانیفست را باطل می‌کند.
     *
     * بدون این، پلاگینی که همین حالا فعال شده تا پایان TTL در
     * `permissionModules()`/`pageTypes()`/`widgetSchemas()` غایب می‌ماند و
     * پرمیشن‌ها و ویجت‌هایش هم دیده نمی‌شوند.
     */
    protected static function booted(): void
    {
        $flush = static function (): void {
            ManifestRegistry::flushCache();
            // K99: بدون این، جدول routeها تا پایان TTL سی‌ثانیه‌ای قدیمی
            // می‌ماند و «غیرفعال‌سازی فوری» که سند معماری وعده می‌دهد عملی
            // نیست: پلاگین غیرفعال‌شده تا ثانیهٔ ۳۰ هنوز route سرو می‌کند.
            PluginRouteTable::flush();
        };

        static::saved($flush);
        static::deleted($flush);
    }

    protected $fillable = [
        'user_id', 'name', 'description', 'slug', 'version', 'previous_version',
        'active', 'system',
        'source', 'publisher_key_id', 'publisher_verified',
        'content_digest', 'digest_verified_at',
        'signature_valid', 'review_status', 'review_note',
        'submitted_at', 'reviewed_at', 'checksum', 'manifest', 'path',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'system' => 'boolean',
            'publisher_verified' => 'boolean',
            'signature_valid' => 'boolean',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'digest_verified_at' => 'datetime',
            'manifest' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * پلاگین سیستمی فقط وقتی true است که slug آن در allowlist دیتابیس باشد.
     *
     * عمداً به مانیفست نگاه نمی‌کند. پیش از فاز ۰، `system` از مانیفست
     * آپلودشده خوانده می‌شد و همین یک خط، مسیر «پلاگین غیرقابل حذف» را برای
     * هر کاربری با پرمیشن plugins.edit باز می‌کرد.
     */
    public function isSystem(): bool
    {
        return SystemPlugin::allows($this->slug);
    }

    /** آیا پلتفرم هرگز کد این پلاگین را ندیده است؟ */
    public function isUnverified(): bool
    {
        return $this->review_status === self::REVIEW_UNVERIFIED
            || $this->review_status === self::REVIEW_PENDING;
    }

    /**
     * وضعیت اعتماد برای UI، به تفکیک سه مفهوم.
     *
     * عمداً سه فیلد جدا برمی‌گرداند تا UI مجبور نباشد از روی یک boolean
     * درباره امنیت حدس بزند.
     */
    public function trustSummary(): array
    {
        return [
            'publisher_verified' => (bool) $this->publisher_verified,
            'review_status' => $this->review_status,
            'review_approved' => $this->review_status === self::REVIEW_APPROVED,
            'integrity' => $this->content_digest !== null
                ? ($this->digest_verified_at !== null ? 'verified' : 'stale')
                : 'unverified',
            'source' => $this->source,
            'badge' => $this->trustBadge(),
        ];
    }

    private function trustBadge(): string
    {
        if ($this->review_status === self::REVIEW_APPROVED) {
            return 'approved';
        }
        if ($this->review_status === self::REVIEW_REJECTED) {
            return 'rejected';
        }
        if ($this->review_status === self::REVIEW_PENDING) {
            return 'pending';
        }

        return 'unverified';
    }
}
