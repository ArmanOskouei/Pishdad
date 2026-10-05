<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\Push\VapidKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ثبت و لغو اشتراک Web Push.
 *
 * ## چرا عمومی و بدون احراز هویت
 *
 * بازدیدکنندهٔ سایت حساب ندارد، ولی می‌خواهد اعلان بگیرد. پس مسیرِ ثبت
 * **نباید** پشت `auth` باشد.
 *
 * این که چه چیزی را می‌توان کرد، به خودِ داده محدود می‌شود نه به هویت:
 *
 * • ثبت: هر کسی می‌تواند اشتراکِ خودِ مرورگر را ثبت کند. کاری که
 *   فقط روی *دستگاه خودِ او* اثر دارد — نه چیزی که بتوان از آن سوءاستفاده
///   کرد.
/// • لغو: فقط با `endpoint` ممکن است، و endpoint همان کلیدِ خصوصیِ
///   دسترسی است. کسی که endpoint را ندارد، نمی‌تواند چیزی را لغو کند.
///
/// • خواندن لیست اشتراک‌ها: **فقط** برای مدیر و فقط با احراز هویت.
 */
class PushSubscriptionController extends Controller
{
    /** حداکثر طول endpoint (URL سرویس push). */
    private const MAX_ENDPOINT = 2048;

    /**
     * دامنه‌های مجاز برای `endpoint`.
     *
     * فقط همین سه ارائه‌دهنده از مرورگرهای امروزی endpoint می‌دهند:
     *
     * • Mozilla/Autotrigger — ‎`push.services.mozilla.com`
     * • Chrome/Edge (FCM) — ‎`fcm.googleapis.com`
     * • Windows Push Notification Service — ‎`notify.windows.com`
     *
     * این فهرست عمداً **بسته** است: هر دامنهٔ دیگری باید اول به کد
     * اضافه شود تا provider مربوطه هم مسیر رمزگذاری/ارسالش تأیید شود.
     */
    private const PROVIDER_HOST_SUFFIXES = [
        'push.services.mozilla.com',
        'fcm.googleapis.com',
        'notify.windows.com',
    ];

    /**
     * ثبت یا به‌روزرسانی یک اشتراک.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // ‎endpoint` تنها چیزی است که به ما اجازه می‌دهد push بفرستیم،
            // پس باید دقیقاً یک URL معتبرِ `https` باشد.
            'endpoint' => ['required', 'string', 'max:'.self::MAX_ENDPOINT, 'url:https'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        $this->assertKnownProviderEndpoint($validated['endpoint']);

        $userId = optional($request->user())->getAuthIdentifier();

        $existing = PushSubscription::query()
            ->where('endpoint', $validated['endpoint'])
            ->first();

        // اگر اشتراک قبلاً وجود دارد ولی به کاربر دیگری تعلق دارد،
        // آن را **نمی‌بریم** — فقط کلیدهای عمومیِ همان دستگاه را
        // به‌روزرسانی می‌کنیم و `user_id` دست‌نخورده می‌ماند. دلیل:
        // endpoint یک کلید یکتاست و دزدیدنش یعنی سرقتِ مقصد push کسی
        // دیگر؛ ضمناً `user_id` باید هرگز از `x` به `y` جابه‌جا نشود
        // چون لیست اعلان‌های مدیر را آلوده می‌کند.
        $subscription = $existing ?? new PushSubscription(['endpoint' => $validated['endpoint']]);

        $subscription->fill([
            'p256dh' => $validated['keys']['p256dh'],
            'auth' => $validated['keys']['auth'],
            'locale' => $this->resolveLocale($request),
        ]);

        // فقط در ساختِ تازه `user_id` را می‌نویسیم.
        if ($existing === null) {
            $subscription->user_id = $userId === null ? null : (int) $userId;
        }

        $subscription->save();

        return response()->json([
            'ok' => true,
            'vapid_public_key' => VapidKeys::get()['publicKey'],
        ], $existing === null ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /**
     * لغو یک اشتراک با `endpoint`.
     *
     * پاسخ همیشه `ok` است — حتی اگر چیزی پیدا نشود. گفتن اینکه «این
     * endpoint وجود ندارد» به یک مهاجم اجازه می‌دهد حدس بزند چه
     * endpointهایی در سامانه هستند.
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:'.self::MAX_ENDPOINT],
        ]);

        PushSubscription::query()
            ->where('endpoint', $validated['endpoint'])
            ->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * وضعیت پشتیبانی مرورگر + کلید عمومی VAPID.
     *
     * فرانت این را یک‌بار می‌خواند تا بداند آیا اصلاً می‌تواند
     * `pushManager.subscribe()` را صدا بزند.
     */
    public function show(): JsonResponse
    {
        return response()->json([
            'supported' => true,
            'vapid_public_key' => VapidKeys::get()['publicKey'],
        ]);
    }

    /**
     * لیست اشتراک‌ها — فقط مدیر.
     *
     * ‎⚠️ `endpoint` در خروجی **نیست**. فقط شناسه و نوع ارائه‌دهنده
     * برگردانده می‌شود، چون endpoint عملاً کلیدِ ارسال push است.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();

        $subscriptions = PushSubscription::query()
            ->forUser($userId)
            ->get();

        return response()->json([
            'data' => $subscriptions->map(fn (PushSubscription $s) => [
                'id' => $s->id,
                // `provider` یک accessor است، نه ستون — پس مثل ویژگی خوانده
                // می‌شود، نه مثل متد.
                'provider' => $s->provider,
                'created_at' => $s->created_at?->toIso8601String(),
            ]),
            'count' => $subscriptions->count(),
        ]);
    }

    private function resolveLocale(Request $request): string
    {
        $locale = (string) $request->input('locale', '');

        return $locale !== '' && Str::length($locale) <= 10
            ? $locale
            : app()->getLocale();
    }

    /**
     * ‎`endpoint` باید متعلق به یکی از ارائه‌دهندگان *واقعی* Push باشد.
     *
     * ## چرا این gate حیاتی است
     *
     * مسیرِ ثبت **عمومی و بدون احراز هویت** است. یعنی هر کسی می‌تواند
     * یک URL دلخواه بفرستد و بعد `PushSender` با درخواست POST (به‌همراه
     * هدر `Authorization` و بدنهٔ رمزشده) به آن می‌رود. بدون allowlist این
     * یعنی **SSRF**: یک مهاجم می‌تواند endpoint را روی یک میزبان داخلی
     * بگذارد و از سرور ما برای آن درخواست بسازد — یا با timeout/خطای
     * اتصال، درخواست را از سرور ما به سرویس بیرونی بزند.
     *
     * فقط HTTPS هم کافی نیست، چون سرویس‌های داخلیِ HTTPS هم وجود دارند.
     * پس فهرستِ سفیدِ **دامنه** لازم است.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function assertKnownProviderEndpoint(string $endpoint): void
    {
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));

        // بدون host معتبر، اصلاً قابل allowlist کردن نیست.
        if ($host === '') {
            throw ValidationException::withMessages([
                'endpoint' => 'قالب endpoint معتبر نیست.',
            ]);
        }

        foreach (self::PROVIDER_HOST_SUFFIXES as $suffix) {
            // ‎`.example.com` نباید `evil-example.com` را هم قبول کند؛
            // برای همین نقطه را صریح چک می‌کنیم.
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'endpoint' => 'این endpoint متعلق به هیچ ارائه‌دهندهٔ پشتیبانی‌شدهٔ Push نیست.',
        ]);
    }
}