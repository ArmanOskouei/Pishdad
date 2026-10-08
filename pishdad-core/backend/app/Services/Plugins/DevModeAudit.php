<?php

namespace App\Services\Plugins;

use App\Models\User;
use App\Services\Audit\AuditTrail;

/**
 * ثبتِ رویدادهای حالتِ توسعه — **نازک‌ترین ممکن**.
 *
 * ## J5 — این کلاس دیگر هیچ چیز از افزونه نمی‌داند
 *
 * نسخهٔ قبل مستقیم `OperatorActivity::record()` مدلِ افزونه را صدا می‌زد. یعنی:
 *
 *  - هسته برای نوشتنِ یک لاگِ امنیتی به افزونهٔ مرکزی وابسته بود، و
 *  - روی نصبِ بدون آن افزونه، لاگِ بازکردنِ قفلِ توسعه **بی‌صدا از بین می‌رفت**
 *    (داخلِ `try` می‌افتاد و فقط یک لاگِ خطا می‌نوشت).
 *
 * دومی بدترین حالت بود: یک رویدادِ امنیتی که ثبت نمی‌شود و کسی هم نمی‌فهمد.
 *
 * حالا فقط `AuditTrail` را صدا می‌زند. اگر افزونهٔ مرکزی نصب باشد، خودش یک
 * `AuditSink` ثبت کرده و رویداد را در `operator_activities` هم می‌نویسد؛ اگر
 * نباشد، لاگِ لاراول باقی است. **هیچ‌کدام هرگز حذف نمی‌شود.**
 *
 * @see \App\Services\Audit\AuditTrail
 */
class DevModeAudit
{
    public function record(?User $user, string $ip, string $action, array $meta = []): void
    {
        // کاربرِ `null` را **حذف نمی‌کنیم.** تلاش برای بازکردنِ قفلِ توسعه پیش
        // از ورود کاربر ندارد، و دقیقاً همان رویدادی است که باید ثبت شود.
        AuditTrail::record($user, $action, $meta, $ip);
    }
}