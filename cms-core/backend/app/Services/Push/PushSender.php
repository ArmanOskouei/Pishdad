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
 * ## ارائه‌دهنده‌های پشتیبانی‌شده
 *
 * فقط **Mozilla/Autotrigger** و **FCM (Chrome/Edge)** پشتیبانی می‌شوند و هر دو
 * از یک قراردادِ کاملاً یکسان پیروی می‌کنند: رمزنگاریِ RFC 8291
 * (`aes128gcm`) به‌همراهِ امضای VAPID در هدر `Authorization: vapid t=…,k=…`.
 * پس در این بیلد تفکیکِ هدرِ احراز هویت میان ارائه‌دهنده‌ها لازم نیست.
 *
 * ⛔ **Windows/WNS (`notify.windows.com`) پشتیبانی نمی‌شود و fail-closed است.**
 *
 * ⚠️ اشتباهی که این‌جا رفع شد: نسخهٔ قبل یک شاخهٔ «Edge/WNS» داشت که ادعا
 * می‌کرد WNS کلید را در هدرِ جداگانهٔ `X-WNS-Key` می‌خواهد، ولی در عمل همان
 * رشتهٔ `vapid t=…,k=…` را برمی‌گرداند و آن هدر را اصلاً نمی‌فرستاد.
 *
 * حقیقت این است که WNS اصلاً VAPID را نمی‌پذیرد: احراز هویتش یک توکنِ
 * OAuth است (`Authorization: Bearer …` از Azure AD) و آدرسِ درخواست هم
 * **نسبی** است (`/notify/?token=…`). هیچ‌کدام از این‌ها در این بیلد پیاده نشده
 * بود، پس هر اشتراکِ Edge بی‌صدا ۴۰۱ می‌گرفت و هیچ اعلانی نمی‌رسید — بدترین
 * حالت: سرویسی که کار نمی‌کند ولی خودش را سالم نشان می‌دهد.
 *
 * پس به‌جای نگه‌داشتنِ شاخه‌ای که همیشه شکست می‌خورد، **fail-closed** انتخاب
 * شد: دامنهٔ WNS از `ProviderAllowlist` بیرون است، پس هم در مسیرِ ثبت رد
 * می‌شود (۴۲۲ با پیامِ روشن) و هم در `post()` پیش از هر HTTP، با یک سطر
 * `warning`. برگرداندنِ پشتیبانی نیاز به پیاده‌سازیِ واقعیِ OAuth و مسیرِ
 * نسبی دارد — نه صرفِ برگرداندنِ یک خط به یک آرایه.
 */
class PushSender
{
    /** کدهایی که یعنی اشتراک مرده است و باید حذف شود. */
    private const GONE_STATUSES = [404, 410];

    /** timeout هر درخواست — سرویس push ممکن است کند باشد. */
    private const TIMEOUT = 10;

    /** حداکثر طولِ بدنهٔ پاسخ که در لاگ می‌رود. */
    private const BODY_LOG_LIMIT = 200;

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
            /**
             * ⭐⭐ تفاوتِ «همیشه خراب» با «الان خراب».
             *
             * کلیدهای `p256dh`/`auth` دادهٔ ورودی‌اند که **هرگز عوض نمی‌شوند**.
             * اگر ساختارشان غلط باشد، رمزنگاری در هر تلاش و برای هر اعلانِ
             * بعدی هم fail می‌شود ⇒ retry بی‌نهایت + لاگِ تکراری.
             *
             * ⚠️ معیار، خودِ داده است نه پیامِ استثنا: پیام‌ها متنِ ناپایدار دارند
             * و به آن‌ها تکیه کردن یعنی یک تستِ شکننده.
             */
            if (! $this->keysAreStructurallyValid($subscription)) {
                Log::warning('push subscription pruned: invalid keys', [
                    'subscription' => $subscription->targetHint(),
                    'reason' => $e->getMessage(),
                ]);

                $this->forget($subscription);

                return false;
            }

            // خطای موقت (کلیدِ اشتراک سالم است ولی رمزنگاری همین‌جا شکست خورد)
            // ⇒ retry بماند، دقیقاً مثل رفتارِ قبلی.
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
            /**
             * ⚠️ بدنه از **طرفِ مقابل** است. `Str::limit` به‌تنهایی کافی نیست:
             * newline و کنترل‌کاراکترها را نگه می‌دارد و یک سرویسِ push
             * می‌تواند یک سطرِ جعلی در لاگِ ما بسازد (log forging).
             */
            'body' => $this->logSafeSnippet((string) $response->body()),
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
        // ⚠️ F5.3-c — محافظ SSRF در خودِ sink ارسال، نه فقط در مسیر ثبت.
        // endpoint ممکن است از راه‌های دیگر (seed/import/دادهٔ قدیمی) وارد
        // پایگاه‌داده شده باشد؛ پس هر POST باید مستقلاً allowlist را رد کند.
        // فقط `host` لاگ می‌شود، نه endpoint کامل (که توکن/کلید در آن است).
        if (! ProviderAllowlist::allows($endpoint)) {
            Log::warning('push endpoint rejected', [
                'host' => ProviderAllowlist::host($endpoint) ?: 'unknown',
            ]);

            return null;
        }

