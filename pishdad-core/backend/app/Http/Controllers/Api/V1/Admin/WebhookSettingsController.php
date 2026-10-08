<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Webhooks\WebhookSender;
use App\Services\Webhooks\WebhookSettings;
use App\Services\Webhooks\WebhookSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * WF-L2 — وب‌هوکِ خروجی: رویدادهای نصب (انتشار صفحه، تیکت تازه) به یک URL دلخواه
 * با امضای HMAC — برای اتصال به n8n/Zapier و هر اتوماسیونِ دیگر.
 *
 * ## ⭐ راز هرگز از سرور برنمی‌گردد
 *
 * `show` فقط `secret_set` (بولین) می‌دهد، دقیقاً مثل `cdn_purge_token_set` و
 * `password_set`. متنِ راز فقط در پاسخِ `rotateSecret()` دیده می‌شود — یعنی یک
 * بار، بلافاصله بعد از ساخت. هیچ endpointِ دیگری (و هیچ لاگی) آن را برنمی‌گرداند.
 *
 * ## ⭐ چرا «ارسال آزمایشی» از صف بیرون است
 *
 * دکمهٔ تست باید **همین حالا** جواب بدهد تا مدیر بداند نشانی درست است یا نه.
 * اگر از outbox رد شود، پاسخ فقط می‌گوید «در صف نشست» و خطای واقعی ده دقیقه
 * بعد در آمار دیده می‌شود — یعنی یک دکمهٔ بی‌فایده. پس `test()` مستقیم
 * `WebhookSender` را صدا می‌زند و نتیجهٔ صادقانه (۴۲۲ با `sent=false`) می‌دهد.
 *
 * تست به `active` بی‌اعتناست: «آزمایشی» یعنی «همین الان بفرست».
 */
class WebhookSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => WebhookSettings::toArray() + [
                // قراردادِ امضا از سرور می‌آید تا UI آن را **تکرار** نکند — یک
                // رشتهٔ کپی‌شده در فرانت یعنی دو تعریف که روزی ناهمگون می‌شوند.
                'signature' => self::signatureReference(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['nullable', 'string', 'max:'.WebhookSettings::URL_MAX, 'url:http,https'],
            // راز می‌تواند نویسهٔ خاص داشته باشد ⇒ عمداً `no_markup` ندارد.
            'secret' => 'nullable|string|max:'.WebhookSettings::SECRET_MAX,
            'clear_secret' => 'sometimes|boolean',
            'active' => 'sometimes|boolean',
        ], [
            'url.url' => 'نشانی وب‌هوک معتبر نیست (مثل https://n8n.example.ir/webhook/cms).',
            'url.max' => 'نشانی وب‌هوک بیش از حد طولانی است (حداکثر '.WebhookSettings::URL_MAX.' نویسه).',
            'secret.max' => 'راز وب‌هوک بیش از حد طولانی است.',
            'active.boolean' => 'مقدار کلید فعال‌بودن معتبر نیست.',
        ]);

        WebhookSettings::save($validated);

        return response()->json([
            'message' => 'تنظیمات وب‌هوک ذخیره شد.',
            'data' => WebhookSettings::toArray(),
        ]);
    }

    /**
     * ⭐ ساختِ رازِ تازه؛ **تنها** پاسخی که متنِ راز را برمی‌گرداند.
     */
    public function rotateSecret(): JsonResponse
    {
        $secret = WebhookSettings::rotateSecret();

        return response()->json([
            'message' => 'راز تازه ساخته شد. همین حالا آن را در گیرنده بگذارید — دیگر نمایش داده نمی‌شود.',
            'data' => WebhookSettings::toArray() + ['secret' => $secret],
        ]);
    }

    /**
     * ارسالِ آزمایشی، مستقیم و با نتیجهٔ صادقانه: خطا ⇒ ۴۲۲ با `sent=false`.
     */
    public function test(): JsonResponse
    {
        $url = WebhookSettings::url();

        if ($url === null) {
            return response()->json([
                'message' => 'اول نشانی وب‌هوک را ذخیره کنید.',
                'data' => ['sent' => false],
            ], 422);
        }

        $secret = WebhookSettings::secret();

        if ($secret === null) {
            return response()->json([
                'message' => 'اول با دکمهٔ «ساخت راز تازه» یک راز بسازید.',
                'data' => ['sent' => false],
            ], 422);
        }

        try {
            WebhookSender::send($url, $secret, 'webhook.test', [
                'site' => (string) config('app.name'),
                'install_url' => rtrim((string) config('app.url'), '/'),
                'note' => 'این یک درخواستِ آزمایشی است. امضای X-PISHDAD-Signature را بررسی کنید.',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'ارسال آزمایشی ناموفق بود: '.$e->getMessage(),
                'data' => ['sent' => false],
            ], 422);
        }

        return response()->json([
            'message' => 'درخواست آزمایشی با موفقیت ارسال شد.',
            'data' => ['sent' => true],
        ]);
    }

    /**
     * ⭐ نامِ سرآیندها و رشتهٔ امضاشده، از **یک** منبعِ حقیقت.
     *
     * پنل همین را رندر می‌کند، پس اگر روزی نامِ سرآیند عوض شود، مستندات و UI با
     * هم می‌روند و گیرنده‌ای که راهنمای قدیمی را دنبال کرده کامپایل نمی‌شود.
     *
     * @return array<string, string>
     */
    public static function signatureReference(): array
    {
        return [
            'event' => WebhookSigner::HEADER_EVENT,
            'timestamp' => WebhookSigner::HEADER_TIMESTAMP,
            'signature' => WebhookSigner::HEADER_SIGNATURE,
            'delivery' => WebhookSigner::HEADER_DELIVERY,
            'signed_string' => WebhookSigner::HEADER_TIMESTAMP.'.'.'{raw_request_body}',
            'algorithm' => 'HMAC-SHA256 (hex, lowercase)',
        ];
    }
}