<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * ارسال پیام به سرویس‌های push.
 *
 * ## تفاوت سرویس‌ها
 *
 * هر سه سرویس بزرگ از یک شکل URL استفاده می‌کنند ولی **قالب header** یکی
 * نیست:
 *
 * • **Mozilla** و **FCM** ⇒ همان RFC 8291 (`aes128gcm` + VAPID).
/// • **Edge/WNS** ⇒ همان رمزنگاری، ولی هدر `Authorization` فرق می‌کند.
///   WNS کلید را در هدر `X-WNS-Key` می‌خواهد نه `k=` در هدر `vapid`.
///
/// ⚠️ اگر قالب WNS را با قالب VAPID یکی بگیریم، WNS خطای ۴۰۱ می‌دهد و
/// همهٔ اعلان‌های Edge بی‌صدا از دست می‌روند.
 */
class PushSender
{
    /** کدهایی که یعنی اشتراک مرده است و باید حذف شود. */
    private const GONE_STATUSES = [404, 410];

    /** timeout هر درخواست — سرویس push ممکن است کند باشد. */
    private const TIMEOUT = 10;

    /**
     * یک پیام را به یک اشتراک می‌فرستد.
     *
     * @return bool  آیا تحویل داده شد؟
     */
    public function send(PushSubscription $subscription, array $payload): bool
    {
        try {
            $encrypted = PushEncryptor::encrypt(
                $payload,
                $subscription->p256dh,
                $subscription->auth,
            );
        } catch (\Throwable $e) {
            // رمزنگاری شکست خورد ⇒ این اشتراک برای ما قابل استفاده نیست.
            Log::warning('push encryption failed', [
                'subscription' => $subscription->targetHint(),
                'reason' => $e->getMessage(),
            ]);

            return false;
        }

        $response = $this->post((string) $subscription->endpoint, $encrypted['body'], $encrypted['headers']);

        if ($response === null) {
            return false;
        }

        $status = $response->status();

        if ($response->successful()) {
            return true;
        }

        // اشتراک مرده ⇒ حذفش کن، وگرنه هر اعلان بعدی هم همین‌جا می‌شکند
        // و جدول بی‌پای بزرگ می‌شود.
        if (in_array($status, self::GONE_STATUSES, true)) {
            $this->forget($subscription);

            return false;
        }

        // ۴۰۱ یعنی کلید VAPID ما غلط است — این یک مشکل سراسری است، نه
        // مشکلِ این اشتراک، پس باید واضح ثبت شود.
        if ($status === Response::HTTP_UNAUTHORIZED) {
            Log::error('push rejected: VAPID signature invalid', [
                'subscription' => $subscription->targetHint(),
                // `provider` یک accessor است، نه متد — مثل ویژگی خوانده
                // می‌شود. فراخوانیِ `provider()` روی این مدل خطای
                // "Call to undefined method" می‌داد، یعنی لاگِ خطای اصلی
                // خودش کرش می‌کرد و علت اصلی گم می‌شد.
                'provider' => $subscription->provider,
            ]);

            return false;
        }

        Log::warning('push failed', [
            'subscription' => $subscription->targetHint(),
            'status' => $status,
            'body' => Str::limit((string) $response->body(), 200),
        ]);

        return false;
    }

