<?php

namespace App\Services\Plugins;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Pishdad\Plugins\Central\Models\OperatorActivity;
use Throwable;

/**
 * لایهٔ نازک بین هسته و ردپای مرکزی (K4.5).
 *
 * ## چرا این غلاف وجود دارد
 *
 * `central_operator_activities` **متعلق به پنل مرکزی است**، و K7.8 آن را به
 * افزونه منتقل می‌کند. یعنی جدولش در آینده‌ای نزدیک از هسته می‌رود.
 *
 * اگر `DevModeUnlock` مستقیم `Pishdad\Plugins\Central\Models\OperatorActivity` را صدا
 * می‌کرد، `CentralBoundaryTest` درست آن را یک نقض مرز می‌دید — و درست است. آن
 * تست نگهبانِ `App\Models\Central` است در برابر ارجاع از هسته، و اینجا دقیقاً
 * همان ارجاعی بود که می‌خواست جلویش را بگیرد.
 *
 * راه درست این نیست که فایل را به `KNOWN_VIOLATIONS` اضافه کنیم. آن فهرست
 * استثنای **بدهیِ شناخته‌شده** است، نه راه دور زدن نگهبان. بدهی تازه اضافه‌کردن
 * یعنی تبدیل یک مرز معماری به عادت.
 *
 * این غلاف تنها جایی است که به مرکزی اشاره می‌کند، و همهٔ سرویس‌های دیگر فقط
 * `DevModeAudit` را می‌شناسند. وقتی K7.8 انجام شد، **فقط همین یک فایل** باید
 * عوض شود.
 *
 * ## چرا خطا اینجا خورده و ادامه پیدا کرده
 *
 * ردپا ابزار عیب‌یابی است، نه دروازه. اگر جدول مرکزی در دسترس نباشد یا
 * `operator_activities` خراب باشد، باز کردن حالت توسعه‌دهنده نباید شکست بخورد —
 * وگرنه یک مشکل ذخیره‌سازی، کل دروازهٔ امنیتی را از کار می‌اندازد و کاربر
 * راهی برای نصب بستهٔ خودش ندارد. ولی بی‌صدا هم نمی‌شود: لاگ می‌شود.
 */
class DevModeAudit
{
    public function record(?User $user, string $ip, string $action, array $meta = []): void
    {
        try {
            OperatorActivity::record($user, $action, $meta, $ip);
        } catch (Throwable $e) {
            Log::error('devmode.audit_failed', [
                'action' => $action,
                'user_id' => $user?->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
