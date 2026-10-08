<?php

namespace App\Services\Settings;

use App\Models\Setting;
use App\Support\IpAllowlist;
use Throwable;

/**
 * WF-M7 — تنظیمات امنیتی نصب: فهرست IPهای مجاز پنل + سقف تلاش ورود به‌ازای هر IP.
 *
 * فهرستِ خالی یعنی «همهٔ نشانی‌ها مجازند» — پیش‌فرضِ امن برای نصبِ تازه که با
 * یک تنظیمِ ناقص نباید خودش را قفل کند. سقفِ تلاش هم قابل تنظیم است ولی داخل
 * بازهٔ مجاز نگه داشته می‌شود تا مقدارِ صفر/منفی کل ورود را نبندد.
 */
final class SecuritySettings
{
    public const GROUP = 'security';

    public const KEY = 'global';

    public const DEFAULT_LOGIN_ATTEMPT_CAP = 20;

    public const MIN_LOGIN_ATTEMPT_CAP = 3;

    public const MAX_LOGIN_ATTEMPT_CAP = 100;

    public const MAX_ALLOWED_IPS = 100;

    public const DEFAULTS = [
        'allowed_admin_ips' => [],
        'login_attempt_cap' => self::DEFAULT_LOGIN_ATTEMPT_CAP,
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

    /** @return list<string> */
    public static function allowedAdminIps(): array
    {
        $ips = self::get()['allowed_admin_ips'] ?? [];

        return is_array($ips) ? array_values(array_filter($ips, 'is_string')) : [];
    }

    public static function loginAttemptCap(): int
    {
        $cap = (int) (self::get()['login_attempt_cap'] ?? self::DEFAULT_LOGIN_ATTEMPT_CAP);

        return self::clampCap($cap);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function save(array $data): array
    {
        $ips = IpAllowlist::normalize(
            is_array($data['allowed_admin_ips'] ?? null) ? $data['allowed_admin_ips'] : []
        );

        $next = [
            'allowed_admin_ips' => $ips,
            'login_attempt_cap' => self::clampCap((int) ($data['login_attempt_cap'] ?? self::DEFAULT_LOGIN_ATTEMPT_CAP)),
        ];

        Setting::set(self::GROUP, self::KEY, $next);
        CachedSettings::forget(self::GROUP, self::KEY);

        return $next;
    }

    private static function clampCap(int $cap): int
    {
        return max(self::MIN_LOGIN_ATTEMPT_CAP, min(self::MAX_LOGIN_ATTEMPT_CAP, $cap));
    }
}
