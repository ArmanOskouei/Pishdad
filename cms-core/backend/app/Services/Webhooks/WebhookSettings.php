<?php

namespace App\Services\Webhooks;

use App\Models\Setting;
use App\Services\Settings\CachedSettings;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * WF-L2 — پیکربندیِ وب‌هوک خروجیِ نصب: نشانی مقصد + راز امضا + کلید فعال/خاموش.
 *
 * ## ⭐ چرا راز رمزنگاری می‌شود و هرگز برنمی‌گردد
 *
 * رازِ وب‌هوک **همان نقشی** را دارد که توکنِ پاک‌سازی CDN و رمزِ SMTP دارد: هر
 * کسی آن را داشته باشد می‌تواند به سیستمِ بیرونی جایی که نصب به آن اعتماد
 * دارد درخواستِ جعلی بزند. پس دقیقاً همان قراردادِ `PerformanceSettings` را
 * دنبال می‌کنیم: `Crypt::encryptString` در `settings`، و خروجیِ API فقط
 * بولینِ `secret_set`.
 *
 * یک تفاوتِ عمدی با CDN: آنجا «توکنِ خالی = بدون تغییر» بود چون فرم مقدار را
 * نمی‌توانست بخواند. اینجا فرم **اصلاً** مقدار را نمی‌بیند، پس «ساخت رازِ تازه»
 * یک عملیاتِ مستقل است (`rotateSecret`) که متنِ راز را **فقط یک بار** برمی‌گرداند.
 * غیر از آن، تنها راهِ تغییر هم همان عملیات است — تا هیچ مسیری راز را لو ندهد.
 *
 * ## ⭐ چرا `configured()` سه شرط دارد
 *
 * «فعال» به‌تنهایی کافی نیست: نصبی می‌تواند کلید را روشن کند ولی هنوز نشانی
 * یا راز نداشته باشد (نصبِ تازه، یا رازِ پاک‌شده). در آن حالت صف کردنِ سطرِ
 * تحویل یعنی ساختنِ کاری که هرگز انجام نمی‌شود ولی در جدولِ `sent` می‌نشیند —
 * همان دروغی که `TelegramSender`/`NotificationDispatcher` برای جلوگیری از آن
 * گیتِ «مقصد هست؟» دارند. پس `configured()` **تنها** گیتِ enqueue است.
 */
final class WebhookSettings
{
    public const GROUP = 'webhook';

    public const KEY = 'global';

    /**
     * سقفِ طول نشانی. دقیقاً برابرِ ستونِ `recipient` در
     * `notification_deliveries` — بلندتر از این در enqueue استثنا می‌داد و
     * آن استثنا در مسیرِ «انتشار صفحه» می‌افتاد (fail-soft آن را می‌بلعید و
     * رویداد بی‌صدا گم می‌شد). پس بهتر است از API رد شود تا از ابتدا.
     */
    public const URL_MAX = 200;

    /** سقفِ طولِ رازِ دستی (۶۴ نویسهٔ hex برای رازِ تولیدشده). */
    public const SECRET_MAX = 1024;

    public const DEFAULTS = [
        'url' => null,
        'secret_encrypted' => null,
        'active' => false,
    ];

    /** @return array<string, mixed> */
    public static function get(): array
    {
        try {
            $stored = CachedSettings::remember(
                self::GROUP,
                self::KEY,
                300,
                fn () => Setting::get(self::GROUP, self::KEY, []),
            );
        } catch (Throwable) {
            return self::DEFAULTS;
        }

        return array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
    }

    /** نشانیِ نرمال‌شده (بدون فاصله‌های کناری) یا null. */
    public static function url(): ?string
    {
        $raw = self::get()['url'] ?? null;

        if (! is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);

        return $trimmed === '' ? null : $trimmed;
    }

    public static function active(): bool
    {
        return (bool) (self::get()['active'] ?? false);
    }

    /**
     * رازِ رمزگشایی‌شده، یا null اگر ذخیره نشده/رمزگشایی نشد.
     *
     * `Throwable` به null: کلیدِ `APP_KEY` عوض شده یا سطر از جایی آمده که رمزِ
     * فعلی آن را باز نمی‌کند. آن حالت «رازِ نداریم» است، نه خطای کارِ درخواست.
     */
    public static function secret(): ?string
    {
        $encrypted = self::get()['secret_encrypted'] ?? null;

        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $plain = Crypt::decryptString($encrypted);
        } catch (Throwable) {
            return null;
        }

        $plain = trim($plain);

        return $plain === '' ? null : $plain;
    }

    public static function hasSecret(): bool
    {
        return self::secret() !== null;
    }

    /**
     * ⭐ تنها گیتِ enqueue. هر سه باید همزمان درست باشند.
     */
    public static function configured(): bool
    {
        return self::active() && self::url() !== null && self::hasSecret();
    }

    /**
     * ذخیرهٔ تنظیمات. `secret` ناموجود/خالی = «بدون تغییر» (مثل `Performance`)
     * و `clear_secret` پاک‌کردنِ صریح است.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function save(array $data): array
    {
        $current = self::get();

        $pick = fn (string $key, mixed $fallback): mixed => array_key_exists($key, $data) ? $data[$key] : $fallback;

        $secretEncrypted = $current['secret_encrypted'] ?? null;

        if (! empty($data['clear_secret'])) {
            $secretEncrypted = null;
        } elseif (isset($data['secret']) && is_string($data['secret']) && trim($data['secret']) !== '') {
            $secretEncrypted = Crypt::encryptString(trim($data['secret']));
        }

        $next = [
            'url' => self::normalizeUrl($pick('url', $current['url'] ?? null)),
            'secret_encrypted' => $secretEncrypted,
            'active' => (bool) $pick('active', $current['active'] ?? false),
        ];

        Setting::set(self::GROUP, self::KEY, $next);
        CachedSettings::forget(self::GROUP, self::KEY);

        return $next;
    }

    /**
     * ⭐ ساختِ رازِ تازه و برگرداندنِ آن **فقط همین یک بار**.
     *
     * چون راز رمزنگاری‌شده ذخیره می‌شود، هیچ راهِ دومی برای دیدنش نیست؛ اگر
     * مدیر آن را گم کند باید همین دکمه را بزند. (همین «یک‌بار» بودن، دلیلِ
     * ساختنِ راز در سرور است نه در مرورگر: رازی که مرورگر بسازد، از DOM هم
     * قابلِ استخراج است.)
     */
    public static function rotateSecret(): string
    {
        $bytes = max(16, (int) config('webhook.secret_bytes', 32));
        $secret = bin2hex(random_bytes($bytes));

        $current = self::get();

        Setting::set(self::GROUP, self::KEY, [
            'url' => self::normalizeUrl($current['url'] ?? null),
            'secret_encrypted' => Crypt::encryptString($secret),
            'active' => (bool) ($current['active'] ?? false),
        ]);
        CachedSettings::forget(self::GROUP, self::KEY);

        return $secret;
    }

    /**
     * @return array{url: string|null, secret_set: bool, active: bool, configured: bool}
     */
    public static function toArray(): array
    {
        return [
            'url' => self::url(),
            'secret_set' => self::hasSecret(),
            'active' => self::active(),
            'configured' => self::configured(),
        ];
    }

    private static function normalizeUrl(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);

        return $trimmed === '' ? null : $trimmed;
    }
}
