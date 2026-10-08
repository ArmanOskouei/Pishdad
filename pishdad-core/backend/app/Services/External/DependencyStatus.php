<?php

namespace App\Services\External;

/**
 * وضعیتِ یک وابستگیِ خارجی — ورودیِ مشترکِ `pishdad:doctor` و
 * `DependencyChecker::assert()`.
 *
 * سه حالت، عمداً بیشتر نه کمتر:
 *
 *  - `ok`      — همه‌چیز هست و کار می‌کند.
 *  - `warn`    — **عمدی** ناقص است: درایور بی‌خطر انتخاب شده (`null`/`log`) یا
 *               چیزی با fallback پوشش داده شده. سایت سالم است، فقط یک قابلیت
 *               خاموش است.
 *  - `missing` — **ناخواسته** ناقص است: درایورِ واقعی انتخاب شده ولی کلیدش نیست.
 *               این تنها حالتی است که `doctor` با آن exit code 1 می‌دهد.
 *
 * تفکیک `warn` از `missing` مهم است: اگر هر دو یکی بودند، اپراتور برای
 * «پیامک نداریم و می‌دانیم» هم باید کاری می‌کرد، و بعد از دو هفته هشدار را
 * نادیده می‌گرفت.
 */
final class DependencyStatus
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const MISSING = 'missing';

    /**
     * @param  list<string>  $missingKeys  کلید(های) محیطیِ جاافتاده.
     * @param  string  $detail  یک خطِ قابلِ خواندن برای اپراتور.
     * @param  string  $remedy  اگر `missing` باشد: دقیقاً چه‌کار کند.
     */
    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $state,
        public readonly string $detail,
        public readonly array $missingKeys = [],
        public readonly string $remedy = '',
    ) {}

    public static function ok(string $key, string $label, string $detail): self
    {
        return new self($key, $label, self::OK, $detail);
    }

    public static function warn(string $key, string $label, string $detail, string $remedy = ''): self
    {
        return new self($key, $label, self::WARN, $detail, [], $remedy);
    }

    /**
     * @param  list<string>  $missingKeys
     */
    public static function missing(string $key, string $label, string $detail, array $missingKeys, string $remedy): self
    {
        return new self($key, $label, self::MISSING, $detail, $missingKeys, $remedy);
    }

    public function isOk(): bool
    {
        return $this->state === self::OK;
    }

    /** تنها حالتی که `doctor` را nonzero می‌کند. */
    public function isBlocking(): bool
    {
        return $this->state === self::MISSING;
    }

    /** برچسبِ رنگی برای خروجیِ ترمینال. */
    public function stateLabel(): string
    {
        return match ($this->state) {
            self::OK => 'ok',
            self::WARN => 'warn',
            default => 'MISSING',
        };
    }
}