<?php

namespace App\Services\Plugins;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * وضعیت حالت توسعه‌دهنده — **سمت سرور**.
 *
 * تا پیش از این کلاس اصلاً چیزی به نام `dev_mode` وجود نداشت، یعنی
 * `upload()` و `upgrade()` هیچ راهی برای پذیرش بستهٔ بدون امضا نداشتند و
 * در نتیجه «حالت توسعه‌دهنده» فقط یک ایده در سند بود. این کلاس آن دروازه
 * را واقعی می‌کند.
 *
 * سه قاعده که اینجا عمداً سخت‌گیرانه‌اند:
 *
 * 1. **پیش‌فرض بسته است.** نبودِ تنظیم یعنی `dev_mode` خاموش است، پس رفتار
 *    پیش‌فرض همان رفتار سخت‌گیرانهٔ قبلی است. هیچ مسیری به‌طور تصادفی باز
 *    نمی‌شود.
 * 2. **انقضا غیرقابل مذاکره است.** حتی اگر `expires_at` در گذشته باشد، حالت
 *    توسعه‌دهنده خاموش است. کاربر باید دوباره بازش کند.
 * 3. **توکن اجباری است.** ژستور کلاینت (K4.3) در برابر CSRF ضعیف است، پس
 *    باز کردن حالت توسعه‌دهنده با رمز عبور و یک توکن یکبارمصرف انجام می‌شود
 *    و همین توکن باید هنگام آپلود هم ارائه شود. حالت توسعه‌دهنده «یک پرچم
 *    در مرورگر» نیست.
 *
 * کش عمداً کوتاه‌عمر است (نه `forever`): اگر حالت توسعه‌دهنده منقضی شود باید
 * همان لحظه اثر کند، وگرنه یک flush یا کش طولانی، دری باز نگه می‌دارد.
 */
class DevMode
{
    public const GROUP = 'system';

    public const KEY = 'dev_mode';

    /** طول عمر باز بودن حالت توسعه‌دهنده (K4.4 — غیرقابل مذاکره). */
    public const TTL_HOURS = 8;

    private const CACHE_KEY = 'devmode:state';

    private const CACHE_SECONDS = 30;

    /**
     * @return array{enabled: bool, expires_at: ?string, unlock_token: ?string}
     */
    public function state(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && array_key_exists('enabled', $cached)) {
            return $cached + ['expires_at' => null, 'unlock_token' => null];
        }

        $raw = Setting::get(self::GROUP, self::KEY);
        $raw = is_array($raw) ? $raw : [];

        $state = [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'expires_at' => isset($raw['expires_at']) && is_string($raw['expires_at'])
                ? $raw['expires_at']
                : null,
            'unlock_token' => isset($raw['unlock_token']) && is_string($raw['unlock_token'])
                ? $raw['unlock_token']
                : null,
        ];

        Cache::put(self::CACHE_KEY, $state, self::CACHE_SECONDS);

        return $state;
    }

    /**
     * آیا حالت توسعه‌دهنده در همین لحظه باز است؟
     *
     * «در همین لحظه» عمداً است: تنظیمِ `enabled` با انقضای گذشته باز نیست.
     */
    public function isActive(): bool
    {
        $state = $this->state();
        if ($state['enabled'] !== true) {
            return false;
        }

        if ($state['expires_at'] === null) {
            // `enabled` بدون انقضا یعنی دادهٔ ناقصِ دستکاری‌شده. حالت امن
            // این است که باز حساب نشود.
            return false;
        }

        try {
            return now()->lt(Carbon::parse($state['expires_at']));
        } catch (\Throwable $e) {
            Log::warning('devmode.expiry_unparseable', ['value' => $state['expires_at']]);

            return false;
        }
    }

    /**
     * آیا این درخواست اجازهٔ عبور از دروازهٔ توسعه‌دهنده را دارد؟
     *
     * توکن با `hash_equals` مقایسه می‌شود تا مقایسهٔ زمان‌ثابت باشد و timing
     * از حدس توکن لو ندهد.
     */
    public function allows(?string $presentedToken): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        $state = $this->state();
        $expected = $state['unlock_token'];
        if (! is_string($expected) || $expected === '' || ! is_string($presentedToken) || $presentedToken === '') {
            return false;
        }

        return hash_equals($expected, $presentedToken);
    }

    /**
     * باز کردن حالت توسعه‌دهنده. توکن تازه صادر می‌شود و انقضا از همین
     * لحظه حساب می‌شود.
     */
    public function enable(string $token): Carbon
    {
        $expires = now()->addHours(self::TTL_HOURS);

        Setting::set(self::GROUP, self::KEY, [
            'enabled' => true,
            'expires_at' => $expires->toIso8601String(),
            'unlock_token' => $token,
        ]);

        $this->forget();

        return $expires;
    }

    /** بستن حالت توسعه‌دهنده. توکن هم دور ریخته می‌شود. */
    public function disable(): void
    {
        Setting::set(self::GROUP, self::KEY, [
            'enabled' => false,
            'expires_at' => null,
            'unlock_token' => null,
        ]);

        $this->forget();
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