        try {
            $request = Http::withHeaders($headers)
                ->timeout(self::TIMEOUT)
                // ریدایرکت دنبال نمی‌شود: وگرنه یک دامنهٔ مجاز می‌تواند ما را
                // به یک میزبان داخلی هدایت کند و allowlist را دور بزند.
                ->withOptions(['allow_redirects' => false])
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

    /**
     * هدر احراز هویت — یکسان برای هر دو ارائه‌دهندهٔ پشتیبانی‌شده.
     *
     * ⛔ **هیچ شاخهٔ Windows/WNS این‌جا وجود ندارد، و عمداً هم نخواهد داشت.**
     *
     * نسخهٔ قبل یک شاخهٔ Edge داشت که ادعا می‌کرد «WNS کلید را در هدر جداگانه
     * می‌خواهد» — ولی در عمل دقیقاً همان رشتهٔ `vapid t=…,k=…` را برمی‌گرداند
     * و آن هدر را اصلاً نمی‌فرستاد.
     *
     * حقیقت: WNS هیچ VAPIDای نمی‌پذیرد. احراز هویتش یک توکنِ OAuth است
     * (`Authorization: Bearer …` از Azure AD) و آدرسِ تحویل هم **نسبی** است
     * (`/notify/?token=…`). چون هیچ‌کدام در این بیلد پیاده نشده بود، هر
     * اشتراکِ Edge بی‌صدا ۴۰۱ می‌گرفت و هرگز اعلانی نمی‌رسید.
     *
     * پس به‌جای شاخه‌ای که همیشه شکست می‌خورد، مسیر **fail-closed** انتخاب
     * شد: `notify.windows.com` از `ProviderAllowlist` بیرون است، پس endpoint
     * پیش از رسیدن به این‌جا در `post()` رد می‌شود. برگرداندنِ WNS نیاز به
     * پیاده‌سازیِ واقعیِ OAuth و مسیرِ نسبی دارد — نه یک شرطِ ساده اینجا.
     */
    private function authorizationFor(string $endpoint): string
    {
        $audience = rtrim(
            (string) (parse_url($endpoint, PHP_URL_SCHEME).'://'.parse_url($endpoint, PHP_URL_HOST)),
            '/',
        );

        return VapidKeys::authorizationHeader($audience, $this->contact());
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

    /**
     * ⭐ آیا کلیدهای اشتراک **ساختاراً** قابل استفاده‌اند؟
     *
     * `PushEncryptor` سه جا می‌تواند به‌خاطرِ دادهٔ اشتراک بشکند:
     *  - `p256dh` نه base64url معتبر است (`Ecdh::base64UrlToRaw`)،
     *  - نقطه طول/فرمت درست ندارد یا **روی منحنی نیست** (`Ecdh::decodePoint`)،
     *  - `auth` دقیقاً ۱۶ بایت نیست.
     *
     * هر سه **تغییرناپذیرند**: retry دوباره همان استثنا را می‌دهد. پس چنین
     * ردیفی باید حذف شود، وگرنه هر انتشارِ بعدی دوباره رمز می‌کند، دوباره
     * لاگ می‌اندازد و سطرش تا ابد `failed` می‌ماند.
     *
     * ⚠️ «نداشتنِ کلید» خرابی نیست: مرورگرهای قدیمی کلید نمی‌دهند و
     * RFC 8291 §5.2 صریحاً مسیرِ رمزنشده را مجاز می‌داند.
     */
    private function keysAreStructurallyValid(PushSubscription $subscription): bool
    {
        if (! $subscription->canEncrypt()) {
            return true;
        }

        try {
            Ecdh::decodePointFromBase64Url((string) $subscription->p256dh);

            return strlen(Ecdh::b64d((string) $subscription->auth)) === 16;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * ⭐⭐ بدنهٔ پاسخِ سرویس را برای لاگ **تک‌خطی و بی‌خطر** می‌کند.
     *
     * داده از یک سرویسِ بیرونی است و در اختیارِ اوست. اگر خام وارد لاگ شود:
     *  - یک `CRLF` می‌تواند یک **سطرِ کامل جعلی** بسازد و هر ابزارِ
     *    پایش/جست‌وجوی لاگ را گمراه کند،
     *  - یک بدنهٔ حجیم می‌تواند کلِ لاگ را پر کند.
     *
     * پس: اول کنترل‌کاراکترها (`\x00-\x1F` و `\x7F`) حذف، بعد فاصله‌ها جمع،
     * و آخر **بایت**-محور بریده می‌شود تا `mb_*` روی ورودیِ نامعتبرِ UTF-8
     * گیر نکند.
     */
    private function logSafeSnippet(string $body): string
    {
        // بدون `u` عمداً است: `preg_replace` روی ورودیِ باینری/بدِ UTF-8 با
        // مودِ `u` مقدارِ `null` برمی‌گرداند و کل لاگ را از کار می‌اندازد.
        $collapsed = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $body);
        $collapsed = is_string($collapsed) ? $collapsed : '';

        $collapsed = preg_replace('/\s+/', ' ', $collapsed) ?? '';

        return Str::limit(trim($collapsed), self::BODY_LOG_LIMIT);
    }

    private function forget(PushSubscription $subscription): void
    {
        PushSubscription::query()
            ->whereKey($subscription->getKey())
            ->delete();
    }
}