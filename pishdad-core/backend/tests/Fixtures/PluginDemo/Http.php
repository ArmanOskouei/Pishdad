<?php

namespace Pishdad\Plugins\Demo\Http;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * K7.11 — fixtureهای اجرایی برای تست dispatch.
 *
 * این‌ها کلاس‌های واقعی‌اند نه mock. `PluginDispatcher` آن‌ها را از موتور PSR-4
 * هسته بارگذاری می‌کند، پس تست مسیر واقعی را می‌پیماید: اعلان مانیفست → resolve
 * → بارگذاری → فراخوانی. با mock، هیچ‌کدام از guardهای واقعاً آزموده نمی‌شد.
 *
 * محل این‌ها کنار تست است و **عمداً** داخل هسته نیست: نگهبانِ مرز
 * و قاعدهٔ prefix در `PluginAutoloader` هر دو
 * namespace اجباری را می‌سنجند، و fixtureی که داخل هسته باشد دقیقاً همان
 * چیزی می‌شد که آن guardها باید جلویش را بگیرند.
 */

/** پاسخ ساده برای اثبات اینکه کد واقعاً اجرا شد. */
class PingController
{
    public function show(): JsonResponse
    {
        return response()->json(['pong' => 'pong']);
    }

    /**
     * خصوصی عمداً است تا guard «متد عمومی» را بسنجد. `method_exists` این را true
     * می‌گوید، پس بدون بررسی جداگانه از بیرون قابل اجرا می‌شد.
     */
    private function privateMethod(): JsonResponse
    {
        return response()->json(['leaked' => true]);
    }
}

/** پارامتر مسیر باید برسد. */
class CallbackController
{
    public function store(string $id): JsonResponse
    {
        return response()->json(['plugin' => 'demo', 'id' => $id]);
    }
}

/** استثنا باید به کاربر نرسد. */
class ThrowingController
{
    public function show(): JsonResponse
    {
        // رشته‌ها عمداً شبیه چیزی‌اند که یک استثنای واقعی لو می‌دهد.
        throw new RuntimeException('SECRET-LEAK: /var/www/html/config/database.php with pg_password');
    }
}
