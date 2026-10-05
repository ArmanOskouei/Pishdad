<?php

namespace App\Services\Chabokan;

use App\Services\External\MissingCredentialException;

/**
 * انتخابِ درایورِ چابکان از روی `CHABOKAN_DRIVER` — تنها جایی که این نام خوانده
 * می‌شود (E4).
 *
 * ⭐ پیش‌فرض `stub` است، نه `api`. یعنی تا وقتی کسی عمداً `CHABOKAN_DRIVER=api`
 * نگذارد، این کلاس **هیچ‌وقت** به شبکه دست نمی‌زند. این با `SMS_DRIVER` فرق دارد
 * که پیش‌فرضش `null` است — دلیلش ساده است: نداشتنِ پیامک چیزی را خراب نمی‌کند،
 * ولی نداشتنِ توکنِ چابکان یعنی هر تلاش برای دیدنِ موجودی، یک request به بیرون.
 */
final class ChabokanDriverFactory
{
    public const STUB = 'stub';

    public const API = 'api';

    /**
     * @throws MissingCredentialException اگر `api` انتخاب شده و کلیدها ناقص‌اند.
     */
    public static function make(?string $driver = null): ChabokanClientInterface
    {
        $driver = strtolower(trim($driver ?? (string) config('chabokan.driver', self::STUB)));

        return match ($driver) {
            self::API => new ApiChabokanDriver, // سازنده‌اش fail-closed است
            // ناشناخته ⇒ `stub`. درایورِ بی‌خطر، ولی `doctor` نامِ ناشناخته را
            // گزارش می‌کند تا تایپِ اشتباه پنهان نماند.
            default => new StubChabokanDriver,
        };
    }

    /**
     * @return list<string>
     */
    public static function missingKeys(?string $driver = null): array
    {
        $driver = strtolower(trim($driver ?? (string) config('chabokan.driver', self::STUB)));

        return $driver === self::API ? ApiChabokanDriver::missingKeys() : [];
    }

    public static function isReal(?string $driver = null): bool
    {
        return strtolower(trim($driver ?? (string) config('chabokan.driver', self::STUB))) === self::API;
    }

    /**
     * تلاش برای ساخت، به‌جای exception.
     *
     * @return array{0: ?ChabokanClientInterface, 1: ?MissingCredentialException}
     */
    public static function tryMake(?string $driver = null): array
    {
        try {
            return [self::make($driver), null];
        } catch (MissingCredentialException $e) {
            return [null, $e];
        }
    }
}