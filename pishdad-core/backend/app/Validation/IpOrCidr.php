<?php

namespace App\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * WF-M7 — اعتبارسنجی «IP یا CIDR» برای فهرست IPهای مجاز پنل.
 *
 * عمداً هم IPv4 و هم IPv6 را می‌پذیرد و محدودهٔ پیشوند را هم درست می‌سنجد
 * (`/32` برای IPv4 و `/128` برای IPv6 سقف دارند). قاعده در `passes` جداست تا
 * هم در لایهٔ validation و هم در نرمال‌سازیِ سرویس قابل استفاده باشد — بدون
 * اینکه دو روایتِ جدا از هم پیدا کند.
 */
final class IpOrCidr implements ValidationRule
{
    public static function passes(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (! str_contains($value, '/')) {
            return false;
        }

        [$ip, $prefix] = explode('/', $value, 2);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false || ! ctype_digit($prefix)) {
            return false;
        }

        $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;
        $prefix = (int) $prefix;

        return $prefix >= 0 && $prefix <= $max;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::passes($value)) {
            $fail('نشانی IP یا محدودهٔ CIDR معتبر نیست (مثل 203.0.113.4 یا 203.0.113.0/24).');
        }
    }
}
