<?php

namespace App\Services\Webhooks;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * WF-L2 — تنها نقطه‌ای که به وب‌هوکِ بیرونی حرف می‌زند.
 *
 * ## ⭐ چرا `send()` عمداً throw می‌کند
 *
 * دقیقاً همان قراردادِ `TelegramSender`: این متد از داخل `Outbox::send()` صدا
 * زده می‌شود، پس استثنا یعنی سطر `failed`/retry و **دیده شدن**. اگر اینجا فقط
 * لاگ می‌زدیم، تحویلی که هرگز نرسیده در آمار «ارسال شد» می‌نشست.
 *
 * fail-soft در جایِ دیگری است و **در همین سرویس نیست**: `WebhookDispatcher`
 * هنگام ساختنِ سطرِ صف، هر استثنایی را می‌بلعد تا انتشار صفحه/ثبت تیکت نشکند.
 * یعنی مسیرِ درخواست هیچ‌وقت به این کلاس نمی‌رسد.
 *
 * ## ⭐ چرا راز در لحظهٔ ارسال خوانده می‌شود
 *
 * راز در `payload` سطرِ صف نوشته نمی‌شود (فقط یک رشتهٔ `recipient` داریم).
 * دلیل: `notification_deliveries` جدولی است که backup و dump و گزارشِ خطا آن
 * را می‌خوانند؛ رازِ امضا نباید در آن باشد. ضمناً چرخشِ راز باید سطرهای در
 * صف را هم بلافاصله با رازِ تازه معتبر کند — که با خواندن در لحظهٔ ارسال
 * خودبه‌خود اتفاق می‌افتد.
 */
final class WebhookSender
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws RuntimeException روی خطای شبکه یا پاسخِ غیر ۲xx (⇒ retry/`failed`)
     */
    public static function send(string $url, string $secret, string $event, array $data, ?string $deliveryId = null): void
    {
        if (trim($url) === '') {
            throw new RuntimeException('نشانی وب‌هوک خالی است.');
        }

        if ($secret === '') {
            throw new RuntimeException('راز وب‌هوک تنظیم نشده است.');
        }

        $sealed = (new WebhookSigner($secret))->seal($event, $data, null, $deliveryId ?? (string) Str::uuid());

        try {
            $response = Http::timeout((int) config('webhook.timeout', 10))
                ->connectTimeout((int) config('webhook.connect_timeout', 5))
                ->acceptJson()
                ->withHeaders($sealed['headers'])
                ->withBody($sealed['body'], 'application/json')
                ->post($url);
        } catch (Throwable $e) {
            // قطعی/timeout — retry معنی دارد (وضعیتِ گذراست)، پس پیام را
            // نگه می‌داریم و فقط علت را عوض می‌کنیم.
            throw new RuntimeException('اتصال به وب‌هوک برقرار نشد: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                'وب‌هوک خطا داد (HTTP '.$response->status().'): '.mb_substr((string) $response->body(), 0, 300)
            );
        }
    }
}
