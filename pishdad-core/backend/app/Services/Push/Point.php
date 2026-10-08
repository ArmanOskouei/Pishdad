<?php

namespace App\Services\Push;

/**
 * نقطه‌های روی منحنی P-256 (a = -3)، در مختصاتAffine.
 *
 * ## چرا Affine
 *
 * پیاده‌سازی کاملاً در مختصات «مثبت/نرمال» ساده‌تر و کم‌خطاتر است. هزینه‌اش
 * این است که هر ضربِ اسکالر، یک تقسیم پیمانه‌ای لازم دارد — که با
 * `bcmod` انجام می‌شود و برای حجم push کاملاً کافی است.
 */
final class Point
{
    public function __construct(
        public readonly BigNum $x,
        public readonly BigNum $y,
        /** آیا این «نقطهٔ بی‌نهایت» است؟ */
        public readonly bool $infinity = false,
    ) {
    }

    public static function infinity(): self
    {
        return new self(BigNum::zero(), BigNum::zero(), true);
    }

    public function isInfinity(): bool
    {
        return $this->infinity;
    }

    public function equals(self $other): bool
    {
        if ($this->infinity || $other->infinity) {
            return $this->infinity === $other->infinity;
        }

        return $this->x->equals($other->x) && $this->y->equals($other->y);
    }
}