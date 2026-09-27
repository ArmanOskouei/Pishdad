<?php

namespace App\Services\Plugins;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * شمارندهٔ ژستور «پنج کلیک» — **سمت سرور**.
 *
 * ## چرا سمت سرور و نه فقط کلاینت
 *
 * ژستور پنج کلیک تنها مانع باز کردن حالت توسعه‌دهنده نیست؛ خودش باید با
 * دروازهٔ `DevMode` هم‌خوان باشد. اگر شمارش فقط در مرورگر بود:
 *
 *  - یک POST ساده با یک اسکریپت، حالت توسعه‌دهنده را باز می‌کرد
 *  - هر کاربر روی همان نصب، شمارندهٔ مشترک می‌داشت و می‌شد یک نفر برای همه
 *    «پنج کلیک» را جلو برد
 *
 * پس کلید `devmode:taps:{user_id}` است، نه فقط IP. IP هم می‌آید ولی به‌عنوان
 * **بُعد دوم** نه جایگزین — چون چند کاربر پشت یک NAT هستند و چند کاربر هم
 * ممی‌توانند پشت یک IP باشند. حاصل‌ضرب هر دو لازم است تا هم از اشتراک
 * ناخواسته جلوگیری شود و هم از حملهٔ عمدی با تعویض کاربر.
 *
 * ## چرا `Cache` و نه جدول
 *
 * شمارنده وضعیت موقتی است و نباید ردپای دائمی تولید کند. جدول برای K4.5 است
 * که **رویداد** ثبت می‌کند، نه شمارش. جدا نگه‌داشتنشان مهم است: اگر شمارش در
 * جدول بود، هر کلیک یک ردیف می‌ساخت و یک انبوه کلیک بی‌معنا می‌شد.
 *
 * ## چرا reset شدن قاعده دارد و نه اختیاری است
 *
 * هر قاعده در `evaluate()` یک تهدید مشخص را می‌بندد:
 *
 *  - ۲ ثانیه بین کلیک‌ها  ⇒ کلیک‌اسپم ماشینی را کند می‌کند
 *  - ۱۰ ثانیه از اولین     ⇒ کل زنجیره را در پنجره نگه می‌دارد
 *  - غیر POST               ⇒ کلیک با کلیک یکی نیست (اسکریپت با GET دور می‌زند)
 *  - ۵ دقیقه بی‌فعالیتی      ⇒ شمارنده‌ای که رها شده از صفر شروع نمی‌شود
 */
class DevModeTaps
{
    /** فاصلهٔ حداقلی بین دو کلیک. */
    public const MIN_GAP_SECONDS = 2;

    /** کل زنجیره باید در این پنجره جمع شود. */
    public const WINDOW_SECONDS = 10;

    /** بی‌فعالیتی بیشتر از این ⇒ شمارنده دور ریخته می‌شود. */
    public const IDLE_RESET_SECONDS = 300;

    /** تعداد کلیک لازم. */
    public const REQUIRED = 5;

    private const KEY = 'devmode:taps:{user_id}';

    /**
     * یک کلیک را ثبت می‌کند و وضعیت جدید را برمی‌گرداند.
     *
     * @return array{count: int, required: int, ready: bool, reason: ?string}
     */
    public function tap(User $user, string $ip, string $method): array
    {
        $this->assertTapAllowed($user, $ip, $method);

        $key = $this->key($user->id);
        $taps = $this->read($key);

        $taps[] = now()->getTimestampMs();
        $this->write($key, $taps);

        return $this->state($user, $taps);
    }

    /**
     * وضعیت فعلی بدون ثبت کلیک — برای مودال که باید بداند چند کلیک مانده.
     *
     * @return array{count: int, required: int, ready: bool, reason: ?string}
     */
    public function state(User $user): array
    {
        $key = $this->key($user->id);
        $taps = $this->prune($this->read($key), $key);
        $count = count($taps);

        return [
            'count' => $count,
            'required' => self::REQUIRED,
            'ready' => $count >= self::REQUIRED,
            // `reason` فقط وقتی پر است که شمارنده نیاز به بازنشانی داشته باشد —
            // تا مودال بتواند به کاربر بگوید چرا شمارش صفر شد.
            'reason' => $count === 0 ? 'بدون کلیک' : null,
        ];
    }

    /** بازنشانی دستی — مثلاً بعد از خروج از حساب. */
    public function reset(User $user): void
    {
        Cache::forget($this->key($user->id));
    }

    /**
     * قاعده‌های رد. اولین قاعده‌ای که نقض شود، دلیلش برمی‌گردد.
     *
     * @throws \RuntimeException با پیام فارسی
     */
    private function assertTapAllowed(User $user, string $ip, string $method): void
    {
        if (strtoupper($method) !== 'POST') {
            throw new \RuntimeException('ثبت کلیک فقط با POST.');
        }

        $key = $this->key($user->id);
        $taps = $this->read($key);

        if ($taps === []) {
            return;
        }

        $now = now()->getTimestampMs();
        $last = (int) end($taps);

        if ($now - $last < self::MIN_GAP_SECONDS * 1000) {
            Log::info('devmode.tap_too_fast', ['user_id' => $user->id, 'ip' => $ip]);

            throw new \RuntimeException('کلیک‌ها خیلی سریع ثبت شدند.');
        }

        if ($now - (int) $taps[0] > self::WINDOW_SECONDS * 1000) {
            // پنجره از اولین کلیک گذشته؛ زنجیره باید از صفر شروع شود.
            $this->write($key, [$now]);

            throw new \RuntimeException('مهلت پنج کلیک تمام شد؛ دوباره شروع کنید.');
        }
    }

    /**
     * @return list<int> میلی‌ثانیه
     */
    private function read(string $key): array
    {
        $raw = Cache::get($key);

        return is_array($raw) ? array_values(array_map('intval', array_filter($raw, 'is_numeric'))) : [];
    }

    /** @param list<int> $taps */
    private function write(string $key, array $taps): void
    {
        // عمر کش = پنجره + حاشیه. بعد از آن بی‌فایده است و کش را بی‌دلیل پر می‌کند.
        Cache::put($key, $taps, self::WINDOW_SECONDS + self::IDLE_RESET_SECONDS);
    }

    /**
     * حذف کلیک‌های قدیمی از پنجرهٔ بی‌فعالیتی.
     *
     * اگر چیزی حذف شد، نتیجه ذخیره می‌شود — وگرنه شمارندهٔ رهاشده هر بار که
     * مودال باز می‌شود دوباره محاسبه می‌گردد ولی هرگز جمع نمی‌شود.
     *
     * @param  list<int>  $taps
     * @return list<int>
     */
    private function prune(array $taps, string $key): array
    {
        $now = now()->getTimestampMs();
        $kept = array_values(array_filter(
            $taps,
            static fn (int $t): bool => $now - $t <= self::IDLE_RESET_SECONDS * 1000
        ));

        if (count($kept) !== count($taps)) {
            $this->write($key, $kept);
        }

        return $kept;
    }

    private function key(int $userId): string
    {
        return str_replace('{user_id}', (string) $userId, self::KEY);
    }
}
