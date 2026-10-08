<?php

namespace App\Services\Market;

/**
 * K0.9 — license validity is bound to the core MAJOR version.
 * Minor/patch core upgrades stay free: only the major number is compared.
 */
class CoreMajor
{
    public static function currentVersion(): string
    {
        return (string) config('app.core_version', '1.0.0');
    }

    public static function of(string $version): int
    {
        $major = (int) explode('.', ltrim($version, 'v'))[0] ?? 0;

        return max($major, 0);
    }

    public static function current(): int
    {
        return self::of(self::currentVersion());
    }
}
