<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\ContentPublishedNotifier;
use App\Services\Push\Ecdh;
use App\Services\Push\PushSender;
use App\Services\Push\VapidKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * تست اعلان «محتوای تازه منتشر شد».
 *
 * ## چرا این تست‌ها لازم‌اند
 *
 * `PushSender` و `ContentPublishedNotifier` قبلاً **هیچ تستی نداشتند** و
 * هر سه باگ زیر در آن‌ها بود و کسی متوجه نشد:
 *
 * ۱. `broadcast(null)` فقط زائران را می‌گرفت ⇒ مدیری که از پنل opt-in
 *    کرده بود هیچ‌وقت اعلان نمی‌گرفت.
 * ۲. لاگ خطای ۴۰۱ با `$subscription->provider()` نوشته می‌شد در حالی که
 *    `provider` یک accessor است ⇒ خودِ لاگ کرش می‌کرد.
 * ۳. `contact()` ردیفِ ناموجودِ `key='email'` را می‌خواند ⇒ همیشه
 *    `mailto:admin@localhost` می‌افتاد و VAPID با مخاطبِ نادرست
 *    امضا می‌شد.
 *
 * @internal
 */
class ContentPublishedNotifierTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/test-token';

    protected function setUp(): void
    {
        parent::setUp();

        // کلید VAPID باید از قبل ساخته شده باشد تا امضا هزار بار ساخته
        // نشود؛ ضمناً مطمئن می‌شویم decrypt روی مسیر واقعی سالم است.
        VapidKeys::get();
    }

    /**
     * کلیدهای معتبرِ مرورگر تا `PushEncryptor` واقعاً موفق شود.
     *
     * اگر کلید ساختگی باشد، رمزنگاری exception می‌دهد و `send()` قبل از
     * رسیدن به HTTP false برمی‌گرداند — یعنی تست به‌جای رفتار واقعی،
     * مسیر شکست را می‌سنجید (و در واقع اولین نسخهٔ همین تست همین اشتباه را
     * کرد: نقطهٔ ۶۴ بایتی بدون پیشوند `0x04` داده بود).
     *
     * @return array{p256dh: string, auth: string}
     */
    private function validBrowserKeys(): array
    {
        $pair = Ecdh::generateKeyPair();

        return [
            'p256dh' => $pair['public'],
            'auth' => Ecdh::b64u(random_bytes(16)),
        ];
    }

    private function subscribe(?User $user = null, string $endpoint = self::ENDPOINT): PushSubscription
    {
        $keys = $this->validBrowserKeys();

        return PushSubscription::query()->create([
            'endpoint' => $endpoint,
            'p256dh' => $keys['p256dh'],
            'auth' => $keys['auth'],
            'user_id' => $user?->id,
        ]);
    }

    private function publishedPage(string $slug = 'faq', string $title = 'پرسش‌های متداول'): Page
    {
        $page = new Page([
            'slug' => $slug,
            'title' => $title,
            'status' => Page::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $page->save();

        return $page;
    }

    // ── broadcastAll: زائر *و* مدیر ────────────────────────────────

    /**
     * 🔴 رگرسیون: اعلان باید به زائر بی‌نام **و** به مدیر برسد.
     */
    public function test_announcement_reaches_both_visitors_and_admins(): void
    {
        $admin = User::factory()->create();

        $this->subscribe(null, 'https://fcm.googleapis.com/fcm/send/visitor');
        $this->subscribe($admin, 'https://fcm.googleapis.com/fcm/send/admin');

        Http::fake(['*' => Http::response('', 201)]);

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        Http::assertSentCount(2);

        $hosts = Http::recorded()->map(fn (array $pair) => $pair[0]->url())->all();

        $this->assertContains('https://fcm.googleapis.com/fcm/send/visitor', $hosts);
        $this->assertContains('https://fcm.googleapis.com/fcm/send/admin', $hosts);
    }

    /**
     * ⚠️ نسخهٔ اول فقط `broadcast(null)` صدا می‌زد و چون
     * `ContentPublishedNotifier` هم `whereNull('user_id')` می‌کرد، مدیر
     * عملاً هرگز اعلان نمی‌گرفت — با اینکه UI برایش کارت Push داشت.
     */
    public function test_a_lone_admin_subscription_still_receives_the_announcement(): void
    {
        $admin = User::factory()->create();

        $this->subscribe($admin);

        Http::fake(['*' => Http::response('', 201)]);

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        Http::assertSentCount(1);
    }

    public function test_nothing_is_sent_when_there_are_no_subscribers(): void
    {
        Http::fake();

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        Http::assertNothingSent();
    }

    /**
     * اعلان فقط برای محتوایِ **منتشرشده** — نه پیش‌نویس.
     */
    public function test_draft_pages_do_not_trigger_an_announcement(): void
    {
        $this->subscribe();

        Http::fake();

        $draft = new Page([
            'slug' => 'draft',
            'title' => 'پیش‌نویس',
            'status' => Page::STATUS_DRAFT,
        ]);

        $draft->save();

        $this->app->make(ContentPublishedNotifier::class)->announce($draft);

        Http::assertNothingSent();
    }

    // ── شکست نباید انتشار را از کار بیندازد ─────────────────────────

    /**
     * ⚠️ خرابی سرویس push نباید باعث ۵۰۰ شدن صفحهٔ انتشار شود.
     */
    public function test_a_transport_failure_does_not_throw(): void
    {
        $this->subscribe();

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('boom'));

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        $this->assertTrue(true, 'انتشار نباید exception بدهد');
    }

    // ── قرارداد درخواست HTTP ───────────────────────────────────────

    public function test_request_carries_the_rfc_8291_and_vapid_headers(): void
    {
        $this->subscribe();

        Http::fake(['*' => Http::response('', 201)]);

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        /** @var Request $request */
        $request = Http::recorded()->first()[0];

        $this->assertSame('aes128gcm', $request->header('Content-Encoding')[0] ?? null);
        $this->assertNotEmpty($request->header('Encryption')[0] ?? null);

        // هدر احراز هویت VAPID باید حاضر باشد.
        $authorization = $request->header('Authorization')[0] ?? '';

        $this->assertStringStartsWith('vapid t=', $authorization);
        $this->assertStringContainsString(',k=', $authorization);

        // بدنه باید رمز شده باشد، نه JSON خام.
        $this->assertStringNotContainsString('پیشداد', $request->body());
    }

    /**
     * ‎`audience` در VAPID باید ریشهٔ endpoint باشد (RFC 8292 §2).
     */
    public function test_vapid_audience_is_the_endpoint_origin(): void
    {
        $this->subscribe();

        Http::fake(['*' => Http::response('', 201)]);

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        /** @var Request $request */
        $request = Http::recorded()->first()[0];

        $authorization = $request->header('Authorization')[0] ?? '';
        $token = explode(',k=', $authorization)[0];
        $payload = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);

        $this->assertSame('https://fcm.googleapis.com', $payload['aud'] ?? null);
    }

    /**
     * ایمیلِ VAPID باید از تنظیمات سایت خوانده شود، نه همیشه localhost.
     *
     * ‎⚠️ نسخهٔ اول `where('group','site')->where('key','email')` را
     * می‌خواند که چنین ردیفی وجود ندارد.
     */
    public function test_vapid_contact_uses_the_configured_site_email(): void
    {
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(
            ['group' => 'site', 'key' => 'global'],
            [
                'value' => json_encode(['email' => 'hello@example.org'], JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ],
        );

        $this->subscribe();

        Http::fake(['*' => Http::response('', 201)]);

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        /** @var Request $request */
        $request = Http::recorded()->first()[0];

        $authorization = $request->header('Authorization')[0] ?? '';
        $token = explode(',k=', $authorization)[0];
        $payload = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);

        $this->assertSame('mailto:hello@example.org', $payload['sub'] ?? null);
    }

    // ── بهداشت جدول: اشتراک مرده باید پاک شود ─────────────────────

    /**
     * ۴۱۰ یعنی endpoint برای همیشه مُرده ⇒ باید حذف شود، وگرنه هر اعلان
     * بعدی هم شکست می‌خورد و جدول بی‌پای بزرگ می‌شود.
     */
    public function test_a_gone_subscription_is_deleted(): void
    {
        $subscription = $this->subscribe();

        Http::fake(['*' => Http::response('', 410)]);

        $result = $this->app->make(PushSender::class)->broadcastAll(['title' => 'x']);

        $this->assertSame(['sent' => 0, 'failed' => 1], $result);
        $this->assertDatabaseMissing('push_subscriptions', ['id' => $subscription->id]);
    }

    /**
     * 🔴 رگرسیون: ۴۰۱ نباید کرش کند.
     *
     * مسیر ۴۰۱ یک لاگ می‌نوشت که `$subscription->provider()` را صدا
     * می‌زد، در حالی که `provider` یک accessor است. یعنی **لاگِ خطا
     * خودش** exception می‌داد و علت اصلی (کلید VAPID غلط) گم می‌شد.
     */
    public function test_an_unauthorized_response_does_not_crash(): void
    {
        $subscription = $this->subscribe();

        Http::fake(['*' => Http::response('invalid vapid', 401)]);

        $result = $this->app->make(PushSender::class)->broadcastAll(['title' => 'x']);

        $this->assertSame(['sent' => 0, 'failed' => 1], $result);

        // ۴۰۱ یک مشکل سراسری است (کلید VAPID)، نه مشکل این اشتراک.
        $this->assertDatabaseHas('push_subscriptions', ['id' => $subscription->id]);
    }

    /**
     * خطای ۵۰۰ موقت است ⇒ نباید اشتراک را حذف کنیم.
     */
    public function test_a_server_error_keeps_the_subscription(): void
    {
        $subscription = $this->subscribe();

        Http::fake(['*' => Http::response('server error', 500)]);

        $this->app->make(PushSender::class)->broadcastAll(['title' => 'x']);

        $this->assertDatabaseHas('push_subscriptions', ['id' => $subscription->id]);
    }

    /**
     * ⚠️ رفتارِ عمدی: اشتراکی که کلید ندارد، **بدون هدر رمزنگاری** ارسال
     * می‌شود.
     *
     * طبق RFC 8291 §5.2 اگر گیرنده `p256dh` نداشته باشد، بدنه باید بدون
     * رمز و بدون `Content-Encoding` فرستاده شود (سرویس push متن را در
     * مسیر TLS خودش محافظت می‌کند).
     *
     * این تست رگرسیون یک باگ است: نسخهٔ اول `encryptWithoutKeys()` هدر
     * `Content-Encoding: aes128gcm` می‌فرستاد در حالی که بدنه رمزنشده بود.
     * سرویس push به هدر اعتماد می‌کرد، decrypt شکست می‌خورد، و پیام هرگز
     * نمی‌رسید — ولی ما status 201 می‌گرفتیم و فکر می‌کردیم موفق بوده.
     */
    public function test_a_subscription_without_keys_is_sent_without_encryption_headers(): void
    {
        $subscription = PushSubscription::query()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/broken',
            'p256dh' => null,
            'auth' => null,
            'user_id' => null,
        ]);

        Http::fake(['*' => Http::response('', 201)]);

        $result = $this->app->make(PushSender::class)->broadcastAll(['title' => 'سلام']);

        $this->assertSame(['sent' => 1, 'failed' => 0], $result);
        Http::assertSentCount(1);

        /** @var Request $request */
        $request = Http::recorded()->first()[0];

        $this->assertNull(
            $request->header('Content-Encoding')[0] ?? null,
            'بدون کلید نباید هدر رمزنگاری فرستاده شود، وگرنه سرویس push شکست می‌خورد'
        );
        $this->assertNull(
            $request->header('Encryption')[0] ?? null,
            'بدون کلید نباید هدر Encryption فرستاده شود'
        );

        // بدنه باید همان JSON خام باشد.
        $this->assertSame(
            ['title' => 'سلام'],
            json_decode($request->body(), true)
        );

        $this->assertNotNull($subscription->id);
    }

    /**
     * یک اشتراک خراب نباید کل batch را متوقف کند — بقیه باید ارسال شوند.
     */
    public function test_one_broken_subscription_does_not_stop_the_others(): void
    {
        // کلید ساختگی ⇒ `PushEncryptor` throw می‌کند ⇒ `send()` false.
        PushSubscription::query()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/badkeys',
            'p256dh' => 'not-a-valid-point',
            'auth' => 'also-not-valid',
            'user_id' => null,
        ]);

        $healthy = $this->subscribe(null, 'https://fcm.googleapis.com/fcm/send/healthy');

        Http::fake(['*' => Http::response('', 201)]);

        $result = $this->app->make(PushSender::class)->broadcastAll(['title' => 'x']);

        $this->assertSame(['sent' => 1, 'failed' => 1], $result);
        $this->assertDatabaseHas('push_subscriptions', ['id' => $healthy->id]);
    }
}