<?php

namespace App\Support;

use App\Validation\IpOrCidr;

/**
 * WF-M7 — تطبیق یک IP با فهرست «IP یا CIDR».
 *
 * پیاده‌سازی مستقل از کتابخانهٔ بیرونی است تا در نبود شبکه/بسته هم کار کند.
 * تطبیق بر پایهٔ `inet_pton` (باینری) است، نه مقایسهٔ رشته‌ای، تا CIDR درست
 * و بدون افتِ کارایی محاسبه شود.
 */
final class IpAllowlist
{
    /**
     * آیا `$ip` در فهرستِ `$entries` (IP خام یا CIDR) هست؟
     *
     * @param  array<int, mixed>  $entries
     */
    public static function matches(string $ip, array $entries): bool
    {
        foreach ($entries as $entry) {
            if (is_string($entry) && self::matchesOne($ip, trim($entry))) {
                return true;
            }
        }

        return false;
    }

    /**
     * پاک‌سازی فهرست: trim، حذف خالی/نامعتبر، و حذف تکراری‌ها (با حفظ ترتیب).
     *
     * @param  array<int, mixed>  $entries
     * @return list<string>
     */
    public static function normalize(array $entries): array
    {
        $out = [];

        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $entry = trim($entry);

            if ($entry === '' || ! IpOrCidr::passes($entry)) {
                continue;
            }

            if (! in_array($entry, $out, true)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    private static function matchesOne(string $ip, string $entry): bool
    {
        if ($entry === '') {
            return false;
        }

        if (! str_contains($entry, '/')) {
            return $ip === $entry;
        }

        [$subnet, $bits] = explode('/', $entry, 2);

        if (! ctype_digit($bits)) {
            return false;
        }

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $maxBits = strlen($ipBin) * 8;

        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        $rem = $bits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        if ($rem > 0) {
            $mask = (0xFF << (8 - $rem)) & 0xFF;

            if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($subnetBin[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
