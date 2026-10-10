<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * سقفِ صفحه‌بندی — یک منبع برای همهٔ لیست‌های پنل.
 *
 * P-01 — پیش از این، کنترلرها مستقیم `(int) $request->query('per_page', 15)`
 * را به `paginate()` می‌دادند. `?per_page=1000000` یعنی «کل جدول را در یک
 * آرایهٔ PHP بریز» — با ستون‌های سنگین مثل `blocks`/`revisions` و
 * `memory_limit=512M` یک درخواست از هر کاربرِ احراز‌هویت‌شده worker را
 * می‌کشت. الگوی درست از قبل در `NotificationInboxController` بود؛ این کلاس
 * آن را عمومی می‌کند.
 */
final class Pagination
{
    /** سقفِ سراسری: هر لیستِ پنل حداکثر ۱۰۰ ردیف. */
    public const MAX = 100;

    public static function perPage(Request $request, int $default = 20, ?int $max = null): int
    {
        $requested = (int) $request->query('per_page', $default);
        $max = $max ?? self::MAX;

        return max(1, min($max, $requested));
    }
}
