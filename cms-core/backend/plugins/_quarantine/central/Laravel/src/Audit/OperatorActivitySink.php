<?php

declare(strict_types=1);

namespace Pishdad\Plugins\Central\Audit;

use App\Models\User;
use App\Services\Audit\AuditSink;
use Illuminate\Support\Facades\Schema;
use Pishdad\Plugins\Central\Models\OperatorActivity;

/**
 * می‌نشاندنِ رویدادهای ممیزیِ هسته در جدولِ `operator_activities` افزونهٔ مرکزی.
 *
 * ## داستانِ این کلاس
 *
 * `DevModeAudit` (هسته) قبلاً مستقیم `OperatorActivity::record()` را صدا می‌زد.
 * با حذفِ آن import (J5) لاگِ امنیتی در نصبِ بدون افزونه **بی‌صدا از بین
 * می‌رفت** — و اگر افزونه نصب بود، هسته هنوز به کلاسِ افزونه وابسته می‌ماند.
 *
 * این کلاس همان کار را از سمتِ افزونه انجام می‌دهد: هسته رویداد را از راه
 * `AuditTrail` می‌نویسد، این sink آن را به جدولِ خودمان می‌برد. هیچ‌کدام به
 * دیگری اشاره ندارد — فقط یک رابط.
 *
 * ## ثبت
 *
 * خودکار: `AppServiceProvider::registerBundledAuditSink()` هر بستهٔ داخلی را برای
 * `Audit\OperatorActivitySink` نگاه می‌کند. پس نیازی به ثبتِ دستی نیست.
 *
 * ⚠️ `AuditTrail` عمداً در `PLUGIN_ALLOWED_CORE` نیست: این کلاس آن را صدا
 * نمی‌زند، فقط در مستندات اسمش آمده. تنها وابستگیِ واقعی `AuditSink` است —
 * یک رابط، دقیقاً مثل `ChabokanClientInterface`.
 */
final class OperatorActivitySink implements AuditSink
{
    public function record(array $payload): void
    {
        // نامِ جدول را از خودِ مدل می‌گیریم، نه اینکه بنویسیم.
        //
        // ⚠️ این ظرافت لازم است: مهاجرتِ `prefix_central_tables` نامِ جدول‌ها را
        // `central_` ع前缀 می‌کند و مدل `central_operator_activities` را دارد. اگر
        // اینجا اسمِ خام را چک می‌کردیم، بعد از prefix همیشه `false` می‌شد و
        // **هیچ رویدادِ ممیزی‌ای هرگز ثبت نمی‌شد** — بی‌صدا. همان باگی که J5
        // داشت، فقط با یک رشتهٔ اشتباه برمی‌گردد.
        if (! Schema::hasTable((new OperatorActivity)->getTable())) {
            return;
        }

        $user = isset($payload['user_id']) && $payload['user_id'] !== null
            ? User::find($payload['user_id'])
            : null;

        OperatorActivity::record(
            $user,
            (string) $payload['action'],
            is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
            isset($payload['ip']) ? (string) $payload['ip'] : null,
        );
    }
}