    /**
     * پیام را به همهٔ اشتراک‌های یک کاربر (یا همهٔ بی‌نام‌ها) می‌فرستد.
     *
     * @return array{sent: int, failed: int}
     */
    public function broadcast(?int $userId, array $payload, int $limit = 500): array
    {
        $sent = 0;
        $failed = 0;

        PushSubscription::query()
            ->forUser($userId)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (PushSubscription $subscription) use ($payload, &$sent, &$failed): void {
                if ($this->send($subscription, $payload)) {
                    $sent++;
                } else {
                    $failed++;
                }
            });

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * پیام به **همهٔ** اشتراک‌ها — هم زائران بی‌نام و هم مدیران.
     *
     * برای اعلان «محتوای تازه منتشر شد» استفاده می‌شود؛ چون هر دو گروه
     * صریحاً در سایت opt-in کرده‌اند.
     *
     * ‎⚠️ در نسخهٔ اول فقط `broadcast(null)` صدا زده می‌شد و این یعنی
     * مدیری که از `/admin/notifications` گزینهٔ Push را روشن کرده بود
     * **هیچ‌وقت** اعلانی دریافت نمی‌کرد — یعنی آن بخش از UI عملاً
     * بی‌اثر بود.
     *
     * @return array{sent: int, failed: int}
     */
    public function broadcastAll(array $payload, int $limit = 500): array
    {
        $sent = 0;
        $failed = 0;

        PushSubscription::query()
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (PushSubscription $subscription) use ($payload, &$sent, &$failed): void {
                if ($this->send($subscription, $payload)) {
                    $sent++;
                } else {
                    $failed++;
                }
            });

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * POST به سرویس push.
     *
     * @param  array<string, string>  $headers
     */
    private function post(string $endpoint, string $body, array $headers): ?\Illuminate\Http\Client\Response
    {
        try {
            $request = Http::withHeaders($headers)
                ->timeout(self::TIMEOUT)
                ->withBody($body, 'application/octet-stream');

            // شمارهٔ `audience` در VAPID همان ریشهٔ endpoint است.
            $request = $request->withHeaders([
                'Authorization' => $this->authorizationFor($endpoint),
            ]);

            return $request->post($endpoint);
        } catch (\Throwable $e) {
            Log::warning('push transport failed', [
                'host' => parse_url($endpoint, PHP_URL_HOST) ?: 'unknown',
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** هدر احراز هویت، متناسب با ارائه‌دهنده. */
    private function authorizationFor(string $endpoint): string
    {
        $audience = rtrim(
            (string) (parse_url($endpoint, PHP_URL_SCHEME).'://'.parse_url($endpoint, PHP_URL_HOST)),
            '/',
        );

        $subject = $this->contact();

        // WNS کلید را در هدر جداگانه می‌خواهد.
        if (str_contains($endpoint, 'wns2-') || str_contains($endpoint, 'notify.windows.com')) {
            return 'vapid t='.$this->vapidToken($audience, $subject).',k='.VapidKeys::get()['publicKey'];
        }

        return VapidKeys::authorizationHeader($audience, $subject);
    }

    /** فقط بخش JWT، بدون `k=` — برای WNS که کلید را جدا می‌خواهد. */
    private function vapidToken(string $audience, string $subject): string
    {
        $header = Ecdh::b64u((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = Ecdh::b64u((string) json_encode([
            'aud' => $audience,
            'exp' => time() + 12 * 3600,
            'sub' => $subject,
        ]));

        $signingInput = $header.'.'.$payload;

        $keys = VapidKeys::get();

        return $signingInput.'.'.VapidKeys::signEs256($signingInput, $keys['privateKey']);
    }

    /** آدرس ایمیلی که به‌عنوان مخاطب VAPID اعلام می‌شود. */
    private function contact(): string
    {
        // ‎⚠️ ایمیل داخل JSONِ تنظیماتِ سایت است، نه یک ردیف جدا.
        // نسخهٔ اول `where('group','site')->where('key','email')` می‌خواند
        // که هیچ‌وقت وجود ندارد (کلید واقعی `global` است و ایمیل داخل
        // JSON آن قرار دارد) ⇒ همیشه `mailto:admin@localhost` می‌افتاد.
        $raw = DB::table('settings')
            ->where('group', 'site')
            ->where('key', 'global')
            ->value('value');

        $site = is_string($raw) ? json_decode($raw, true) : $raw;
        $email = is_array($site) ? (string) ($site['email'] ?? '') : '';

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)
            ? 'mailto:'.$email
            : 'mailto:admin@localhost';
    }

    private function forget(PushSubscription $subscription): void
    {
        PushSubscription::query()
            ->whereKey($subscription->getKey())
            ->delete();
    }
}