<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Outbox\Outbox;
use App\Services\Push\ContentPublishedNotifier;
use App\Services\Push\Ecdh;
use App\Services\Push\PushSender;
use App\Services\Push\VapidKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
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
 * ## ⭐ تغییرِ F5.3: اعلان از مسیرِ درخواستِ خارج شد
 *
 * `announce()` قبلاً `broadcastAll()` را **درونِ همان درخواستِ انتشار** صدا
 * می‌زد؛ یعنی با N مشترک، تا N درخواستِ HTTPِ سریال با timeout ده‌ثانیه‌ای
 * در مسیری که کاربر منتظرش بود. حالا فقط صف می‌سازد و `outbox:drain` تحویل
 * می‌دهد.
 *
 * چند تستِ این فایل **عمداً** عوض شدند و باید بدانی چرا: آن‌ها بعد از
 * `announce()` مستقیم `Http::assertSent*()` می‌زدند، یعنی رفتارِ همزمان را
 * تثبیت می‌کردند — و تثبیتِ رفتاری که باید حذف شود، خودش نوعی باگ است. نیتِ
 * هر تست حفظ شده و فقط راهِ اثبات عوض شده: `drain()` صدا زده می‌شود.
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

    private function subscribe(
        ?User $user = null,
        string $endpoint = self::ENDPOINT,
        ?string $locale = null,
        ?string $topic = null,
    ): PushSubscription {
        $keys = $this->validBrowserKeys();

        return PushSubscription::query()->create([
            'endpoint' => $endpoint,
            'p256dh' => $keys['p256dh'],
            'auth' => $keys['auth'],
            'user_id' => $user?->id,
            'locale' => $locale,
            'topic' => $topic,
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

    /**
     * تخلیهٔ صف — همان کاری که `outbox:drain` بیرون از اپ انجام می‌دهد.
     *
     * ‎⚠️ از نسخهٔ F5.3 این **لازم** است: `announce()` دیگر خودش HTTP
     * نمی‌زند، پس تست باید صریحاً بگوید «الان صف را تخلیه کن» وگرنه
     * دربارهٔ رفتاری تست می‌کرد که دیگر وجود ندارد.
     */
    private function drain(): void
    {
        $this->artisan('outbox:drain')->assertSuccessful();
    }

    /** @return list<object> سطرهای صفِ push که از فیلترِ کانال رد شده‌اند. */
    private function queuedPushRows(): array
    {
        return DB::table('notification_deliveries')
            ->where('channel', Outbox::CHANNEL_PUSH)
            ->orderBy('id')
            ->get()
            ->all();
    }

    // ── broadcastAll: زائر *و* مدیر ────────────────────────────────

    /**
     * 🔴 رگرسیون: اعلان باید به زائر بی‌نام **و** به مدیر برسد.
     *
     * ⚠️ تغییرِ عمدی (F5.3): این تست قبلاً بعد از `announce()` مستقیم
     * `Http::assertSentCount(2)` می‌زد، یعنی **همان رفتارِ همزمان** را تثبیت
     * می‌کرد — دقیقاً همان چیزی که باید حذف شود. نیتِ تست (هر دو گروه اعلان
     * می‌گیرند) حفظ شده، ولی از راهِ درست: صف ساخته می‌شود، بعد `drain`
     * تحویل می‌دهد.
     */
    public function test_announcement_reaches_both_visitors_and_admins(): void
    {
        $admin = User::factory()->create();

        $this->subscribe(null, 'https://fcm.googleapis.com/fcm/send/visitor');
        $this->subscribe($admin, 'https://fcm.googleapis.com/fcm/send/admin');

        Http::fake(['*' => Http::response('', 201)]);

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        $this->assertCount(2, $this->queuedPushRows());

        $this->drain();

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

        $this->drain();

        Http::assertSentCount(1);
    }

    /**
     * ⭐⭐ F5.3 — انتشار **فقط صف می‌سازد**، خودش هیچ HTTP بیرونی نمی‌زند.
     *
     * با N مشترک، نسخهٔ قبل تا N درخواستِ سریال با timeout ده‌ثانیه‌ای در
     * مسیرِ همان درخواستِ انتشار می‌زد. اگر این ادعا برقرار نبود، «صف دارد ولی
     * باز هم مسدود می‌کند» ممکن است — و آن دقیقاً همان باگی است که این تسک
     * برایش باز شد.
     */
    public function test_publishing_queues_rows_without_touching_the_push_service(): void
    {
        $this->subscribe(null, 'https://fcm.googleapis.com/fcm/send/a');
        $this->subscribe(null, 'https://fcm.googleapis.com/fcm/send/b');
        $this->subscribe(null, 'https://fcm.googleapis.com/fcm/send/c');

        Http::fake(['*' => Http::response('', 201)]);

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        Http::assertNothingSent();

        $rows = $this->queuedPushRows();

        $this->assertCount(3, $rows, 'هر مشترک باید سطرِ خودش را داشته باشد.');

        foreach ($rows as $row) {
            $this->assertSame(Outbox::STATUS_PENDING, $row->status);
            $this->assertSame(0, (int) $row->attempts);
        }
    }

    /**
     * ⭐⭐ کلیدِ dedupe باید شناسهٔ اشتراک را داشته باشد.
     *
     * قیدِ یکتا روی `dedupe_key` است؛ اگر شناسه نباشد، سه مشترک در **یک** سطر
     * جمع می‌شوند و enqueue اول `null` می‌گیرد — یعنی فقط یک نفر اعلان می‌گیرد و
     * بقیه بی‌سروصدا حذف می‌شوند.
     */
    public function test_each_subscription_gets_its_own_dedupe_key(): void
    {
        $this->subscribe(null, 'https://fcm.googleapis.com/fcm/send/a');
        $this->subscribe(null, 'https://fcm.googleapis.com/fcm/send/b');

        Http::fake(['*' => Http::response('', 201)]);

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        $keys = array_map(fn (object $row): string => (string) $row->dedupe_key, $this->queuedPushRows());

        $this->assertCount(2, $keys);
        $this->assertCount(2, array_unique($keys), 'کلیدهای dedupe باید یکتا بمانند.');

        $this->drain();

        Http::assertSentCount(2);
    }

    /**
     * ⭐⭐ `recipient` نباید endpoint باشد.
     *
     * ستون `varchar(200)` است و endpointهای FCM/WNS معمولاً بلندترند. ضمناً
     * endpoint عملاً کلیدِ ارسال push است و `notification_deliveries` جدولی است
     * که backup و dump می‌خوانند.
     */
    public function test_the_queue_recipient_is_the_subscription_id_not_the_endpoint(): void
    {
        $subscription = $this->subscribe(null, 'https://wns2-by3p.notify.windows.com/w/?token=abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP');

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        $row = $this->queuedPushRows()[0];

        $this->assertSame((string) $subscription->id, (string) $row->recipient);
        $this->assertStringNotContainsString('notify.windows.com', (string) $row->recipient);
        $this->assertLessThanOrEqual(200, mb_strlen((string) $row->recipient));
    }

    public function test_nothing_is_sent_when_there_are_no_subscribers(): void
    {
        Http::fake();

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        $this->assertSame([], $this->queuedPushRows());

        $this->drain();

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

        $this->assertSame([], $this->queuedPushRows());

        $this->drain();

        Http::assertNothingSent();
    }

    // ── شکست نباید انتشار را از کار بیندازد ─────────────────────────

    /**
     * ⚠️ خرابی سرویس push نباید باعث ۵۰۰ شدن صفحهٔ انتشار شود.
     *
     * ‎F5.3 — از آنجا که `announce()` دیگر HTTP نمی‌زند، این تست دیگر مسیرِ
     * شبکه را نمی‌سنجد؛ سنجهٔ درست این است که **ساختنِ صف** هم شکست را
     * بلعیده. پس سرویس را طوری خراب می‌کنیم که enqueue استثنا بدهد.
     */
    public function test_an_enqueue_failure_does_not_throw(): void
    {
        $this->subscribe();

        DB::statement('DROP TABLE notification_deliveries');

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        $this->assertTrue(true, 'انتشار نباید exception بدهد.');
    }

    /**
     * ⚠️ خرابی در لحظهٔ **تحویل** هم نباید سطر را `sent` کند.
     */
    public function test_a_transport_failure_leaves_the_row_retryable(): void
    {
        $this->subscribe();

        Http::fake(fn () => throw new ConnectionException('boom'));

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        $this->drain();

        $row = $this->queuedPushRows()[0];

        $this->assertNotSame(Outbox::STATUS_SENT, $row->status, 'سطر نباید «ارسال شد» بخورد.');
        $this->assertSame(Outbox::STATUS_PENDING, $row->status, 'تلاش اول باید قابل retry بماند.');
        $this->assertSame(1, (int) $row->attempts);
        $this->assertNotNull($row->last_error);
    }

    // ── قرارداد درخواست HTTP ───────────────────────────────────────

    public function test_request_carries_the_rfc_8291_and_vapid_headers(): void
    {
        $this->subscribe();

        Http::fake(['*' => Http::response('', 201)]);

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        $this->drain();

        /** @var Request $request */
        $request = Http::recorded()->first()[0];

        $this->assertSame('aes128gcm', $request->header('Content-Encoding')[0] ?? null);

        // ⚠️ برای `aes128gcm` نباید هدر `Encryption` فرستاده شود: salt خودش
        // ۱۶ بایت اولِ body است و آن هدر متعلق به قالبِ کهنِ `aes128` (پیش از
        // RFC 8188) بود که salt را بیرون از body می‌فرستاد.
        $this->assertEmpty(
            $request->header('Encryption'),
            'هدر Encryption برای aes128gcm نباید فرستاده شود؛ salt داخل body است.',
        );

        // ‎salt = ۱۶ بایت اولِ body.
        $this->assertSame(
            16,
            strlen(substr($request->body(), 0, 16)),
            'salt باید ۱۶ بایت اول body باشد.',
        );

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

        $this->drain();

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
        DB::table('settings')->updateOrInsert(
            ['group' => 'site', 'key' => 'global'],
            [
                'value' => json_encode(['email' => 'hello@example.org'], JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ],
        );

        $this->subscribe();

        Http::fake(['*' => Http::response('', 201)]);

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        $this->drain();

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

    // ── ⭐⭐ فیلترِ locale/topic (F5.3) ──────────────────────────────

    /**
     * 🔴 spam: قبلاً هر صفحهٔ منتشرشده‌ای به **همهٔ** مشترک‌ها می‌رفت.
     *
     * مشترکی که صریحاً `topic = 'blog'` دارد نباید اعلانِ یک صفحهٔ `single` را
     * بگیرد.
     */
    public function test_a_specific_topic_excludes_subscribers_of_other_topics(): void
    {
        $blogger = $this->subscribe(null, 'https://fcm.googleapis.com/fcm/send/blogger', null, 'blog');
        $everyone = $this->subscribe(null, 'https://fcm.googleapis.com/fcm/send/everyone');

        $page = $this->publishedPage();
        $page->forceFill(['meta' => ['page_type' => 'single']])->save();

        $this->app->make(ContentPublishedNotifier::class)->announce($page);

        $recipients = array_map(fn (object $row): string => (string) $row->recipient, $this->queuedPushRows());

        $this->assertNotContains((string) $blogger->id, $recipients, 'مشترکِ blog نباید صفحهٔ single را بگیرد.');
        $this->assertContains((string) $everyone->id, $recipients, 'مشترکِ بدون topic همه را می‌گیرد.');
    }

    /**
     * همین فیلتر در جهتِ مثبت هم باید کار کند، وگرنه `topic` فقط یک حذف‌کنندهٔ
     * بی‌معنی است.
     */
    public function test_a_matching_topic_receives_the_page(): void
    {
        $blogger = $this->subscribe(null, 'https://fcm.googleapis.com/fcm/send/blogger', null, 'blog');

        $page = $this->publishedPage();
        $page->forceFill(['meta' => ['page_type' => 'blog']])->save();

        $this->app->make(ContentPublishedNotifier::class)->announce($page);

        $recipients = array_map(fn (object $row): string => (string) $row->recipient, $this->queuedPushRows());

        $this->assertContains((string) $blogger->id, $recipients);
    }

    /**
     * ⭐⭐ صفحهٔ **بدون** `page_type` یعنی «نوعی اعلام نشده»، نه «از هر نوع».
     *
     * اگر حالتِ `null` را «همه» می‌گرفتیم، کسی که `topic = 'blog'` را انتخاب کرده
     * بود اعلانِ صفحهٔ بی‌نوع را هم می‌گرفت و فیلتر عملاً توخالی می‌شد. پس فقط
     * مشترکِ بدونِ محدودیت می‌گیرد.
     */
    public function test_a_page_without_a_type_only_reaches_topic_less_subscribers(): void
    {
        $blogger = $this->subscribe(null, 'https://fcm.googleapis.com/fcm/send/blogger', null, 'blog');
        $everyone = $this->subscribe(null, 'https://fcm.googleapis.com/fcm/send/everyone');

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        $recipients = array_map(fn (object $row): string => (string) $row->recipient, $this->queuedPushRows());

        $this->assertNotContains((string) $blogger->id, $recipients);
        $this->assertContains((string) $everyone->id, $recipients);
    }

    /**
     * 🔴 `locale` تنها چیزی است که فرانت واقعاً می‌فرستد، پس باید واقعاً فیلتر
     * کند: مشترکِ `en` نباید اعلانِ صفحهٔ `fa` را بگیرد.
     */
    public function test_locale_excludes_subscribers_of_another_locale(): void
    {
        $english = $this->subscribe(null, 'https://fcm.googleapis.com/fcm/send/en', 'en');
        $persian = $this->subscribe(null, 'https://fcm.googleapis.com/fcm/send/fa', 'fa');

        $page = $this->publishedPage();
        $page->forceFill(['locale' => 'fa'])->save();

        $this->app->make(ContentPublishedNotifier::class)->announce($page);

        $recipients = array_map(fn (object $row): string => (string) $row->recipient, $this->queuedPushRows());

        $this->assertContains((string) $persian->id, $recipients);
        $this->assertNotContains((string) $english->id, $recipients);
    }

    /**
     * ⭐⭐ ردیفِ **قدیمی** با `locale IS NULL` باید اعلان بگیرد.
     *
     * این مهم‌ترین ادعای فیلتر است: هیچ backfillی در این پروژه انجام نشده و
     * front-end فقط از نسخه‌های جدید `locale` می‌فرستد. اگر `NULL` را «هیچ‌کدام»
     * می‌گرفتیم، هر مشترکِ قدیمی برای همیشه بی‌صدا حذف می‌شد و هیچ نشانه‌ای هم
     * نبود که بفهمیم چرا.
     */
    public function test_a_subscription_without_a_locale_still_receives_announcements(): void
    {
        $legacy = $this->subscribe(null, 'https://fcm.googleapis.com/fcm/send/legacy');

        $page = $this->publishedPage();
        $page->forceFill(['locale' => 'fa'])->save();

        $this->app->make(ContentPublishedNotifier::class)->announce($page);

        $recipients = array_map(fn (object $row): string => (string) $row->recipient, $this->queuedPushRows());

        $this->assertContains((string) $legacy->id, $recipients);
    }
}
