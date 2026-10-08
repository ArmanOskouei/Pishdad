<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\PluginNotification;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Outbox\Outbox;
use App\Services\Push\Ecdh;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * WF-M15 — کانال‌های ایمیل/تلگرام + خلاصهٔ روزانه.
 *
 * ⭐ چه چیزی این تست‌ها قفل می‌کنند:
 *  ۱. **گیتِ fail-soft تلگرام** — تا توکن ربات نباشد هیچ سطرِ صفی ساخته
 *     نمی‌شود (پیامی که هرگز نمی‌رسد ولی در آمار «تحویل» می‌شود).
 *  ۲. **تحویل واقعی از مسیر outbox** — نه فقط enqueue؛ Bot API با HTTP جعلی
 *     صدا زده می‌شود و chat_id درست می‌رود.
 *  ۳. **خلاصهٔ روزانه idempotent است** — اجرای دوباره در همان روز پیام دوم
 *     نمی‌سازد.
 */
class NotificationChannelsTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $extra = []): User
    {
        return User::query()->create($extra + [
            'name' => 'مدیر',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Pass!1234'),
            'role' => 'admin',
        ])->fresh();
    }

    /** @return array<string, string> */
    private function auth(User $u): array
    {
        // سنهٔ توکن‌محور در تست‌ها: گارد در همان اپِ تست کش می‌شود، پس پیش از
        // هر درخواست باید گاردها رها شوند تا کاربرِ همان توکن حل شود.
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken];
    }

    // ------------------------------------------------------------------
    // ⭐ تنظیماتِ کانال (chat_id + خلاصهٔ روزانه)
    // ------------------------------------------------------------------

    public function test_channel_settings_persist_and_come_back(): void
    {
        $u = $this->user();

        $this->putJson('/api/v1/admin/notification-preferences/settings', [
            'telegram_chat_id' => '123456789',
            'daily_digest' => true,
        ], $this->auth($u))
            ->assertOk()
            ->assertJsonPath('data.settings.telegram_chat_id', '123456789')
            ->assertJsonPath('data.settings.daily_digest', true);

        $this->assertSame('123456789', DB::table('users')->where('id', $u->id)->value('telegram_chat_id'));
        $this->assertTrue((bool) DB::table('users')->where('id', $u->id)->value('notification_daily_digest'));

        // خواندنِ دوباره هم همان را می‌دهد (UI از همین می‌سازد).
        $this->getJson('/api/v1/admin/notification-preferences', $this->auth($u))
            ->assertOk()
            ->assertJsonPath('data.settings.telegram_chat_id', '123456789')
            ->assertJsonPath('data.settings.daily_digest', true);
    }

    public function test_channel_settings_are_per_user(): void
    {
        $a = $this->user();
        $b = $this->user();

        $this->putJson('/api/v1/admin/notification-preferences/settings', [
            'telegram_chat_id' => '111',
            'daily_digest' => true,
        ], $this->auth($a))->assertOk();

        $this->getJson('/api/v1/admin/notification-preferences', $this->auth($b))
            ->assertOk()
            ->assertJsonPath('data.settings.telegram_chat_id', null)
            ->assertJsonPath('data.settings.daily_digest', false);
    }

    public function test_an_empty_chat_id_clears_it(): void
    {
        $u = $this->user(['telegram_chat_id' => '999']);

        $this->putJson('/api/v1/admin/notification-preferences/settings', [
            'telegram_chat_id' => '',
        ], $this->auth($u))->assertOk()->assertJsonPath('data.settings.telegram_chat_id', null);

        $this->assertNull(DB::table('users')->where('id', $u->id)->value('telegram_chat_id'));
    }

    public function test_an_invalid_chat_id_is_rejected(): void
    {
        $u = $this->user();

        $this->putJson('/api/v1/admin/notification-preferences/settings', [
            'telegram_chat_id' => 'bad id!',
        ], $this->auth($u))->assertStatus(422)->assertJsonValidationErrors('telegram_chat_id');
    }

    public function test_telegram_is_an_addressable_channel_in_the_matrix(): void
    {
        $u = $this->user();

        $this->putJson('/api/v1/admin/notification-preferences', [
            'group_key' => 'plugins', 'channel' => 'telegram', 'enabled' => true,
        ], $this->auth($u))->assertOk()->assertJsonPath('data.matrix.plugins.telegram', true);

        $this->assertTrue(NotificationDispatcher::allows($u, 'plugins.enabled', 'plugins', 'telegram'));
    }

    // ------------------------------------------------------------------
    // ⭐ گیتِ fail-soft تلگرام: تا توکن نباشد، سطرِ صف ساخته نمی‌شود
    // ------------------------------------------------------------------

    public function test_no_telegram_row_without_a_bot_token(): void
    {
        // E74 — چون نسخهٔ منتشرشده رباتِ عمومیِ پیش‌فرض دارد، «بدون توکن»
        // یعنی خطِ **خالیِ صریح** در `.env` (`TELEGRAM_BOT_TOKEN=`)، نه نبودِ
        // خط. `''` همان خاموشیِ عمدی است.
        config(['telegram.bot_token' => '']);
        Mail::fake();

        $u = $this->user(['telegram_chat_id' => '123']);

        NotificationDispatcher::send($u, 'system.maintenance', ['۲۰', '۲۱']);

        $channels = DB::table('notification_deliveries')->pluck('channel')->all();

        $this->assertNotContains('telegram', $channels, 'بدون توکن، تلگرام نباید در صف بنشیند.');
        $this->assertContains('email', $channels, 'ایمیل باید مستقل از تلگرام کار کند.');
    }

    public function test_no_telegram_row_without_a_chat_id(): void
    {
        config(['telegram.bot_token' => 'test-token']);
        Http::fake();
        Mail::fake();

        $u = $this->user(); // بدون chat_id

        NotificationDispatcher::send($u, 'system.maintenance', ['الف', 'ب']);

        $this->assertNotContains(
            'telegram',
            DB::table('notification_deliveries')->pluck('channel')->all(),
        );
    }

    // ------------------------------------------------------------------
    // ⭐ تحویل واقعی از outbox
    // ------------------------------------------------------------------

    public function test_a_telegram_notification_is_queued_and_delivered(): void
    {
        config(['telegram.bot_token' => 'test-token']);
        Http::fake();
        Mail::fake();

        $u = $this->user(['telegram_chat_id' => '555']);

        NotificationDispatcher::send($u, 'system.maintenance', ['۰۱:۰۰', '۰۳:۰۰']);

        $row = DB::table('notification_deliveries')->where('channel', 'telegram')->first();
        $this->assertNotNull($row, 'با توکن و chat_id، تلگرام باید در صف باشد.');
        $this->assertSame('555', $row->recipient);

        $this->artisan('outbox:drain')->assertSuccessful();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/bottest-token/sendMessage')
            && ($request['chat_id'] ?? null) === '555'
            && str_contains((string) ($request['text'] ?? ''), 'نگه‌داری'));

        $this->assertSame(
            'sent',
            DB::table('notification_deliveries')->where('channel', 'telegram')->value('status'),
        );
    }

    public function test_a_failed_telegram_response_marks_the_row_failed(): void
    {
        config(['telegram.bot_token' => 'test-token', 'outbox.max_attempts' => 1]);
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'bad chat'], 400)]);

        $u = $this->user(['telegram_chat_id' => '555']);

        NotificationDispatcher::send($u, 'system.maintenance', ['الف', 'ب']);

        $this->artisan('outbox:drain')->assertSuccessful();

        $this->assertSame(
            'failed',
            DB::table('notification_deliveries')->where('channel', 'telegram')->value('status'),
        );
    }

    // ------------------------------------------------------------------
    // ⭐⭐⭐ کانالِ push: هرگز به ایمیل نمی‌افتد
    // ------------------------------------------------------------------

    /**
     * کلیدهای معتبر می‌سازد تا مسیرِ رمزنگاری هم واقعاً اجرا شود.
     *
     * @return array{p256dh: string, auth: string}
     */
    private function browserKeys(): array
    {
        $pair = Ecdh::generateKeyPair();

        return ['p256dh' => $pair['public'], 'auth' => Ecdh::b64u(random_bytes(16))];
    }

    private function subscribe(User $user, string $endpoint): PushSubscription
    {
        $keys = $this->browserKeys();

        return PushSubscription::query()->create([
            'endpoint' => $endpoint,
            'p256dh' => $keys['p256dh'],
            'auth' => $keys['auth'],
            'user_id' => $user->id,
        ]);
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

    /**
     * ⭐ روشن‌کردنِ `push` برای یک گروه.
     *
     * لازم است چون `push` در `defaultChannels()` **هیچ** کلیدی نیست ⇒ پیش‌فرض
     * خاموش (کاربر باید صریح opt-in کند). بدون این ردیف، تست‌های زیر عملاً
     * «کاربرِ خاموش» را می‌سنجیدند و هرگز به شاخهٔ push نمی‌رسیدند.
     */
    private function allowPush(User $user, string $group, bool $enabled = true): void
    {
        DB::table('notification_preferences')->insert([
            'user_id' => $user->id,
            'group_key' => $group,
            'catalog_key' => null,
            'channel' => Outbox::CHANNEL_PUSH,
            'enabled' => $enabled,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * ⭐⭐⭐ باگِ اصلیِ `F5.3-b`: `push` به `Outbox::email()` می‌افتاد و
     * **شمارهٔ موبایل** ایمیل می‌شد — نه push می‌رفت، نه ایمیل به مقصد می‌رسید.
     *
     * ادعای این تست دقیقاً همین است: مقصدِ سطرِ push **شناسهٔ اشتراک** است
     * (قراردادِ `Outbox::push`)، و هیچ سطرِ ایمیلی با شمارهٔ موبایل ساخته
     * نمی‌شود.
     */
    public function test_the_push_channel_queues_the_subscription_not_the_phone_number(): void
    {
        Mail::fake();

        $u = $this->user(['phone' => '09120000000']);
        $a = $this->subscribe($u, 'https://fcm.googleapis.com/fcm/send/admin-a');
        $b = $this->subscribe($u, 'https://fcm.googleapis.com/fcm/send/admin-b');

        $this->allowPush($u, 'plugins');

        NotificationDispatcher::send($u, 'plugins.enabled', ['افزونهٔ نمونه']);

        $rows = $this->pushRows();

        $this->assertCount(2, $rows, 'هر اشتراکِ کاربر باید یک سطرِ مستقل بگیرد.');
        $this->assertSame(
            [(string) $a->id, (string) $b->id],
            array_map(fn ($r): string => (string) $r->recipient, $rows),
            'recipient باید شناسهٔ سطرِ اشتراک باشد، نه endpoint و نه شماره.',
        );

        $this->assertNotContains(
            '09120000000',
            DB::table('notification_deliveries')->pluck('recipient')->all(),
            'شمارهٔ موبایل نباید هیچ‌وقت recipientِ ایمیل باشد.',
        );

        $payload = json_decode((string) $rows[0]->payload, true);
        $this->assertSame('افزونه فعال شد', $payload['notification']['title'] ?? null);
        $this->assertStringContainsString('نمونه', (string) ($payload['notification']['body'] ?? ''));
    }

    /**
     * ⭐⭐ کلیدِ dedupe باید **به ازای هر اشتراک** یکتا باشد.
     *
     * قیدِ یکتا روی `dedupe_key` است. اگر شناسهٔ اشتراک داخل کلید نباشد، همهٔ
     * دستگاه‌ها در یک سطر جمع می‌شوند: enqueue اول موفق است و بقیه `null`
     * می‌گیرند — یعنی فقط یک دستگاه اعلان می‌گیرد و بقیه **هرگز**.
     */
    public function test_push_dedupe_keys_are_unique_per_subscription(): void
    {
        Mail::fake();

        $u = $this->user();
        $a = $this->subscribe($u, 'https://fcm.googleapis.com/fcm/send/dedupe-a');
        $b = $this->subscribe($u, 'https://fcm.googleapis.com/fcm/send/dedupe-b');

        $this->allowPush($u, 'plugins');

        NotificationDispatcher::send($u, 'plugins.enabled', ['افزونهٔ نمونه']);

        $keys = DB::table('notification_deliveries')
            ->where('channel', Outbox::CHANNEL_PUSH)
            ->pluck('dedupe_key')
            ->all();

        $this->assertCount(2, array_unique($keys));
        $this->assertStringContainsString('|sub:'.$a->id, (string) $keys[0]);
        $this->assertStringContainsString('|sub:'.$b->id, (string) $keys[1]);
    }

    /**
     * ⭐⭐ همان اعلان دوبار ⇒ همان تعداد سطر. این همان چیزی است که قیدِ یکتا
     * می‌خرد و بدون شناسهٔ اشتراک در کلید، خراب می‌شد.
     */
    public function test_the_same_push_notification_twice_is_still_two_rows(): void
    {
        Mail::fake();

        $u = $this->user();
        $this->subscribe($u, 'https://fcm.googleapis.com/fcm/send/twice-a');
        $this->subscribe($u, 'https://fcm.googleapis.com/fcm/send/twice-b');

        $this->allowPush($u, 'plugins');

        NotificationDispatcher::send($u, 'plugins.enabled', ['افزونهٔ نمونه']);
        NotificationDispatcher::send($u, 'plugins.enabled', ['افزونهٔ نمونه']);

        $this->assertCount(2, $this->pushRows());
    }

    /**
     * ⭐⭐ کاربرِ opt-in‌نشده: **هیچ** سطری نه push و نه ایمیلِ ساختگی.
     *
     * نبودِ اشتراک خطا نیست؛ fallback به ایمیل هم نباید باشد — `push` را
     * کاربر خواسته، پس جایگزینش یعنی اعلانی که نخواسته و هرگز نمی‌رسد.
     */
    public function test_push_without_a_subscription_creates_no_row_at_all(): void
    {
        Mail::fake();

        $u = $this->user(['phone' => '09120000000']);

        $this->allowPush($u, 'plugins');

        NotificationDispatcher::send($u, 'plugins.enabled', ['افزونهٔ نمونه']);

        $this->assertCount(0, $this->pushRows());

        $emails = DB::table('notification_deliveries')->where('channel', Outbox::CHANNEL_EMAIL)->get();

        $this->assertCount(1, $emails, 'فقط ایمیلِ واقعیِ کاربر.');
        $this->assertSame($u->email, $emails->first()->recipient);
    }

    /**
     * ⭐⭐⭐ ترجیحِ خاموشِ کاربر برای `push` باید واقعاً جلوی صف را بگیرد.
     *
     * `push` در `Catalog::CHANNELS` است ولی در `defaultChannels()` هیچ کلیدی
     * نیست ⇒ پیش‌فرض خاموش. اگر کاربر روشنش کند، صف باید پر شود.
     */
    public function test_push_respects_the_users_preference_in_both_directions(): void
    {
        Mail::fake();

        $off = $this->user();
        $this->subscribe($off, 'https://fcm.googleapis.com/fcm/send/pref-off');
        $this->allowPush($off, 'plugins', false);

        NotificationDispatcher::send($off, 'plugins.enabled', ['افزونهٔ نمونه']);

        $this->assertCount(0, $this->pushRows(), 'ترجیحِ خاموش باید برنده باشد.');

        $on = $this->user();
        $onPref = $this->subscribe($on, 'https://fcm.googleapis.com/fcm/send/pref-on');
        $this->allowPush($on, 'plugins');

        NotificationDispatcher::send($on, 'plugins.enabled', ['افزونهٔ نمونه']);

        $rows = $this->pushRows();

        $this->assertCount(1, $rows, 'ترجیحِ روشن باید صف را پر کند.');
        $this->assertSame((string) $onPref->id, (string) $rows[0]->recipient);
    }

    /**
     * ⭐⭐⭐ سطرِ push باید به همان `notifications` ردیف وصل باشد.
     *
     * `$notificationId` تنها چیزی است که «کلیک روی اعلانِ مرورگر» را به
     * صندوقِ پنل وصل می‌کند. اگر `null` بنشیند، push می‌رسد ولی هیچ‌کس
     * نمی‌تواند بفهمد مربوط به کدام سطر است.
     */
    public function test_push_rows_point_back_at_the_inbox_row(): void
    {
        Mail::fake();

        $u = $this->user();
        $this->subscribe($u, 'https://fcm.googleapis.com/fcm/send/linked');

        $this->allowPush($u, 'plugins');

        NotificationDispatcher::send($u, 'plugins.enabled', ['افزونهٔ نمونه']);

        $inboxId = DB::table('notifications')->value('id');
        $row = $this->pushRows()[0];

        $this->assertSame($inboxId, $row->notification_id);
    }

    /**
     * ⭐⭐ مسیرِ کامل: از `send()` تا تحویلِ واقعی از طریق `PushSender`.
     *
     * بدون این، ممکن است سطر درست ساخته شود ولی `drain` آن را از راهِ
     * اشتباهی بفرستد (یا اصلا نفرستد).
     */
    public function test_a_catalog_push_notification_is_delivered_through_the_push_service(): void
    {
        Mail::fake();
        Http::fake(['*' => Http::response('', 201)]);

        $u = $this->user();
        $this->subscribe($u, 'https://fcm.googleapis.com/fcm/send/delivered');

        $this->allowPush($u, 'plugins');

        NotificationDispatcher::send($u, 'plugins.enabled', ['افزونهٔ نمونه']);

        $this->artisan('outbox:drain')->assertSuccessful();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'fcm.googleapis.com'));

        $row = $this->pushRows()[0];

        $this->assertSame('sent', $row->status);
        $this->assertNotNull($row->sent_at);
    }

    // ------------------------------------------------------------------
    // ⭐ خلاصهٔ روزانه
    // ------------------------------------------------------------------

    private function notify(User $u, int $count = 2): void
    {
        for ($i = 0; $i < $count; $i++) {
            $u->notify(new PluginNotification('تیتر '.$i, 'متن '.$i, 'info', null, null));
        }
    }

    public function test_digest_sends_one_email_for_an_opted_in_user(): void
    {
        Mail::fake();

        $u = $this->user(['notification_daily_digest' => true]);
        $this->notify($u, 3);

        $this->artisan('notifications:digest')->assertSuccessful();

        $rows = DB::table('notification_deliveries')->where('channel', 'email')->get();
        $this->assertCount(1, $rows, 'سه اعلانِ خوانده‌نشده باید یک ایمیل بدهند، نه سه‌تا.');
        $this->assertStringContainsString('digest:'.$u->id.':', (string) $rows->first()->dedupe_key);

        $payload = json_decode((string) $rows->first()->payload, true);
        $this->assertStringContainsString('تیتر 0', (string) ($payload['body'] ?? ''));
    }

    public function test_digest_skips_users_who_did_not_opt_in(): void
    {
        Mail::fake();

        $u = $this->user(['notification_daily_digest' => false]);
        $this->notify($u);

        $this->artisan('notifications:digest')->assertSuccessful();

        $this->assertSame(0, DB::table('notification_deliveries')->where('channel', 'email')->count());
    }

    public function test_digest_skips_when_there_is_nothing_unread(): void
    {
        Mail::fake();

        $u = $this->user(['notification_daily_digest' => true]);

        $this->artisan('notifications:digest')->assertSuccessful();

        $this->assertSame(0, DB::table('notification_deliveries')->count());
    }

    public function test_digest_is_idempotent_within_the_same_day(): void
    {
        Mail::fake();

        $u = $this->user(['notification_daily_digest' => true]);
        $this->notify($u);

        $this->artisan('notifications:digest')->assertSuccessful();
        $this->artisan('notifications:digest')->assertSuccessful();

        $this->assertSame(
            1,
            DB::table('notification_deliveries')->where('channel', 'email')->count(),
            'اجرای دوبارهٔ command در همان روز نباید پیام دوم بسازد.',
        );
    }

    public function test_digest_also_uses_telegram_when_configured(): void
    {
        config(['telegram.bot_token' => 'test-token']);
        Http::fake();
        Mail::fake();

        $u = $this->user([
            'notification_daily_digest' => true,
            'telegram_chat_id' => '777',
        ]);
        $this->notify($u);

        $this->artisan('notifications:digest')->assertSuccessful();

        $channels = DB::table('notification_deliveries')->pluck('channel')->sort()->values()->all();
        $this->assertSame(['email', 'telegram'], $channels);

        $this->artisan('outbox:drain')->assertSuccessful();

        Http::assertSent(fn ($request): bool => ($request['chat_id'] ?? null) === '777'
            && str_contains((string) ($request['text'] ?? ''), 'خلاصهٔ روزانه'));
    }
}
