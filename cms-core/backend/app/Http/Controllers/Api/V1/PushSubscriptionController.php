<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Admin\SiteSettingsController;
use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\Push\ProviderAllowlist;
use App\Services\Push\VapidKeys;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
/// • لغو: با `endpoint` ممکن است، ولی **فقط روی ردیفی که مالکش همان فراخوان
///   است** (یا بی‌صاحب باشد). بدون این محدودیت، هر کسی که endpoint را
///   بداند می‌توانست اشتراکِ کسِ دیگر را پاک کند.
///
/// • خواندن لیست اشتراک‌ها: **فقط** برای مدیر و فقط با احراز هویت.
 */
class PushSubscriptionController extends Controller
{
    /** حداکثر طول endpoint (URL سرویس push). */
    private const MAX_ENDPOINT = 2048;

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

            /**
             * F5.3 — اختیاری، و عمداً **بدون مقدارِ پیش‌فرض**.
             *
             * نبودنش یعنی «همهٔ انواع صفحه» (قاعدهٔ `scopeInterestedIn`) و
             * فرانت همین را می‌فرستد. پس نه مهاجرت لازم است، نه backfillِ ردیف‌های
             * موجود، نه تغییر در فرانت.
             *
             * ⚠️ چرا `nullable` ولی با `sometimes`: اگر فرانت کلید را نفرستد
             * نباید مقدارِ ذخیره‌شدهٔ قبلی را **پاک** کنیم — همان قاعدهٔ
             * `user_id` در پایین همین متد.
             */
            'topic' => ['sometimes', 'nullable', 'string', 'max:64'],

