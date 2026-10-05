<?php

namespace App\Services\Audit;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ثبتِ رویدادهای ممیزیِ پنل — **نقطهٔ اتصالِ هسته**.
 *
 * ## چرا این‌جا است و نه در افزونه
 *
 * پیش‌تر `DevModeAudit` مستقیم `OperatorActivity::record()` را صدا می‌زد. یعنی
 * هسته برای ثبتِ یک لاگِ امنیتی به افزونهٔ مرکزی وابسته بود — و بدتر: اگر آن
 * افزونه نصب نبود، لاگِ امنیتی هم نمی‌رفت.
 *
 * حالا هسته فقط **می‌نویسد** و هر کسی می‌تواند **بخواند**:
 *
 *  - پیش‌فرض: `Log::info('audit.event', …)` — همیشه کار می‌کند، بدون افزونه.
 *  - اگر افزونه‌ای (مثل مرکز) جدولِ لاگِ خودش را داشته باشد، می‌تواند از راه
 *    `AuditSink` آن را ثبت کند. رابط است، نه import.
 *
 * این همان الگویی است که کل پروژه دنبالش می‌رود: هسته می‌داند **چه** اتفاقی
 * افتاده، افزونه تصمیم می‌گیرد **کجا** ثبتش کند.
 *
 * @see AuditSink  پیاده‌سازیِ خواننده (null یعنی فقط لاگِ لاراول)
 */
final class AuditTrail
{
    /**
     * @var list<AuditSink>
     */
    private static array $sinks = [];

    /**
     * افزونه‌ای می‌تواند بگوید رویدادها را کجا بنویسد.
     *
     * ⚠️ فقط از راه `bootBundledPlugins()` یا هر جای مطمئن — چون ثبتِ sink
     * سراسری است و یک افزونهٔ بداندار می‌تواند لاگِ همه را بربرد.
     */
    public static function pushSink(AuditSink $sink): void
    {
        self::$sinks[] = $sink;
    }

    /**
     * رویدادِ امنیتی را ثبت می‌کند.
     *
     * `$user` می‌تواند `null` باشد — **و این مهم‌ترین حالت است.** رویدادِ
     * «تلاش برای بازکردنِ قفلِ حالتِ توسعه *پیش از* ورود» کاربر ندارد، و دقیقاً
     * همان رویدادی است که باید ثبت شود. پس کاربرِ `null` حذف نمی‌شود، فقط
     * `user_id` خالی می‌ماند.
     *
     * ⚠️ **هرگز throw نمی‌کند.** این مسیر در `DevModeUnlock` صدا زده می‌شود و
     * یک خطای لاگ نباید بازکردنِ قفلِ حالتِ توسعه را متوقف کند — و برعکس، نباید
     * بی‌صدا رد شود. پس شکست‌ها لاگ می‌شوند ولی exception ندارند.
     */
    public static function record(?User $user, string $action, array $meta = [], ?string $ip = null): void
    {
        $payload = [
            'user_id' => $user?->id,
            'action' => $action,
            'meta' => $meta,
            'ip' => $ip,
            'at' => now()->toIso8601String(),
        ];

        foreach (self::$sinks as $sink) {
            try {
                $sink->record($payload);
            } catch (Throwable $e) {
                Log::error('audit.sink_failed', [
                    'action' => $action,
                    'user_id' => $user?->id,
                    'sink' => $sink::class,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        try {
            Log::info('audit.event', $payload);
        } catch (Throwable) {
            // اگر حتی لاگ هم بنویسد نشود، هیچ کاری نیست که بتوان کرد.
        }
    }
}