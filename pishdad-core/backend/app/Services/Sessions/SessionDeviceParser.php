<?php

namespace App\Services\Sessions;

use Jenssegers\Agent\Agent;

final class SessionDeviceParser
{
    public static function parse(?string $userAgent): array
    {
        $ua = trim((string) $userAgent);
        if ($ua === '') {
            return self::unknown();
        }

        $agent = new Agent;
        $agent->setUserAgent($ua);

        $rawOs = (string) $agent->platform();
        $rawBrowser = (string) $agent->browser();
        $os = self::osName($rawOs);
        $browser = self::browserName($rawBrowser);
        $osVersion = self::version($agent->version($rawOs));
        $browserVersion = self::version($agent->version($rawBrowser));
        $deviceType = self::deviceType($agent);

        return [
            'os' => $os,
            'os_version' => $osVersion,
            'browser' => $browser,
            'browser_version' => $browserVersion,
            'device' => self::deviceName($browser, $os),
            'device_type' => $deviceType,
        ];
    }

    private static function osName(string $platform): ?string
    {
        $value = strtolower(trim($platform));
        if ($value === '') {
            return null;
        }

        return match (true) {
            str_contains($value, 'windows') => 'Windows',
            str_contains($value, 'android') => 'Android',
            str_contains($value, 'ios') || str_contains($value, 'iphone') || str_contains($value, 'ipad') => 'iOS',
            str_contains($value, 'mac') || str_contains($value, 'os x') => 'macOS',
            str_contains($value, 'chrome os') => 'Chrome OS',
            str_contains($value, 'linux') || str_contains($value, 'ubuntu') => 'Linux',
            default => ucfirst($value),
        };
    }

    private static function browserName(string $browser): ?string
    {
        $value = strtolower(trim($browser));
        if ($value === '') {
            return null;
        }

        return match (true) {
            str_contains($value, 'edge') || str_contains($value, 'edg') => 'Edge',
            str_contains($value, 'firefox') || str_contains($value, 'fxios') => 'Firefox',
            str_contains($value, 'samsung') => 'Samsung Internet',
            str_contains($value, 'opera') || str_contains($value, 'opr') => 'Opera',
            str_contains($value, 'chrome') || str_contains($value, 'chromium') => 'Chrome',
            str_contains($value, 'safari') => 'Safari',
            str_contains($value, 'vivaldi') => 'Vivaldi',
            str_contains($value, 'brave') => 'Brave',
            str_contains($value, 'msie') || str_contains($value, 'trident') => 'Internet Explorer',
            default => ucfirst($value),
        };
    }

    private static function version(mixed $version): ?string
    {
        if (! is_scalar($version)) {
            return null;
        }

        $value = trim((string) $version);
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/[^0-9A-Za-z._+-]/', '', $value) ?: null;
        $value = $value !== null ? str_replace('_', '.', $value) : null;

        return $value !== null && $value !== '' ? $value : null;
    }

    private static function deviceType(Agent $agent): string
    {
        if ($agent->isTablet()) {
            return 'تبلت';
        }
        if ($agent->isPhone()) {
            return 'موبایل';
        }
        if ($agent->isDesktop()) {
            return 'دسکتاپ';
        }

        return 'نامشخص';
    }

    private static function deviceName(?string $browser, ?string $os): string
    {
        if ($browser !== null && $os !== null) {
            return "{$browser} روی {$os}";
        }
        if ($os !== null) {
            return $os;
        }
        if ($browser !== null) {
            return $browser;
        }

        return 'نامشخص';
    }

    private static function unknown(): array
    {
        return [
            'os' => null,
            'os_version' => null,
            'browser' => null,
            'browser_version' => null,
            'device' => 'نامشخص',
            'device_type' => 'نامشخص',
        ];
    }
}