            /**
             * ⭐ `locale` فقط از فهرستِ واقعیِ زبان‌های سایت.
             *
             * قبلاً هر رشتهٔ ≤۱۰ نویسه‌ای پذیرفته می‌شد و مستقیم در ستون
             * می‌نشست. ولی این ستون **کلیدِ فیلتر** است
             * (`scopeInterestedIn`): مقدارِ ناشناخته نه به زبانِ هیچ صفحه‌ای
             * می‌خورد (پس اعلان‌ها را بی‌صدا از دست می‌داد) و نه فیلتر را
             * خنثی می‌کرد. ضمناً کنترل‌کرها (SpamAssassin) و طولِ ستون را دور
             * می‌زد.
             *
             * منبعِ حقیقت همان ثابتِ `SiteSettingsController::LOCALES` است
             * که پنل هم با آن زبان‌ها را می‌پذیرد — پس یک تعریف، دو مصرف‌کننده.
             *
             * ⚠️ مثل `topic` با `sometimes` است: نبودنش یعنی «زبانِ نامعلوم»
             * (که در فیلتر یعنی «محدودیتی ندارم») و **نباید** مقدارِ ذخیره‌شدهٔ
             * قبلی را پاک کند.
             */
            'locale' => ['sometimes', 'nullable', 'string', Rule::in(SiteSettingsController::LOCALES)],
        ], [
            'locale.in' => 'زبانِ ارسالی باید یکی از زبان‌های سایت باشد (fa یا en).',
        ]);

        $this->assertKnownProviderEndpoint($validated['endpoint']);

        $userId = $this->callerId($request);

        $existing = PushSubscription::query()
            ->where('endpoint', $validated['endpoint'])
            ->first();

        /**
         * ⭐⭐ قاعدهٔ مالکیت — دروازهٔ اصلیِ این مسیرِ عمومی.
         *
         * ردیفی که `user_id` غیرNULL دارد **مالِ** آن کاربر است. هر فراخوانِ
         * دیگری (حتی مهمان، چون `null` هم با `id` فرق دارد) **رد** می‌شود و
         * حتی یک بایت از ردیف را لمس نمی‌کند.
         *
         * چرا این‌قدر سفت؟ چون نسخهٔ قبل `fill()` را بی‌قید و بند اجرا می‌کرد و
         * فقط `user_id` را دست‌نخورده نگه می‌داشت. یعنی هر کسی که endpoint را
         * می‌دید می‌توانست `p256dh`/`auth` را با کلیدهای خودش بازنویسی کند:
         * مالکیت ردیف سرِ جایش می‌ماند ولی رمزنگاریِ RFC 8291 از آن به بعد با
         * کلیدِ غریبه بسته می‌شد ⇒ **هر** push بعدی بی‌صدا ۴۰۱ می‌خورد. یعنی
         * یک درخواست، ازکارانداختنِ کاملِ اعلان‌های یک کاربر.
         *
         * رد کردن به‌جای «بی‌صدا نادیده گرفتن» عمدی است: فرانت روی `!ok`
         * `false` برمی‌گرداند و به کاربر می‌گوید فعال‌سازی انجام نشد، به‌جای
         * اینکه تیک بزند و بعد اعلان‌ها هرگز نرسند.
         */
        if ($existing !== null && $existing->user_id !== null && $existing->user_id !== $userId) {
            throw ValidationException::withMessages([
                'endpoint' => 'این endpoint قبلاً برای کاربرِ دیگری ثبت شده است.',
            ]);
        }

        // ردیفِ بی‌صاحب (`user_id IS NULL`) یا مالِ خودِ کاربر ⇒ به‌روزرسانی
        // مجاز. برای ردیفِ تازه هم مسیرِ عادیِ همین شاخه است.
        $subscription = $existing ?? new PushSubscription(['endpoint' => $validated['endpoint']]);

        $subscription->fill([
            'p256dh' => $validated['keys']['p256dh'],
            'auth' => $validated['keys']['auth'],
        ]);

        /**
         * F5.3 — `topic` فقط وقتی نوشته می‌شود که فر واقعاً فرستاده باشد.
         *
         * `fill()` روی کلیدِ غایب کاری نمی‌کند، ولی `null` صریح آن را پاک
         * می‌کند؛ و پاک کردنِ topic ذخیره‌شده با یک درخواستِ بی‌ربط (مثلاً
         * تعویض کلیدِ دستگاه) بدتر از نداشتنش است.
         */
        if (array_key_exists('topic', $validated)) {
            $topic = trim((string) ($validated['topic'] ?? ''));

            $subscription->topic = $topic !== '' ? $topic : null;
        }

        /**
         * `locale` دقیقاً با همان قاعده: فقط اگر فر واقعاً فرستاده باشد.
         *
         * نسخهٔ قبل در هر `store()` آن را بازنویسی می‌کرد و وقتی فرانت نفرستاده
         * بود `app()->getLocale()` می‌نوشت — یعنی یک درخواستِ نامرتبط (تعویض
         * کلیدِ دستگاه) زبانِ واقعیِ مشترک را با زبانِ سرور عوض می‌کرد و اعلان‌ها
         * را بی‌صدا فیلتر می‌کرد. ردیفِ تازه با نبودنش `NULL` می‌ماند، و در
         * `scopeInterestedIn` خالی یعنی «محدودیتی ندارم» (قاعدهٔ مشترک).
         */
        if (array_key_exists('locale', $validated)) {
            $locale = trim((string) ($validated['locale'] ?? ''));

            $subscription->locale = $locale !== '' ? $locale : null;
        }

        // فقط در ساختِ تازه `user_id` را می‌نویسیم — و **هرگز** از بدنهٔ
        // درخواست: `user_id` در `fill()` بالا هم نبود.
        //
        // ⭐ `vapid_public_key` هم فقط همین‌جا نوشته می‌شود، و این تنها جایی
        // است که آن ستون زنده می‌ماند. بدونش، بعد از چرخشِ کلید هیچ راهی نبود
        // که بفهمیم کدام اشتراک مرده است: همه بی‌صدا ۴۰۱ می‌خوردند.
        //
        // ⚠️ چرا **فقط** روی ساخت و نه روی هر به‌روزرسانی: به‌روزرسانیِ این
        // مسیر معمولاً «تعویض کلیدِ دستگاه» است (همان endpoint، همان سرویس
        // push). جفتِ VAPIDِ ثبت‌شده در خودِ سرویس push با آن عوض نمی‌شود ⇒
        // بازنویسیِ این ستون یک ردیفِ واقعاً مرده را «تازه» نشان می‌داد و
        // دقیقاً همان تشخیصی را که می‌خواهیم از بین می‌برد.
        if ($existing === null) {
            $subscription->user_id = $userId;
            $subscription->vapid_public_key = VapidKeys::get()['publicKey'];
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
     * ⭐⭐ قاعدهٔ مالکیت (هم‌قاعده با `store`): فقط ردیف‌هایی پاک می‌شوند که
     * `user_id IS NULL` باشند یا `user_id`شان برابرِ فراخوان باشد. ردیفِ
     * کاربرِ دیگر **دست‌نخورده** می‌ماند — حتی با endpointِ درست.
     *
     * دلیل: مسیر عمومی است و فقط `throttle` دارد، پس endpoint یک رازِ کامل
     * نیست (لاگ مرورگر، دستگاهِ مشترک، پشتیبانی سایت…). نسخهٔ قبل با
     * `where('endpoint', …)->delete()` مالکیت را نادیده می‌گرفت، پس یک
     * endpointِ لو رفته یعنی امکان خاموش کردنِ اعلان‌های هر کسی در سامانه.
     *
     * ⚠️ پاسخ همیشه `ok` و همیشه `200` است — چه چیزی پاک شود، چه چیزی
     * نپاک شود، چه اصلاً چیزی با این endpoint نباشد. تفاوتِ وضعیت (پاک شد /
     * رد شد / نبود) خودش یک **اوراکلِ وجود** است: با آن، یک مهاجم می‌فهمد کدام
     * endpointها در سامانه ثبت شده‌اند و مالِ چه کسی‌اند.
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:'.self::MAX_ENDPOINT],
        ]);

        $this->ownedByCaller(
            PushSubscription::query()->where('endpoint', $validated['endpoint']),
            $this->callerId($request),
        )->delete();

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
     *
     * ⭐ `stale` اضافه شده تا چرخشِ کلید قابلِ مدیریت باشد: بدونِ آن، همهٔ
     * ردیف‌ها یکسان به نظر می‌رسند در حالی که فقط بعضی‌هایشان بعد از چرخش
     * بی‌اعتبار شده‌اند و بی‌صدا ۴۰۱ می‌خورند. اپراتور می‌تواند دقیقاً همان‌ها
     * را پاک/درخواستِ re-subscribe کند.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();

        $subscriptions = PushSubscription::query()
            ->forUser($userId)
            ->get();

        $currentVapidPublicKey = VapidKeys::get()['publicKey'];

        return response()->json([
            'data' => $subscriptions->map(fn (PushSubscription $s) => [
                'id' => $s->id,
                // `provider` یک accessor است، نه ستون — پس مثل ویژگی خوانده
                // می‌شود، نه مثل متد.
                'provider' => $s->provider,
                'created_at' => $s->created_at?->toIso8601String(),
                'stale' => $s->isStaleFor($currentVapidPublicKey),
            ]),
            'count' => $subscriptions->count(),
        ]);
    }

    /**
     * شناسهٔ کاربرِ فراخوان، یا `null` برای مهمان.
     *
     * ⚠️ `getAuthIdentifier()` می‌تواند رشته برگرداند (مدلِ auth قابل تعویض)،
     * ولی `user_id` یک ستونِ `integer` است ⇒ همیشه به `int` تبدیل می‌شود تا
     * مقایسهٔ مالکیت با `!==` (که در `store` استفاده می‌شود) روی نوع‌های
     * ناهمگون به شکستِ سکوت نیفتد.
     */
    private function callerId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (int) $id;
    }

    /**
     * محدود کردنِ کوئری به ردیف‌هایی که فراخوان حقِ نوشتن/پاک کردنشان را دارد.
     *
     * • مهمان (`null`) ⇒ فقط `user_id IS NULL`.
     * • کاربر ⇒ `user_id IS NULL` **یا** `user_id = خودش`.
     *
     * ⚠️ صفتِ «بی‌صاحب» عمداً باز است: `store` ردیفِ بی‌صاحب را به کسی نمی‌سپارد
     * (`user_id` فقط در ساختِ تازه نوشته می‌شود)، پس لازم است مهمان بتواند
     * همان دستگاهِ بی‌نامش را دوباره ثبت یا لغو کند — بدون این، لغوِ سایتِ
     * عمومی کاملاً از کار می‌افتاد. در عوض، به این معنا **نیست** که هر کسی
     * بتواند ردیفِ یک کاربرِ واردشده را لمس کند.
     *
     * @param  Builder<PushSubscription>  $query
     * @return Builder<PushSubscription>
     */
    private function ownedByCaller(Builder $query, ?int $userId): Builder
    {
        return $query->where(
            fn (Builder $owned) => $owned
                ->whereNull('user_id')
                ->when(
                    $userId !== null,
                    fn (Builder $withOwner) => $withOwner->orWhere('user_id', $userId),
                )
        );
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
     * @throws ValidationException
     */
    private function assertKnownProviderEndpoint(string $endpoint): void
    {
        // تعریفِ فهرست در `ProviderAllowlist` است تا همان یک تعریف هم در
        // مسیرِ ثبت و هم در sinkِ ارسال (`PushSender::post`) اعمال شود.
        if (! ProviderAllowlist::allows($endpoint)) {
            throw ValidationException::withMessages([
                // host خالی یعنی قالب معتبر نیست، نه ارائه‌دهندهٔ ناشناخته.
                'endpoint' => ProviderAllowlist::host($endpoint) === ''
                    ? 'قالب endpoint معتبر نیست.'
                    : 'این endpoint متعلق به هیچ ارائه‌دهندهٔ پشتیبانی‌شدهٔ Push نیست.',
            ]);
        }
    }
}
