<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageRevision;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Outbox\Outbox;
use App\Services\Push\ContentPublishedNotifier;
use App\Services\Push\Ecdh;
use App\Services\Push\ProviderAllowlist;
use App\Services\Push\PushSender;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * F5.3 — کانالِ `push` در outbox مشترک.
 *
 * ## ⭐ چرا این تست جداست و نه ادامهٔ `ContentPublishedNotifierTest`
 *
 * آن فایل دربارهٔ **کیفیتِ اعلان** است (هدر RFC 8291، امضا، حذفِ اشتراک مرده).
 * این فایل دربارهٔ **صف** است: ساختن سطر، dedupe، تحویل، retry. این دو محور
 * جدا عمداً جدا نگه داشته شده‌اند تا یک تغییر در صف، تست‌های رمزنگاری را
 * بی‌دلیل قرمز نکند.
 *
 * ## ⭐⭐ ادعای مرکزیِ کلِ تسک
 *
 * `announce()` **هیچ HTTP بیرونی نمی‌زند**. تحویل در `outbox:drain` است. بدون
 * این، با N مشترک انتشارِ صفحه تا N×۱۰ ثانیه معطل می‌ماند.
 *
 * @internal
 */
class PushOutboxChannelTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/token';

    protected function setUp(): void
    {
        parent::setUp();

        // `perm:pages.edit` از جدولِ دسترسی‌ها می‌خواند، پس نقش به‌تنهایی کافی
        // نیست و باید رابطه‌های نقش↔دسترسی هم ساخته شوند.
        $this->seed(RolesPermissionsSeeder::class);
    }

    /**
     * ناشری که واقعاً می‌تواند صفحه منتشر کند.
     *
     * نقش به‌تنهایی کافی نیست: `EnsurePermission` از Gate می‌پرسد و Gate به
     * رابطهٔ spatie نگاه می‌کند — پس باید هم seeder اجرا شود و هم دسترسی
     * گره بخورد، وگرنه مسیر ۴۰۳ می‌دهد و تست به `announce()` نمی‌رسد.
     */
    private function publisher(): User
    {
        $user = User::query()->create([
            'name' => 'ناشر تست',
            'email' => 'publisher'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);

        $user->givePermissionTo('pages.view', 'pages.edit');

        return $user->fresh();
    }

    /** @return array{p256dh: string, auth: string} */
    private function browserKeys(): array
    {
        $pair = Ecdh::generateKeyPair();

        return ['p256dh' => $pair['public'], 'auth' => Ecdh::b64u(random_bytes(16))];
    }

    private function subscribe(string $endpoint = self::ENDPOINT): PushSubscription
    {
        $keys = $this->browserKeys();

        return PushSubscription::query()->create([
            'endpoint' => $endpoint,
            'p256dh' => $keys['p256dh'],
            'auth' => $keys['auth'],
        ]);
    }

    private function publishedPage(string $slug = 'faq'): Page
    {
        $page = new Page([
            'slug' => $slug,
            'title' => 'پرسش‌های متداول',
            'status' => Page::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $page->save();

        return $page;
    }

    /** @return list<object> */
    private function pushRows(): array
    {
        return DB::table('notification_deliveries')
            ->where('channel', Outbox::CHANNEL_PUSH)
            ->orderBy('id')
            ->get()
            ->all();
    }

    // ------------------------------------------------------------------
    // ⛔⭐⭐⭐ Windows/WNS — fail-closed (نه «پشتیبانیِ نیمه‌کاره»)
    // ------------------------------------------------------------------

    /**
     * ⛔⭐⭐⭐ ادعای مرکزی: WNS نباید در allowlist باشد.
     *
     * WNS از VAPID استفاده نمی‌کند. احراز هویتش یک توکنِ OAuth است
     * (`Authorization: Bearer`) و آدرسِ تحویل هم نسبی (`/notify/?token=…`).
     * هیچ‌کدام در این بیلد پیاده نشده، پس راه‌درست «پشتیبانیِ ظاهری» نیست —
     * ردِ صریح است.
     *
     * رگرسیونِ همین تست: نسخهٔ قبل `notify.windows.com` را مجاز می‌دانست و یک
     * شاخهٔ ساختگی هم داشت ⇒ هر اشتراکِ Edge بی‌صدا ۴۰۱ می‌گرفت.
     */
    public function test_windows_wns_is_rejected_by_the_allowlist(): void
    {
        $this->assertFalse(ProviderAllowlist::allows('https://notify.windows.com/w/?token=abc'));
        $this->assertFalse(ProviderAllowlist::allows('https://wns2-by3p.notify.windows.com/w/?token=abc'));

        // مهندسیِ اجتماعی روی دامنهٔ مجاز هم باید رد شود.
        $this->assertFalse(ProviderAllowlist::allows('https://notify.windows.com.evil.io/w/?token=abc'));

        // ⭐ و دو ارائه‌دهندهٔ پشتیبانی‌شده باید سرجایشان بمانند.
        $this->assertTrue(ProviderAllowlist::allows('https://fcm.googleapis.com/fcm/send/x'));
        $this->assertTrue(ProviderAllowlist::allows('https://updates.push.services.mozilla.com/wpush/v2/x'));
    }

    /**
     * ⛔⭐⭐⭐ حتی اگر ردیفِ WNS از راهِ دور (seed/import/دادهٔ قدیمی) وارد پایگاه‌داده
     * شده باشد، نباید حتی یک درخواستِ HTTP بیرون زده شود.
     *
     * این نقطهٔ دومِ اعمالِ allowlist است (سینکِ ارسال، نه فقط مسیرِ ثبت)؛ بدون
     * آن، یک ردیفِ کهنه دوباره فعال می‌شد و همان ۴۰۱ِ بی‌صدا تکرار می‌گشت.
     */
    public function test_a_wns_subscription_dispatches_no_http(): void
    {
        Log::spy();

        Http::fake();

        $subscription = $this->subscribe('https://wns2-by3p.notify.windows.com/w/?token=abc');

        $sent = $this->app->make(PushSender::class)->send($subscription, ['title' => 'بی‌مقصد']);

        $this->assertFalse($sent);
        Http::assertNothingSent();

        // و رد شدن باید **دیده شود**، نه اینکه بی‌صدا از دست برود.
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
            return $message === 'push endpoint rejected'
                && ($context['host'] ?? null) === 'wns2-by3p.notify.windows.com';
        });
    }

    // ------------------------------------------------------------------
    // enqueue
    // ------------------------------------------------------------------

    /**
     * مهرِ انتشارِ واقعی.
     *
     * ‎`pages.published_revision_id` قیدِ خارجی دارد، پس عددِ ساختگی جواب
     * نمی‌دهد — و همین خودش یک ادعای مفید است: کلیدِ dedupe به **شناسهٔ
     * واقعیِ اسنپشات** گره خورده، نه به یک شمارندهٔ دلخواه.
     */
    private function stampPublish(Page $page): Page
    {
        $revision = PageRevision::query()->create([
            'page_id' => $page->id,
            'version' => (int) $page->revisions()->max('version') + 1,
            'blocks' => $page->blocks ?? [],
            'created_by' => null,
        ]);

        $page->forceFill(['published_revision_id' => $revision->id])->save();

        return $page;
    }

    /**
     * ⭐ قراردادِ کمکی: هر سطر یک مشترک، `recipient` شناسه، و payload زیر کلیدِ
     * `notification`.
     */
    public function test_pushing_lands_one_row_per_subscription(): void
    {
        $a = $this->subscribe('https://fcm.googleapis.com/fcm/send/a');
        $b = $this->subscribe('https://fcm.googleapis.com/fcm/send/b');

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        $rows = $this->pushRows();

        $this->assertCount(2, $rows);
        $this->assertSame([(string) $a->id, (string) $b->id], array_map(fn ($r) => (string) $r->recipient, $rows));

        foreach ($rows as $row) {
            $this->assertSame(Outbox::STATUS_PENDING, $row->status);
            $this->assertNull($row->notification_id);

            $payload = json_decode((string) $row->payload, true);

            $this->assertArrayHasKey('notification', $payload);
            $this->assertSame('محتوای تازه در پیشداد', $payload['notification']['title'] ?? null);
            $this->assertSame('/faq', $payload['notification']['url'] ?? null);
        }
    }

    /**
     * ⭐⭐ دو انتشارِ متفاوت باید **دو** سطر بدهند، نه یکی.
     *
     * اگر مهرِ انتشار از کلید حذف شود، کلید فقط `page:{id}` می‌شود و انتشارِ
     * دوم برای همیشه dedupe می‌شود — یعنی بعد از هر بازنشری، اعلان نمی‌رود.
     */
    public function test_a_second_publish_of_the_same_page_creates_a_new_row(): void
    {
        $this->subscribe();

        $page = $this->publishedPage();

        $notifier = $this->app->make(ContentPublishedNotifier::class);

        $this->stampPublish($page);
        $notifier->announce($page);

        $this->stampPublish($page);
        $notifier->announce($page);

        $this->assertCount(2, $this->pushRows());
    }

    /**
     * ⭐ همان انتشار دوبار ⇒ یک سطر. این همان چیزی است که قیدِ یکتا می‌خرد.
     */
    public function test_the_same_publish_twice_is_one_row(): void
    {
        $this->subscribe();

        $page = $this->publishedPage();
        $this->stampPublish($page);

        $notifier = $this->app->make(ContentPublishedNotifier::class);
        $notifier->announce($page);
        $notifier->announce($page);

        $this->assertCount(1, $this->pushRows());
    }

    /**
     * ⭐ گیتِ شناسهٔ نامعتبر: ساختن سطر برای مقصدی که وجود ندارد یعنی سطری که
     * در هر tick دوباره ادعا و دوباره شکست می‌خورد.
     */
    public function test_a_non_positive_subscription_id_never_creates_a_row(): void
    {
        $this->assertNull(Outbox::push(0, 'push:zero', ['title' => 'x']));
        $this->assertNull(Outbox::push(-3, 'push:negative', ['title' => 'x']));

        $this->assertSame(0, DB::table('notification_deliveries')->count());
    }

    // ------------------------------------------------------------------
    // ⭐ deliver
    // ------------------------------------------------------------------

    /**
     * ⭐⭐ مسیرِ `CHANNEL_PUSH` در `send()` واقعاً از `PushSender` عبور می‌کند.
     */
    public function test_the_drain_actually_delivers_through_the_push_service(): void
    {
        $this->subscribe();

        Http::fake(['*' => Http::response('', 201)]);

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        $this->artisan('outbox:drain')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(self::ENDPOINT, Http::recorded()->first()[0]->url());

        $row = $this->pushRows()[0];

        $this->assertSame(Outbox::STATUS_SENT, $row->status);
        $this->assertNotNull($row->sent_at);
        $this->assertNull($row->claim_token);
        $this->assertNull($row->last_error);
    }

    /**
     * ⭐⭐ بدنه باید **رمزشده** باشد، نه JSON خام — یعنی همان قراردادی که
     * `PushSender` با `PushEncryptor` می‌سازد.
     */
    public function test_the_delivered_body_is_encrypted(): void
    {
        $this->subscribe();

        Http::fake(['*' => Http::response('', 201)]);

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());
        $this->artisan('outbox:drain')->assertSuccessful();

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

        // ‎salt = ۱۶ بایت اولِ body. گیرنده salt را از همین‌جا برمی‌دارد، پس
        // بدنهٔ کوتاه‌تر از ۱۶ بایت یعنی ساختارِ بدنه خراب است.
        $this->assertSame(
            16,
            strlen(substr($request->body(), 0, 16)),
            'salt باید ۱۶ بایت اول body باشد.',
        );

        $this->assertStringNotContainsString('پیشداد', $request->body());
    }

    /**
     * ⭐⭐⭐ مهم‌ترین ادعای این فایل: شکستِ push باید سطر را **قابل retry** نگه
     * دارد، نه اینکه بی‌صدا `sent` شود.
     *
     * `PushSender` به‌جای throw کردن `bool` می‌دهد (تا یک مشترکِ خراب کل batch را
     * متوقف نکند). اگر `Outbox` آن `false` را نادیده می‌گرفت، داشبورد می‌گفت
     * «ارسال شد» و هیچ‌کس نمی‌فهمید اعلان نرسیده.
     */
    public function test_a_failed_push_keeps_the_row_for_retry(): void
    {
        $this->subscribe();

        Http::fake(['*' => Http::response('upstream is unhappy', 500)]);

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());
        $this->artisan('outbox:drain')->assertSuccessful();

        $row = $this->pushRows()[0];

        $this->assertSame(Outbox::STATUS_PENDING, $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertNotNull($row->last_error, 'علت باید ثبت شود تا دیده شود.');
        $this->assertNull($row->sent_at);
    }

    /**
     * ⭐ پاسخ‌های ناموفقِ متعدد باید به `failed` برسند، نه اینکه بی‌نهایت retry
     * شوند (همان سقفِ بقیهٔ کانال‌ها).
     */
    public function test_a_push_never_succeeding_eventually_fails_visibly(): void
    {
        config(['outbox.max_attempts' => 2]);

        $this->subscribe();

        Http::fake(['*' => Http::response('nope', 500)]);

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        $this->artisan('outbox:drain')->assertSuccessful();

        DB::table('notification_deliveries')
            ->where('channel', Outbox::CHANNEL_PUSH)
            ->update(['available_at' => now()->subSecond()]);

        $this->artisan('outbox:drain')->assertSuccessful();

        $row = $this->pushRows()[0];

        $this->assertSame(Outbox::STATUS_FAILED, $row->status);
        $this->assertNotNull($row->failed_at);
    }

    /**
     * ⭐⭐ اشتراکی که بین enqueue و tick پاک شده (مثلاً ۴۱۰ در یک batch دیگر) باید
     * **خطا** بدهد، نه `sent`.
     *
     * سکوت در صفِ تحویل بدترین شکلِ سکوت است: ردیف «ارسال شد» برای اعلانی که
     * هرگز نرسید.
     */
    public function test_a_vanished_subscription_fails_loudly(): void
    {
        $subscription = $this->subscribe();

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        PushSubscription::query()->whereKey($subscription->id)->delete();

        $this->artisan('outbox:drain')->assertSuccessful();

        $row = $this->pushRows()[0];

        $this->assertNotSame(Outbox::STATUS_SENT, $row->status);
        $this->assertNull($row->sent_at);
        $this->assertNotNull($row->last_error);
    }

    /**
     * ⭐ یک سطرِ خراب نباید کل tick را متوقف کند — بقیه باید تحویل شوند.
     */
    public function test_one_failing_subscription_does_not_stop_the_others(): void
    {
        /**
         * ⚠️ شناسهٔ ردیفِ خراب **قبل** از drain گرفته می‌شود.
         *
         * `PushSender` حالا اشتراکی که کلیدهایش ساختاراً غلط است را خودش
         * حذف می‌کند، پس `min('id')` بعد از drain دیگر اشتراکِ خراب را نشان
         * نمی‌دهد و تست اشتباهی سبز می‌شد.
         */
        $broken = PushSubscription::query()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/badkeys',
            'p256dh' => 'not-a-valid-point',
            'auth' => 'also-not-valid',
        ]);

        $healthy = $this->subscribe('https://fcm.googleapis.com/fcm/send/healthy');

        Http::fake(['*' => Http::response('', 201)]);

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());
        $this->artisan('outbox:drain')->assertSuccessful();

        $rows = $this->pushRows();
        $byRecipient = [];

        foreach ($rows as $row) {
            $byRecipient[(string) $row->recipient] = (string) $row->status;
        }

        $this->assertSame(Outbox::STATUS_SENT, $byRecipient[(string) $healthy->id] ?? null);
        $this->assertSame(Outbox::STATUS_PENDING, $byRecipient[(string) $broken->id] ?? null);
    }

    // ------------------------------------------------------------------
    // ⭐⭐ fail-soft در مسیرِ انتشار
    // ------------------------------------------------------------------

    /**
     * ⭐⭐⭐ خرابیِ ساختنِ صف نباید از `announce()` بیرون بزند.
     *
     * سنجهٔ درست «شبکه» نیست (F5.3 اعلان را از مسیرِ درخواست بیرون برد)، بلکه
     * خرابیِ خودِ `enqueue` است: جدولِ صف را می‌اندازیم تا `insertOrIgnore`
     * استثنا بدهد و می‌بینیم `announce()` آن را می‌بلعد.
     *
     * ‎⚠️ این تنها جایی است که **عمداً** جدول خراب می‌شود و به همین دلیل
     * دنبالش هیچ query دیگری نمی‌زنیم: روی Postgres یک دستورِ ناموفق کلِ
     * تراکنشِ باز را abort می‌کند (`25P02`)، و `RefreshDatabase` کلِ تست را در
     * یک تراکنش می‌بندد. همین موضوع دقیقاً همان چیزی است که PHPDocِ
     * `announce()` هشدار می‌دهد: فراخواننده‌های واقعیِ این متد هیچ‌کدام تراکنش
     * نمی‌بندند، پس آنجا فقط همان statement می‌میرد.
     */
    public function test_an_enqueue_failure_does_not_escape_announce(): void
    {
        $this->subscribe();

        DB::statement('DROP TABLE notification_deliveries');

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        $this->assertTrue(true, 'انتشار نباید exception بدهد.');
    }

    /**
     * ⭐⭐⭐ مسیرِ واقعیِ انتشار، از HTTP تا صف.
     *
     * `PageController::publish` → `PagePublisher::publish()` → `announce()`. این
     * تست ثابت می‌کند سیم‌کشی واقعاً وصل است و باز هم **هیچ HTTP بیرونی** در
     * مسیرِ درخواست زده نمی‌شود — یعنی همان چیزی که کلِ تسک برایش باز شد.
     */
    public function test_the_real_publish_endpoint_queues_without_sending_inline(): void
    {
        $this->subscribe();

        Http::fake(['*' => Http::response('', 201)]);

        $owner = $this->publisher();

        $page = $this->publishedPage();
        $page->forceFill(['user_id' => $owner->id, 'status' => Page::STATUS_DRAFT])->save();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/publish")
            ->assertOk();

        /**
         * ⚠️ فقط درخواست‌های سرویس‌های push مهم‌اند: `PagePublisher` علاوه بر
         * اعلان، درخواستِ revalidate به فرانت هم می‌زند و آن یکی بیرونِ دامنهٔ
         * این تست است.
         */
        $pushRequests = Http::recorded()->filter(
            fn (array $pair): bool => str_contains($pair[0]->url(), 'fcm.googleapis.com')
        );

        $this->assertCount(0, $pushRequests, 'انتشار نباید به سرویس push دست بزند.');

        $this->assertCount(1, $this->pushRows());
    }

    // ------------------------------------------------------------------
    // ⭐⭐⭐ بهداشت: هیچ‌کس نباید بی‌صدا حذف شود — و هیچ‌کس نباید بی‌صدا بماند
    // ------------------------------------------------------------------

    /** یک اشتراک با کلیدِ عمومیِ سالم ولی `auth` به‌طولِ غلط. */
    private function subscriptionWithBadAuth(string $suffix): PushSubscription
    {
        return PushSubscription::query()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$suffix,
            'p256dh' => $this->browserKeys()['p256dh'],
            // RFC 8291 دقیقاً ۱۶ بایت می‌خواهد؛ ۸ بایت یعنی این ردیف هرگز رمز نمی‌شود.
            'auth' => Ecdh::b64u(random_bytes(8)),
        ]);
    }

    /** یک اشتراک با `p256dh`ای که اصلاً نقطهٔ P-256 نیست. */
    private function subscriptionWithBadPoint(string $suffix): PushSubscription
    {
        return PushSubscription::query()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$suffix,
            'p256dh' => 'bm90LWEtcG9pbnQ',
            'auth' => Ecdh::b64u(random_bytes(16)),
        ]);
    }

    /**
     * ⭐⭐⭐ ادعای اصلی: کلیدِ ساختاراً غلط **حذف** می‌شود، نه اینکه تا ابد retry شود.
     *
     * قبلاً چنین ردیفی هر tick دوباره رمزنگاری می‌شد، سه بار retry می‌خورد و
     * `failed` می‌ماند — و در **هر انتشارِ بعدی** همین کار تکرار می‌شد. یعنی
     * هزینه و نویزِ دائمی برای چیزی که هرگز کار نمی‌کرد.
     *
     * اینجا `auth` طولِ غلط دارد؛ حالتِ نقطهٔ خراب در تستِ بعدی است.
     */
    public function test_a_structurally_invalid_subscription_is_pruned(): void
    {
        $broken = $this->subscriptionWithBadAuth('badauth');

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());
        $this->artisan('outbox:drain')->assertSuccessful();

        // کلیدِ ساختاراً غلط با retry درست نمی‌شود ⇒ باید حذف شود.
        $this->assertDatabaseMissing('push_subscriptions', ['id' => $broken->id]);

        // سطرِ صف هم نباید «ارسال شد» بخورد.
        $row = $this->pushRows()[0];
        $this->assertNotSame(Outbox::STATUS_SENT, $row->status);
        $this->assertNull($row->sent_at);
    }

    /**
     * ⭐ همان حکم برای نقطهٔ عمومیِ خراب — چون `Ecdh::decodePoint` آن را رد
     * می‌کند و این هم هیچ‌وقت با retry درست نمی‌شود.
     */
    public function test_a_subscription_with_an_undecodable_point_is_pruned(): void
    {
        $broken = $this->subscriptionWithBadPoint('badpoint');

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());
        $this->artisan('outbox:drain')->assertSuccessful();

        $this->assertDatabaseMissing('push_subscriptions', ['id' => $broken->id]);
    }

    /**
     * ⭐⭐⭐ ضدِ حالتِ افراطی: خطای **انتقالی** هرگز نباید اشتراک را حذف کند.
     *
     * ۵xx و timeout موقتی‌اند و اشتراک کاملاً سالم است. اگر این هم پاک می‌شد،
     * یک قطعیِ چنددقیقه‌ایِ سرویس push کلِ پایگاه‌دادهٔ مشترک‌ها را خالی
     * می‌کرد — بدترین حالتِ ممکن.
     */
    public function test_a_transient_failure_never_prunes_a_valid_subscription(): void
    {
        $healthy = $this->subscribe();

        Http::fake(['*' => Http::response('upstream is unhappy', 500)]);

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());
        $this->artisan('outbox:drain')->assertSuccessful();

        // خطای انتقالی موقتی است ⇒ retry باید اشتراک را نگه دارد.
        $this->assertDatabaseHas('push_subscriptions', ['id' => $healthy->id]);

        // و سطرِ صف همچنان retry-able می‌ماند.
        $this->assertSame(Outbox::STATUS_PENDING, $this->pushRows()[0]->status);
    }

    /**
     * ⭐⭐⭐ لاگِ جعلی در لاگِ ما: بدنهٔ خطا از **طرفِ مقابل** است.
     *
     * اگر `\r\n` خام وارد لاگ شود، هر خوانندهٔ لاگ یک سطرِ کاملاً ساختگی
     * می‌بیند (log forging) و ابزارهای پایش هم قاطی می‌شوند.
     */
    public function test_a_newline_in_the_upstream_body_cannot_forge_a_log_line(): void
    {
        Log::spy();

        $this->subscribe();

        Http::fake([
            '*' => Http::response(
                "upstream said no\r\n2026-01-01 ERROR push forged by a third party",
                400,
            ),
        ]);

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());
        $this->artisan('outbox:drain')->assertSuccessful();

        $snippet = null;

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use (&$snippet): bool {
            if ($message !== 'push failed') {
                return false;
            }

            $snippet = (string) ($context['body'] ?? '');

            return true;
        });

        $this->assertIsString($snippet, 'لاگِ «push failed» باید بدنه را داشته باشد.');
        $this->assertStringNotContainsString("\n", $snippet);
        $this->assertStringNotContainsString("\r", $snippet);
        $this->assertStringContainsString('forged by a third party', $snippet);
        $this->assertLessThanOrEqual(200, strlen($snippet));
    }

    /**
     * ⭐⭐⭐ سقفِ ۵۰۰ وقتی **واقعاً پر شود** باید warning بدهد.
     *
     * نسخهٔ قبل فقط یک عدد در لاگِ info می‌نوشت؛ برای بازدیدکننده‌ای که صد
     * نفر بعد از او در فهرست‌اند هیچ تفاوتی نمی‌کرد.
     */
    public function test_a_full_enqueue_window_warns_with_the_total(): void
    {
        $this->bulkSubscriptions(501);

        Log::spy();

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        $this->assertCount(
            500,
            $this->pushRows(),
            'فقط پنجرهٔ سقف باید صف شود — نه یکی بیشتر.',
        );

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
            return $message === 'push announcement truncated at the subscriber cap'
                && $context['selected'] === 500
                && $context['limit'] === 500
                // ⭐ عددِ کل، نه اندازهٔ پنجره: وگرنه هیچ اطلاعاتی نمی‌داد.
                && $context['total'] === 501;
        });
    }

    /**
     * ⭐⭐⭐ و زیرِ سقف هیچ هشداری نباید باشد — وگرنه warning بی‌معنا می‌شود
     * و کسی دیگر آن را نمی‌بیند.
     */
    public function test_a_publish_below_the_cap_never_warns(): void
    {
        $this->subscribe();
        $this->subscribe('https://fcm.googleapis.com/fcm/send/second');

        Log::spy();

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        $this->assertCount(2, $this->pushRows());

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * ⭐⭐ و پرچمِ `capped` در خطِ عادی هم درست گزارش می‌شود، تا هر دو کنارِ
     * هم خوانده شوند و بشود یک‌جا همه‌ی رخداد را دید.
     */
    public function test_the_cap_flag_is_false_when_nothing_is_dropped(): void
    {
        $this->subscribe();

        Log::spy();

        $this->app->make(ContentPublishedNotifier::class)->announce($this->publishedPage());

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            return $message === 'push announcement queued'
                && $context['subscribers'] === 1
                && $context['capped'] === false;
        });
    }

    /**
     * ۵۰۱ ردیف در **یک** `INSERT`.
     *
     * ساختنِ ردیف‌ها با `create()` یعنی ۵۰۱ جفت‌کلیدِ ECDH — که برای یک تستِ
     * بهداشتِ لاگ، هزینهٔ چند دقیقه‌ای دارد. کلیدها `null` می‌مانند چون این
     * تست فقط دربارهٔ *انتخابِ* مشترک‌هاست، نه رمزنگاری.
     */
    private function bulkSubscriptions(int $count): void
    {
        $now = now();
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/bulk-'.$i,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('push_subscriptions')->insert($rows);
    }
}
