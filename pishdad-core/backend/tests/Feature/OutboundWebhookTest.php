<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Outbox\Outbox;
use App\Services\Pages\PagePublisher;
use App\Services\Settings\CachedSettings;
use App\Services\Webhooks\WebhookDispatcher;
use App\Services\Webhooks\WebhookSettings;
use App\Services\Webhooks\WebhookSigner;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WF-L2 — وب‌هوک خروجی: امضا، fail-soft، و اینکه راز هرگز بیرون نمی‌آید.
 *
 * ⭐⭐ پنج ادعا که این فایل قفل می‌کند:
 *  ۱. **امضا** — هدر `X-PISHDAD-Signature` دقیقاً برابرِ
 *     `hash_hmac('sha256', "{ts}.{raw_body}", secret)` است. ادعا از راهِ
 *     بازسازیِ مستقلِ امضا در تست ثابت می‌شود، نه با صدا زدنِ همان متدِ
 *     پیاده‌سازی (وگرنه یک باگ در `signedString` هر دو طرف را با هم تأیید
 *     می‌کرد).
 *  ۲. **fail-soft** — پاسخِ غیر ۲xx و استثنای شبکه هیچ‌کدام نباید انتشار صفحه
 *     یا ثبت تیکت را بشکنند؛ فقط سطرِ صف retry می‌خورد.
 *  ۳. **وقتی خاموش است، هیچ تحویلی نیست** — نه حتی یک سطرِ صف که بعداً
 *     `failed` شود.
 *  ۴. **راز لو نمی‌رود** — `show` فقط بولین می‌دهد، تنها جایی که متنِ راز را
 *     برمی‌گرداند `POST …/secret` است، و راز در جدول رمزنگاری‌شده می‌نشیند.
 *  ۵. **دو مسیرِ ساختِ تیکت** هر دو وب‌هوک می‌فرستند (ناظر، نه کنترلر).
 */
class OutboundWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://hooks.example.ir/cms';

    private const SECRET = 'test-secret-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function admin(array $perms = ['settings.view', 'settings.edit']): User
    {
        $user = User::query()->create([
            'name' => 'مدیر وب‌هوک',
            'email' => 'hook'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
        $user->givePermissionTo(...$perms);

        return $user->fresh();
    }

    /** نصب را «پیکربندی‌شده» می‌کند بدون دور زدنِ رمزنگاریِ راز. */
    private function configure(bool $active = true, string $url = self::URL, ?string $secret = self::SECRET): void
    {
        Setting::set(WebhookSettings::GROUP, WebhookSettings::KEY, [
            'url' => $url,
            'secret_encrypted' => $secret === null ? null : Crypt::encryptString($secret),
            'active' => $active,
        ]);
        CachedSettings::forget(WebhookSettings::GROUP, WebhookSettings::KEY);
    }

    private function page(array $overrides = []): Page
    {
        return Page::query()->create($overrides + [
            'title' => 'صفحهٔ آزمایشی',
            'slug' => 'azmayeshi',
            'locale' => 'fa',
            'status' => Page::STATUS_DRAFT,
            'blocks' => [],
            'meta' => [],
        ]);
    }

    private function ticket(): Ticket
    {
        return Ticket::query()->create([
            'subject' => 'تیکت آزمایشی',
            'source' => 'contact',
            'contact_name' => 'مریم',
            'contact_email' => 'maryam@example.ir',
        ]);
    }

    /** @return list<object> سطرهای صفِ وب‌هوک. */
    private function queued(): array
    {
        return DB::table('notification_deliveries')
            ->where('channel', Outbox::CHANNEL_WEBHOOK)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * فقط درخواست‌های **خودِ وب‌هوک**، با اندیسِ صفر-پیوسته.
     *
     * ⭐⭐ چرا `values()` لازم است: `Http::recorded($callback)` یک `Collection`
     * برمی‌گرداند که `filter` رویش زده شده، و `filter` **کلیدهای اصلی را
     * نگه می‌دارد**. یعنی بعد از فیلتر، اندیس‌ها لزوماً از صفر شروع نمی‌شوند:
     * انتشارِ صفحه خودش اول یک POST به `revalidate.url` می‌فرستد و در ضبط
     * جای `0` را می‌گیرد، پس وب‌هوک با کلید `1` می‌نشیند. `assertCount` این را
     * نمی‌بیند (روی شمارش کار می‌کند) ولی `$mine[0]` خطای
     * «Undefined array key 0» می‌دهد — یعنی تست به‌جایِ وب‌هوک، هیچ چیز را
     * نمی‌سنجید و فقط با شانس رد می‌شد.
     *
     * @return Collection<int, array{0: ClientRequest, 1: mixed}>
     */
    private function webhookRequests(): Collection
    {
        return Http::recorded(fn (ClientRequest $r) => $r->url() === self::URL)->values();
    }

    // ------------------------------------------------------------------
    // ⭐ ۱ — تنظیمات: ذخیره، رمزنگاری، و «راز هرگز برنمی‌گردد»
    // ------------------------------------------------------------------

    public function test_the_secret_is_stored_encrypted_and_the_endpoint_never_returns_it(): void
    {
        $auth = $this->actingAs($this->admin(), 'sanctum');

        $this->configure(active: false);

        $res = $auth->getJson('/api/v1/admin/settings/webhook');

        $res->assertOk()
            ->assertJsonPath('data.url', self::URL)
            ->assertJsonPath('data.secret_set', true)
            ->assertJsonPath('data.active', false);

        // ⭐ هیچ مسیری به متنِ راز نباید وجود داشته باشد — نه به اسمِ خودش، نه
        // به شکلِ رمزنگاری‌شده.
        $body = $res->getContent();
        $this->assertStringNotContainsString(self::SECRET, $body);
        $this->assertArrayNotHasKey('secret', $res->json('data'));
        $this->assertArrayNotHasKey('secret_encrypted', $res->json('data'));

        $stored = Setting::get(WebhookSettings::GROUP, WebhookSettings::KEY);
        $this->assertNotSame(self::SECRET, $stored['secret_encrypted']);
        $this->assertSame(self::SECRET, Crypt::decryptString($stored['secret_encrypted']));
    }

    public function test_updating_never_overwrites_the_secret_with_an_empty_value(): void
    {
        $this->configure(active: false);
        $auth = $this->actingAs($this->admin(), 'sanctum');

        // فرم مقدار را نمی‌بیند ⇒ خالی یعنی «بدون تغییر» (قراردادِ CDN/SMTP).
        $auth->putJson('/api/v1/admin/settings/webhook', [
            'url' => 'https://n8n.example.ir/webhook/cms',
            'secret' => '',
            'active' => true,
        ])->assertOk()->assertJsonPath('data.secret_set', true);

        $this->assertSame(self::SECRET, WebhookSettings::secret());
        $this->assertSame('https://n8n.example.ir/webhook/cms', WebhookSettings::url());
    }

    public function test_clearing_the_secret_is_explicit_and_disables_delivery(): void
    {
        $this->configure();
        $auth = $this->actingAs($this->admin(), 'sanctum');

        $auth->putJson('/api/v1/admin/settings/webhook', [
            'clear_secret' => true,
        ])->assertOk()->assertJsonPath('data.secret_set', false);

        $this->assertNull(WebhookSettings::secret());
        $this->assertFalse(WebhookSettings::configured(), 'بدون راز، نصب «پیکربندی‌شده» نیست.');
    }

    public function test_rotating_returns_the_secret_exactly_once(): void
    {
        $this->configure();
        $auth = $this->actingAs($this->admin(), 'sanctum');

        $res = $auth->postJson('/api/v1/admin/settings/webhook/secret');
        $res->assertOk();

        $revealed = $res->json('data.secret');
        $this->assertIsString($revealed);
        $this->assertNotSame(self::SECRET, $revealed);
        $this->assertGreaterThanOrEqual(32, strlen((string) $revealed));

        // و بعد از آن، هر مسیرِ دیگر فقط «دارد/ندارد» می‌گوید.
        $this->assertSame($revealed, WebhookSettings::secret());
        $follow = $auth->getJson('/api/v1/admin/settings/webhook');
        $follow->assertOk();
        $this->assertStringNotContainsString((string) $revealed, $follow->getContent());
    }

    public function test_the_settings_endpoint_is_permission_gated(): void
    {
        $this->actingAs($this->admin([]), 'sanctum')
            ->getJson('/api/v1/admin/settings/webhook')
            ->assertForbidden();

        $this->actingAs($this->admin(['settings.view']), 'sanctum')
            ->putJson('/api/v1/admin/settings/webhook', ['active' => true])
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // ⭐ ۲ — رویدادها: صف می‌شوند و مسیرهای گوناگون را می‌گیرند
    // ------------------------------------------------------------------

    public function test_publishing_a_page_queues_a_delivery(): void
    {
        Http::fake();

        $this->configure();

        $page = $this->page();
        app(PagePublisher::class)->publish($page, null, 'انتشار');

        $rows = $this->queued();
        $this->assertCount(1, $rows);

        $payload = json_decode((string) $rows[0]->payload, true);
        $this->assertSame(WebhookDispatcher::EVENT_PAGE_PUBLISHED, $payload['event']);
        $this->assertSame($page->id, $payload['data']['id']);
        $this->assertSame('azmayeshi', $payload['data']['slug']);
        $this->assertSame('/azmayeshi', $payload['data']['path']);
        $this->assertSame(self::URL, $rows[0]->recipient);

        // ⭐ راز هرگز در `payload` سطرِ صف نیست (جدولی که backup و لاگ می‌خوانند).
        $this->assertStringNotContainsString(self::SECRET, (string) $rows[0]->payload);
    }

    public function test_unpublishing_a_page_queues_a_different_event(): void
    {
        Http::fake();

        $this->configure();

        $page = $this->page(['status' => Page::STATUS_PUBLISHED]);
        $page->forceFill(['status' => Page::STATUS_DRAFT])->save();

        $rows = $this->queued();
        $this->assertCount(1, $rows);
        $this->assertSame(
            WebhookDispatcher::EVENT_PAGE_UNPUBLISHED,
            json_decode((string) $rows[0]->payload, true)['event'],
        );
    }

    /**
     * ⭐ صفحهٔ خانه در روت `/` سرو می‌شود نه `/home` — همان قاعدهٔ
     * `ContentPublishedNotifier::pathFor`.
     */
    public function test_the_home_page_payload_points_at_the_root(): void
    {
        Http::fake();

        $this->configure();

        app(PagePublisher::class)->publish($this->page(['slug' => 'home']), null);

        $rows = $this->queued();
        $this->assertSame('/', json_decode((string) $rows[0]->payload, true)['data']['path']);
    }

    /** هر سه مسیرِ ساختِ تیکت از `created` رد می‌شوند. */
    public function test_a_new_ticket_queues_a_delivery(): void
    {
        Http::fake();

        $this->configure();

        $ticket = $this->ticket();

        $rows = $this->queued();
        $this->assertCount(1, $rows);

        $payload = json_decode((string) $rows[0]->payload, true);
        $this->assertSame(WebhookDispatcher::EVENT_TICKET_CREATED, $payload['event']);
        $this->assertSame($ticket->id, $payload['data']['id']);
        $this->assertSame('maryam@example.ir', $payload['data']['contact_email']);
    }

    /**
     * ⭐ یک صفحه که اصلاً وضعیتش عوض نشده، رویداد نیست. بدون این گیت هر ذخیرهٔ
     * عادی یک وب‌هوک می‌فرستاد (همان دامی که `ContentPublishedNotifier` دربارهٔ
     * `booted()` هشدار می‌دهد).
     */
    public function test_saving_a_page_without_a_status_change_queues_nothing(): void
    {
        Http::fake();

        $this->configure();

        $page = $this->page();
        $page->forceFill(['title' => 'عنوان تازه'])->save();

        $this->assertCount(0, $this->queued());
    }

    // ------------------------------------------------------------------
    // ⭐ ۳ — وقتی خاموش است، هیچ تحویلی نیست
    // ------------------------------------------------------------------

    public function test_nothing_is_delivered_when_the_webhook_is_disabled(): void
    {
        Http::fake();

        $this->configure(active: false);

        app(PagePublisher::class)->publish($this->page(), null);
        $this->ticket();

        $this->assertCount(0, $this->queued());
        $this->assertCount(0, $this->webhookRequests());
    }

    public function test_nothing_is_delivered_without_a_secret(): void
    {
        Http::fake();

        $this->configure(secret: null);

        app(PagePublisher::class)->publish($this->page(), null);

        $this->assertCount(0, $this->queued());
        $this->assertCount(0, $this->webhookRequests());
    }

    public function test_nothing_is_delivered_without_a_url(): void
    {
        Http::fake();

        $this->configure(url: '');

        $this->ticket();

        $this->assertCount(0, $this->queued());
    }

    // ------------------------------------------------------------------
    // ⭐⭐ ۴ — امضا: دقیقاً همان چیزی که مستند شده
    // ------------------------------------------------------------------

    public function test_the_delivery_carries_the_documented_hmac_signature(): void
    {
        Http::fake();

        $this->configure();

        app(PagePublisher::class)->publish($this->page(), null);

        $this->artisan('outbox:drain')->assertSuccessful();

        /**
         * ⭐ فقط درخواست‌های **خودِ وب‌هوک** ادعا می‌شوند.
         *
         * `assertSent` روی همهٔ درخواست‌های ضبط‌شده callback را می‌زند، و
         * انتشارِ صفحه خودش هم یک POST به `revalidate.url` می‌فرستد. بدون این
         * فیلتر، assertهای داخلِ callback روی درخواستِ بیگانه اجرا می‌شدند و
         * تست به‌جایِ وب‌هوک، آن را می‌سنجید. `webhookRequests()` فیلتر را با
         * `values()` همراه می‌کند تا `$mine[0]` واقعاً همان وب‌هوک باشد.
         */
        $mine = $this->webhookRequests();

        $this->assertCount(1, $mine, 'انتشارِ صفحه باید دقیقاً یک وب‌هوک بفرستد.');

        /** @var ClientRequest $request جفتِ [0] از رکوردِ وب‌هوک. */
        $request = $mine[0][0];

        $this->assertSame('POST', $request->method());

        $timestamp = (string) ($request->header(WebhookSigner::HEADER_TIMESTAMP)[0] ?? '');
        $signature = (string) ($request->header(WebhookSigner::HEADER_SIGNATURE)[0] ?? '');

        $this->assertMatchesRegularExpression('/^\d{10}$/', $timestamp);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signature);

        // ⭐⭐ بازسازیِ **مستقل**ِ امضا: اینجا هیچ متدی از پیاده‌سازی صدا زده
        // نمی‌شود. اگر `signedString` نقطه را جا بیندازد یا بدنه را عوض کند،
        // همین یک assert شکست می‌خورد.
        $this->assertSame(
            hash_hmac('sha256', $timestamp.'.'.$request->body(), self::SECRET),
            $signature,
        );

        // سرآیندهای مستندسازی‌شده هم باید حاضر باشند.
        $this->assertSame(
            WebhookDispatcher::EVENT_PAGE_PUBLISHED,
            (string) ($request->header(WebhookSigner::HEADER_EVENT)[0] ?? ''),
        );
        $this->assertNotSame('', (string) ($request->header(WebhookSigner::HEADER_DELIVERY)[0] ?? ''));

        // بدنه باید همان بایت‌هایی باشد که امضا شدند، با شکلِ مستندشده.
        $decoded = json_decode($request->body(), true);
        $this->assertSame(['event', 'occurred_at', 'data'], array_keys($decoded));
    }

    public function test_the_test_endpoint_also_signs_and_answers_synchronously(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->configure(active: false);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/settings/webhook/test')
            ->assertOk()
            ->assertJsonPath('data.sent', true);

        $mine = $this->webhookRequests();

        $this->assertCount(1, $mine);

        /** @var ClientRequest $request جفتِ [0] از رکوردِ وب‌هوک. */
        $request = $mine[0][0];
        $timestamp = (string) ($request->header(WebhookSigner::HEADER_TIMESTAMP)[0] ?? '');

        $this->assertSame(
            hash_hmac('sha256', $timestamp.'.'.$request->body(), self::SECRET),
            (string) ($request->header(WebhookSigner::HEADER_SIGNATURE)[0] ?? ''),
        );

        $this->assertSame('webhook.test', json_decode($request->body(), true)['event']);
    }

    // ------------------------------------------------------------------
    // ⭐⭐ ۵ — fail-soft: شکستِ وب‌هوک هرگز کارِ اصلی را نمی‌شکند
    // ------------------------------------------------------------------

    public function test_a_failing_webhook_never_breaks_publishing_a_page(): void
    {
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        $this->configure();

        $page = $this->page();

        $published = app(PagePublisher::class)->publish($page, null);

        // کارِ اصلی کامل انجام شده: وضعیت، نسخه، تاریخِ انتشار، لاگِ revalidate.
        $this->assertSame(Page::STATUS_PUBLISHED, $published->status);
        $this->assertNotNull($published->published_at);
        $this->assertNotNull($published->published_revision_id);

        $rows = $this->queued();
        $this->assertCount(1, $rows);

        $this->artisan('outbox:drain')->assertSuccessful();

        $row = DB::table('notification_deliveries')->find($rows[0]->id);
        $this->assertNotSame('sent', $row->status, 'پاسخِ ۵۰۰ نباید «ارسال شد» بنشیند.');
        $this->assertSame(1, (int) $row->attempts);
        $this->assertStringContainsString('HTTP 500', (string) $row->last_error);
        $this->assertGreaterThan(now()->timestamp, $row->available_at, 'باید backoff بخورد.');
    }

    public function test_a_throwing_transport_never_breaks_publishing_a_page(): void
    {
        Http::fake(fn () => throw new ConnectionException('اتصال برقرار نشد'));

        $this->configure();

        $published = app(PagePublisher::class)->publish($this->page(), null);

        $this->assertSame(Page::STATUS_PUBLISHED, $published->status);

        $rows = $this->queued();
        $this->assertCount(1, $rows);

        $this->artisan('outbox:drain')->assertSuccessful();

        $row = DB::table('notification_deliveries')->find($rows[0]->id);
        $this->assertNotSame('sent', $row->status);
        $this->assertStringContainsString('اتصال برقرار نشد', (string) $row->last_error);
    }

    public function test_a_failing_webhook_never_breaks_creating_a_ticket(): void
    {
        Http::fake(['*' => Http::response('nope', 503)]);

        $this->configure();

        $ticket = $this->ticket();

        $this->assertNotNull($ticket->id);
        $this->assertSame('تیکت آزمایشی', Ticket::query()->find($ticket->id)->subject);
    }

    /** پاسخِ ناموفقِ تست باید ۴۲۲ و `sent=false` باشد، نه یک موفقیتِ دروغ. */
    public function test_the_test_endpoint_reports_failure_honestly(): void
    {
        Http::fake(['*' => Http::response('gateway down', 502)]);

        $this->configure();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/settings/webhook/test')
            ->assertStatus(422)
            ->assertJsonPath('data.sent', false);
    }

    public function test_the_test_endpoint_refuses_without_a_url_or_a_secret(): void
    {
        $this->configure(url: '', secret: null);

        $auth = $this->actingAs($this->admin(), 'sanctum');

        $auth->postJson('/api/v1/admin/settings/webhook/test')
            ->assertStatus(422)
            ->assertJsonPath('data.sent', false);

        $this->configure(url: self::URL, secret: null);
        $auth->postJson('/api/v1/admin/settings/webhook/test')
            ->assertStatus(422)
            ->assertJsonPath('data.sent', false);
    }

    /**
     * ⭐⭐ سخت‌ترین ادعای fail-soft: خودِ `enqueue` هم شکست بخورد.
     *
     * جدولِ صف را می‌اندازیم تا `Outbox::enqueue()` استثنا بدهد. اگر استثنا از
     * `publish()` بیرون بزند، `Page::updated` آن را بالا می‌برد و انتشارِ صفحه
     * می‌شکند — که دقیقاً همان چیزی است که این ادعا می‌بندد.
     *
     * ⚠️ عمداً `PagePublisher` صدا زده نمی‌شود: روی Postgres یک `INSERT`
     * ناموفق کلِ تراکنشِ تست را abort می‌کند (`25P02`) و `fresh()` بعدی
     * استثنا می‌دهد — یعنی تست به‌جایِ آنکه شکستِ وب‌هوک را بسنجد، خودِ DB را
     * می‌سنجید. مسیرِ ناظر جداگانه (بدونِ DB بعد از خطا) تست شده است.
     */
    public function test_the_dispatcher_swallows_a_broken_outbox(): void
    {
        Http::fake();

        $this->configure();

        DB::statement('DROP TABLE notification_deliveries');

        WebhookDispatcher::publish(WebhookDispatcher::EVENT_PAGE_PUBLISHED, ['id' => 1], 'probe');

        // فقط رسیدن به این خط اثبات است: یعنی هیچ استثنایی بیرون نیامد.
        $this->assertTrue(true, 'publish() نباید استثنا بدهد.');
    }

    /**
     * گیتِ طول: نشانیِ بلندتر از ستونِ `recipient` نباید سطرِ صف بسازد، چون روی
     * Postgres آن خطا تراکنشِ فراخواننده را abort می‌کند.
     */
    public function test_an_over_long_url_is_skipped_instead_of_failing_the_insert(): void
    {
        Http::fake();

        $long = 'https://hooks.example.ir/'.'x'.str_repeat('a', WebhookSettings::URL_MAX);
        $this->configure(url: $long);

        $this->assertGreaterThan(WebhookSettings::URL_MAX, mb_strlen($long));

        WebhookDispatcher::publish(WebhookDispatcher::EVENT_PAGE_PUBLISHED, ['id' => 1], 'probe');

        $this->assertCount(0, $this->queued());
    }
}
