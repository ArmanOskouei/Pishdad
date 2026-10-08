<?php

namespace App\Mail;

use App\Models\Setting;
use App\Services\Settings\CachedSettings;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * WF-H11 — تنظیمات SMTP هر نصب، به‌همراه اعمال آن روی mailer در زمان اجرا.
 *
 * رمز SMTP **هرگز** به‌صورت متنِ خام ذخیره نمی‌شود: با `Crypt` رمزنگاری و در
 * همان ردیفِ `settings` نگه داشته می‌شود. خروجیِ API هم هرگز رمز را برنمی‌گرداند؛
 * فقط بولینِ `password_set`.
 */
final class MailSettings
{
    public const GROUP = 'mail';

    public const KEY = 'global';

    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    public const DEFAULTS = [
        'host' => '',
        'port' => 587,
        'username' => '',
        'from_address' => '',
        'from_name' => '',
        'encryption' => 'tls',
        'password_encrypted' => null,
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

    /** رمزِ رمزگشایی‌شده، یا `null` اگر ذخیره نشده/رمزگشایی نشد. */
    public static function password(): ?string
    {
        $encrypted = self::get()['password_encrypted'] ?? null;

        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (Throwable) {
            // کلیدِ دوره‌شده یعنی رمز قابل استفاده نیست؛ بهتر است مثلِ «تنظیم‌نشده»
            // رفتار شود تا شکست در لحظهٔ ارسال با پیامِ نامفهوم.
            return null;
        }
    }

    public static function hasPassword(): bool
    {
        return self::password() !== null;
    }

    /**
     * ذخیرهٔ تنظیمات. `password` ناموجود/خالی = «بدون تغییر» تا فرم نتواند
     * ناخواسته رمزِ ذخیره‌شده را پاک کند. `clear_password` پاک‌کردنِ صریح است.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function save(array $data): array
    {
        $current = self::get();

        $pick = fn (string $key, mixed $fallback): mixed => array_key_exists($key, $data) ? $data[$key] : $fallback;

        $encryption = in_array($pick('encryption', $current['encryption']), self::ENCRYPTIONS, true)
            ? $pick('encryption', $current['encryption'])
            : 'tls';

        $passwordEncrypted = $current['password_encrypted'] ?? null;

        if (! empty($data['clear_password'])) {
            $passwordEncrypted = null;
        } elseif (isset($data['password']) && is_string($data['password']) && $data['password'] !== '') {
            $passwordEncrypted = Crypt::encryptString($data['password']);
        }

        $next = [
            'host' => trim((string) $pick('host', $current['host'] ?? '')),
            'port' => (int) $pick('port', $current['port'] ?? 587),
            'username' => trim((string) $pick('username', $current['username'] ?? '')),
            'from_address' => trim((string) $pick('from_address', $current['from_address'] ?? '')),
            'from_name' => trim((string) $pick('from_name', $current['from_name'] ?? '')),
            'encryption' => $encryption,
            'password_encrypted' => $passwordEncrypted,
        ];

        Setting::set(self::GROUP, self::KEY, $next);
        CachedSettings::forget(self::GROUP, self::KEY);

        return $next;
    }

    /**
     * نگاشتِ خالصِ تنظیمات به کلیدهای کانفیگِ لاراول.
     *
     * `ssl` ⇒ `smtps` (TLS ضمنی/پورت ۴۶۵)، `tls`/`none` ⇒ `smtp` (شروع با
     * STARTTLS). عمداً از خروجیِ این متد در تست‌ها هم استفاده می‌شود چون
     * `app()->runningUnitTests()` جلوی اعمالِ واقعی را می‌گیرد.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function smtpConfig(array $settings): array
    {
        $scheme = ($settings['encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp';
        $username = (string) ($settings['username'] ?? '');

        return [
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => (string) ($settings['host'] ?? ''),
            'mail.mailers.smtp.port' => (int) ($settings['port'] ?? 587),
            'mail.mailers.smtp.username' => $username !== '' ? $username : null,
            'mail.mailers.smtp.password' => $settings['password'] ?? null,
            'mail.mailers.smtp.scheme' => $scheme,
        ];
    }

    /**
     * اعمالِ تنظیمات ذخیره‌شده روی mailer.
     *
     * دو گاردِ عمدی:
     *  - در تست، `phpunit.xml` مقدارِ `MAIL_MAILER=array` را force می‌کند و این
     *    نباید با یک SMTP واقعی (و شبکهٔ ناموجود) خراب شود.
     *  - اگر کانفیگ از قبل `array` است، دست نمی‌زنیم.
     */
    public static function applyToConfig(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        if (config('mail.default') === 'array') {
            return;
        }

        $settings = self::get();

        if (trim((string) ($settings['host'] ?? '')) === '') {
            return;
        }

        $settings['password'] = self::password();

        config(self::smtpConfig($settings));

        if (trim((string) ($settings['from_address'] ?? '')) !== '') {
            config(['mail.from.address' => $settings['from_address']]);
        }

        if (trim((string) ($settings['from_name'] ?? '')) !== '') {
            config(['mail.from.name' => $settings['from_name']]);
        }
    }
}
