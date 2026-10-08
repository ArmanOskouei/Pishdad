<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\VapidKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * تست API اشتراک Web Push.
 *
 * ## چرا این تست‌ها
 *
 * مسیر ثبت **عمومی** است — بازدیدکننده حساب ندارد. این عمدی است، ولی
 * یعنی هر کسی می‌تواند به آن درخواست بدهد. پس تست‌ها روی سه چیز تمرکز
 * دارند:
 *
 * ۱. داده‌ها درست اعتبارسنجی و ذخیره می‌شوند.
/// ۲. `endpoint` **هرگز** در پاسخ برنمی‌گردد.
/// ۳. مسیرهای حساس پشت احراز هویت‌اند.
 */
class PushSubscriptionApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123XYZ';

    public function test_public_key_endpoint_is_public(): void
    {
        $this->getJson('/api/v1/push/public-key')
            ->assertOk()
            ->assertJsonStructure(['supported', 'vapid_public_key']);
    }

    public function test_a_visitor_can_subscribe_without_authentication(): void
    {
        $response = $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => self::ENDPOINT,
            'keys' => [
                'p256dh' => str_repeat('A', 87),
                'auth' => str_repeat('B', 22),
            ],
        ]);

        $response->assertCreated()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => self::ENDPOINT,
            'user_id' => null,
        ]);
    }

    /**
     * ⚠️ ثبت دوباره نباید ردیف جدید بسازد — endpoint یکتای گرانی است.
     */
    public function test_subscribing_twice_updates_the_same_row(): void
    {
        $payload = [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
        ];

        $this->postJson('/api/v1/push/subscribe', $payload)->assertCreated();
        $this->postJson('/api/v1/push/subscribe', $payload)->assertOk();

        $this->assertSame(1, PushSubscription::query()->count());
    }

    /**
     * 🔴 امنیتی: `endpoint` تنها چیزی است که به ما اجازه می‌دهد push بفرستیم،
     * پس هرگز نباید در پاسخ برگردد.
     */
    public function test_endpoint_is_never_echoed_back(): void
    {
        $response = $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
        ]);

        $body = $response->getContent();

        $this->assertIsString($body);
        $this->assertStringNotContainsString('fcm.googleapis.com', $body);
        $this->assertStringNotContainsString('abc123XYZ', $body);
    }

    public function test_endpoint_must_be_a_valid_https_url(): void
    {
        // endpoint دلخواه ⇒ مسیر SSRF باز می‌شد
        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => 'http://127.0.0.1:8080/admin',
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
        ])->assertJsonValidationErrors('endpoint');

        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => 'not-a-url',
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
        ])->assertJsonValidationErrors('endpoint');
    }

    /**
     * 🔴 SSRF: مسیر ثبت عمومی است و `PushSender` بعداً با POST به همان
     * endpoint می‌رود. پس `https` به‌تنهایی کافی نیست — باید دامنهٔ
     * ارائه‌دهندهٔ واقعی Push باشد، وگرنه سرور ما برای مهاجم به هر
     * میزبان داخلی/بیرونی درخواست می‌سازد.
     */
    public function test_endpoint_host_must_be_a_known_push_provider(): void
    {
        $rejected = [
            // میزبان داخلی، حتی با HTTPS
            'https://127.0.0.1/admin',
            'https://localhost/admin',
            'https://10.0.0.5/notify',
            // دامنهٔ عمومیِ ناشناس
            'https://evil.example.com/collect',
            // مهندسیِ اجتماعی روی دامنهٔ مجاز: `push.services.mozilla.com.evil.io`
            'https://push.services.mozilla.com.evil.io/x',
            // بدون نقطهٔ جداکننده نباید قبول شود: `evil-fcm.googleapis.com`
            'https://evil-fcm.googleapis.com/x',
        ];

        foreach ($rejected as $endpoint) {
            $this->postJson('/api/v1/push/subscribe', [
                'endpoint' => $endpoint,
                'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
            ])->assertJsonValidationErrors('endpoint');
        }

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    /**
     * دامنه‌های واقعی و زیردامنه‌هایشان باید پذیرفته شوند.
     *
     * ⚠️ WNS/Edge **دیگر** در این فهرست نیست و عمداً هم نیست: احراز هویتش
     * توکنِ OAuth است، نه هدرِ `vapid`، و آدرسش نسبی (`/notify/?token=…`).
     * پذیرشش یعنی ثبتِ موفق و بعد ۴۰۱ روی هر اعلان ⇒ `fail-closed` در
     * `ProviderAllowlist` (که همین مسیرِ ثبت و هم sinkِ ارسال را می‌بندد).
     */
    public function test_known_provider_subdomains_are_accepted(): void
    {
        $accepted = [
            'https://fcm.googleapis.com/fcm/send/token',
            'https://updates.push.services.mozilla.com/wpush/v2/token',
        ];

        foreach ($accepted as $endpoint) {
            $this->postJson('/api/v1/push/subscribe', [
                'endpoint' => $endpoint,
                'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
            ])->assertCreated();
        }

        $this->assertDatabaseCount('push_subscriptions', count($accepted));
    }

    /**
     * 🔴 WNS باید رد شود، نه بی‌صدا ثبت شود — دقیقاً همان دلیلی که در
     * `ProviderAllowlist::PROVIDER_HOST_SUFFIXES` نوشته شده.
     */
    public function test_windows_wns_is_rejected_because_it_cannot_do_vapid(): void
    {
        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => 'https://wns2-by3p.notify.windows.com/w/?token=x',
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
        ])->assertJsonValidationErrors('endpoint');

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    /**
     * 🔴🔴 ثبت با endpointِ کاربرِ دیگر باید **رد** شود — نه اینکه بی‌صدا ردیفِ او را
     * بازنویسی کند.
     *
     * نسخهٔ قبل `user_id` را نگه می‌داشت ولی `p256dh`/`auth`/`locale` را عوض
     * می‌کرد. یعنی هر کسی که endpoint را می‌دید می‌توانست کلیدِ رمزنگاریِ دستگاهِ
     * قربانی را مال خودش کند، و از آن به بعد **همهٔ** push‌های آن کاربر با ۴۰۱ رد
     * می‌شدند (رمزنگاریِ RFC 8291 با کلیدِ غریبه بسته می‌شود): یک درخواست =
     * ازکارافتادنِ کاملِ اعلان‌های یک کاربر، بی‌آنکه چیزی در سمتِ ما قرمز شود.
     *
     * رد شدن با `422` است (نه سکوت) تا فرانت که روی `!ok` مقدار `false` برمی‌گرداند
     * به کاربر بگوید فعال‌سازی انجام نشد — به‌جای اینکه تیک بزند و بعد اعلان‌ها
     * هرگز نرسند.
     */
    public function test_a_different_user_cannot_overwrite_an_owned_subscription(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $subscription = PushSubscription::query()->create([
            'endpoint' => self::ENDPOINT,
            'p256dh' => str_repeat('A', 87),
            'auth' => str_repeat('B', 22),
            'locale' => 'en',
            'user_id' => $owner->id,
        ]);

        $this->actingAs($other)
            ->postJson('/api/v1/push/subscribe', [
                'endpoint' => self::ENDPOINT,
                'keys' => ['p256dh' => str_repeat('C', 87), 'auth' => str_repeat('D', 22)],
                'locale' => 'fa',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('endpoint');

        $subscription->refresh();

        // نه کلیدها، نه زبان، نه مالکیت: رد شدن یعنی *هیچ* نوشتنی.
        $this->assertSame(str_repeat('A', 87), $subscription->p256dh);
        $this->assertSame(str_repeat('B', 22), $subscription->auth);
        $this->assertSame('en', $subscription->locale);
        $this->assertSame(
            (int) $owner->id,
            (int) $subscription->user_id,
            'user_id هرگز نباید از یک کاربر به کاربر دیگر تغییر کند.'
        );
    }

    /**
     * 🔴 مهمان هم «کاربرِ دیگر» است: `null` با `id` فرق دارد، پس رد می‌شود.
     *
     * این fail-closed عمدی است — مسیرِ عمومی بی‌احراز هویت است، پس «مهمان»
     * نمی‌تواند ادعا کند همان دستگاهِ کاربرِ واردشده است.
     */
    public function test_a_guest_cannot_overwrite_an_owned_subscription(): void
    {
        $owner = User::factory()->create();

        $subscription = PushSubscription::query()->create([
            'endpoint' => self::ENDPOINT,
            'p256dh' => str_repeat('A', 87),
            'auth' => str_repeat('B', 22),
            'user_id' => $owner->id,
        ]);

        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => str_repeat('C', 87), 'auth' => str_repeat('D', 22)],
        ])->assertStatus(422)->assertJsonValidationErrors('endpoint');

        $this->assertSame(str_repeat('A', 87), $subscription->fresh()->p256dh);
    }

    /**
     * ⭐ طرفِ سالمِ همان قاعده: **مالک** می‌تواند کلیدِ دستگاهش را بچرخاند.
     *
     * بدون این تست، «رد کردن» می‌توانست یک باگِ بدتر باشد: مسیرِ
     * `push/subscribe/auth` (مدیر) با مسیرِ عمومیِ یک endpoint یکی می‌شد و هر
     * بارِ بعدی رد می‌شد ⇒ مدیر عملاً هرگز دوباره opt-in نمی‌کرد.
     */
    public function test_the_owner_can_rotate_the_keys_on_their_own_subscription(): void
    {
        $owner = User::factory()->create();

        $subscription = PushSubscription::query()->create([
            'endpoint' => self::ENDPOINT,
            'p256dh' => str_repeat('A', 87),
            'auth' => str_repeat('B', 22),
            'user_id' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->postJson('/api/v1/push/subscribe', [
                'endpoint' => self::ENDPOINT,
                'keys' => ['p256dh' => str_repeat('C', 87), 'auth' => str_repeat('D', 22)],
            ])
            ->assertOk();

        $subscription->refresh();

        $this->assertSame(str_repeat('C', 87), $subscription->p256dh);
        $this->assertSame(str_repeat('D', 22), $subscription->auth);
        $this->assertSame((int) $owner->id, (int) $subscription->user_id);
    }

    /**
     * ⭐ ردیفِ بی‌صاحب (`user_id IS NULL`) برای هر فراخوانِ مهمان قابل به‌روزرسانی
     * است — وگرنه تعویض کلیدِ دستگاهِ یک بازدیدکنندهٔ عادی ۴۲۲ می‌گرفت.
     *
     * ⚠️ ولی بی‌صاحب می‌ماند: `user_id` فقط در ساختِ تازه نوشته می‌شود، پس کسی
     * با دانستنِ endpoint نمی‌تواند ردیف را «به نامِ خودش» سپرد.
     */
    public function test_a_guest_can_still_update_its_own_unowned_subscription(): void
    {
        $subscription = PushSubscription::query()->create([
            'endpoint' => self::ENDPOINT,
            'p256dh' => str_repeat('A', 87),
            'auth' => str_repeat('B', 22),
            'user_id' => null,
        ]);

        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => str_repeat('C', 87), 'auth' => str_repeat('D', 22)],
        ])->assertOk();

        $subscription->refresh();

        $this->assertSame(str_repeat('C', 87), $subscription->p256dh);
        $this->assertNull($subscription->user_id);
    }

    /**
     * مسیر احراز هویت‌شده باید `user_id` را واقعاً پر کند.
     *
     * ‎⚠️ این همان باگی است که کارت Push مدیر را بی‌اثر می‌کرد: مسیر
     * عمومی middleware ندارد، پس `$request->user()` همیشه `null` بود و
     * اشتراکِ مدیر بی‌نام ذخیره می‌شد.
     */
    public function test_authenticated_subscribe_endpoint_attaches_the_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/push/subscribe/auth', [
                'endpoint' => self::ENDPOINT,
                'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => self::ENDPOINT,
            'user_id' => $user->id,
        ]);
    }

    public function test_authenticated_subscribe_requires_authentication(): void
    {
        $this->postJson('/api/v1/push/subscribe/auth', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
        ])->assertUnauthorized();

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    /**
     * کلید عمومی باید در **اولین** درخواست هم موجود باشد.
     *
     * نسخهٔ اول `exists() ? get() : null` برمی‌گرداند، یعنی روی نصبِ
     * تازه کلید `null` بود و فرانت اصلاً `pushManager.subscribe()` را
     * صدا نمی‌زد — یعنی کل قابلیت با کاربرِ اول شروع نمی‌شد.
     */
    public function test_public_key_is_available_on_the_very_first_request(): void
    {
        $this->assertFalse(VapidKeys::exists());

        $key = $this->getJson('/api/v1/push/public-key')->assertOk()->json('vapid_public_key');

        $this->assertIsString($key);
        $this->assertNotSame('', $key);
    }

    public function test_keys_are_required(): void
    {
        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => self::ENDPOINT,
        ])->assertJsonValidationErrors(['keys.p256dh', 'keys.auth']);
    }

    /**
     * لغو با endpoint انجام می‌شود چون endpoint همان کلیدِ یکتای دسترسی است.
     */
    public function test_unsubscribe_removes_the_row(): void
    {
        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
        ])->assertCreated();

        $this->postJson('/api/v1/push/unsubscribe', ['endpoint' => self::ENDPOINT])
            ->assertOk();

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    /**
     * پاسخ لغو همیشه `ok` است، حتی اگر چیزی نبود.
     *
     * گفتن اینکه «این endpoint وجود ندارد» به یک مهاجم اجازه می‌دهد
     * endpointهای موجود را کشف کند.
     */
    public function test_unsubscribe_of_unknown_endpoint_still_succeeds(): void
    {
        $this->postJson('/api/v1/push/unsubscribe', ['endpoint' => 'https://example.com/x'])
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    /**
     * 🔴🔴 لغو نباید ردیفِ کاربرِ دیگر را پاک کند، حتی با endpointِ درست.
     *
     * مسیر عمومی است و فقط `throttle` دارد، پس endpoint یک رازِ کامل نیست
     * (لاگ مرورگر، دستگاهِ مشترک، پشتیبانیِ سایت…). نسخهٔ قبل `delete()` را
     * بدونِ محدودیتِ مالکیت می‌زد، پس یک endpointِ لو رفته = امکان خاموش کردنِ
     * اعلان‌های هر کسی در سامانه.
     */
    public function test_unsubscribe_does_not_delete_another_users_subscription(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $subscription = PushSubscription::query()->create([
            'endpoint' => self::ENDPOINT,
            'p256dh' => 'a',
            'auth' => 'b',
            'user_id' => $owner->id,
        ]);

        $this->actingAs($attacker)
            ->postJson('/api/v1/push/unsubscribe', ['endpoint' => self::ENDPOINT])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('push_subscriptions', ['id' => $subscription->id]);
    }

    /**
     * 🔴 مهمان هم نمی‌تواند ردیفِ یک کاربرِ واردشده را پاک کند.
     */
    public function test_a_guest_cannot_delete_a_users_subscription(): void
    {
        $owner = User::factory()->create();

        $subscription = PushSubscription::query()->create([
            'endpoint' => self::ENDPOINT,
            'p256dh' => 'a',
            'auth' => 'b',
            'user_id' => $owner->id,
        ]);

        $this->postJson('/api/v1/push/unsubscribe', ['endpoint' => self::ENDPOINT])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('push_subscriptions', ['id' => $subscription->id]);
    }

    /**
     * ⭐ طرفِ سالمِ قاعده: مالک (و ردیفِ بی‌صاحب) واقعاً لغو می‌شود.
     */
    public function test_unsubscribe_deletes_only_what_the_caller_owns(): void
    {
        $owner = User::factory()->create();

        $mine = PushSubscription::query()->create([
            'endpoint' => self::ENDPOINT,
            'p256dh' => 'a',
            'auth' => 'b',
            'user_id' => $owner->id,
        ]);

        $guestOwned = PushSubscription::query()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/guest',
            'p256dh' => 'a',
            'auth' => 'b',
            'user_id' => null,
        ]);

        $this->actingAs($owner)
            ->postJson('/api/v1/push/unsubscribe', ['endpoint' => self::ENDPOINT])
            ->assertOk();

        $this->assertDatabaseMissing('push_subscriptions', ['id' => $mine->id]);

        // ردیفِ بی‌صاحب هم در دامنهٔ لغوِ کاربرِ واردشده است.
        $this->actingAs($owner)
            ->postJson('/api/v1/push/unsubscribe', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/guest'])
            ->assertOk();

        $this->assertDatabaseMissing('push_subscriptions', ['id' => $guestOwned->id]);
    }

    /**
     * 🔴 پاسخِ لغو باید **غیرقابل تشخیص** بماند: ردیفِ مالِ کسِ دیگر، ردیفِ
     * ناموجود و لغوی موفق همگی `200 {ok:true}` می‌دهند.
     *
     * تفاوتِ وضعیت یا بدنه خودش یک اوراکلِ وجود است: با آن یک مهاجم می‌فهمد کدام
     * endpointها در سامانه ثبت شده‌اند و مالِ چه کسی‌اند.
     */
    public function test_unsubscribe_response_is_identical_for_owned_foreign_and_missing_rows(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        PushSubscription::query()->create([
            'endpoint' => self::ENDPOINT,
            'p256dh' => 'a',
            'auth' => 'b',
            'user_id' => $owner->id,
        ]);

        $foreign = $this->actingAs($attacker)
            ->postJson('/api/v1/push/unsubscribe', ['endpoint' => self::ENDPOINT]);

        $missing = $this->postJson('/api/v1/push/unsubscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/never-existed',
        ]);

        $this->assertSame($missing->status(), $foreign->status());
        $this->assertSame($missing->json(), $foreign->json());
        $this->assertSame(['ok' => true], $foreign->json());
    }

    /**
     * 🔴 لیست اشتراک‌ها فقط برای مدیر — و بدون endpoint.
     */
    public function test_listing_subscriptions_requires_authentication(): void
    {
        $this->getJson('/api/v1/push/subscriptions')->assertUnauthorized();
    }

    public function test_an_authenticated_user_sees_only_their_own_subscriptions(): void
    {
        $mine = PushSubscription::query()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/mine',
            'p256dh' => 'a',
            'auth' => 'b',
            'user_id' => null,
        ]);

        $response = $this->actingAs(\App\Models\User::factory()->create())
            ->getJson('/api/v1/push/subscriptions');

        $response->assertOk();

        $this->assertStringNotContainsString('mine', (string) $response->getContent());
        $this->assertNotNull($mine->id);
    }

    // ── F5.3 — ستون `topic` ────────────────────────────────────────

    /**
     * ⭐ `topic` اختیاری است و نبودنش یعنی «همهٔ انواع صفحه».
     *
     * فرانت فعلی هیچ `topic`ی نمی‌فرستد، پس ردیف‌ها باید `NULL` ذخیره شوند —
     * نه یک مقدارِ پیش‌فرض. اگر پیش‌فرض می‌گذاشتیم، هر ردیف یک محدودیتِ
     * ساختگی می‌گرفت که نه فرانت می‌تواند آن را تغییر دهد و نه کاربر دیده.
     */
    public function test_topic_stays_null_when_the_client_does_not_send_it(): void
    {
        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
        ])->assertCreated();

        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => self::ENDPOINT,
            'topic' => null,
        ]);
    }

    public function test_a_sent_topic_is_persisted(): void
    {
        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
            'topic' => 'blog',
        ])->assertCreated();

        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => self::ENDPOINT,
            'topic' => 'blog',
        ]);
    }

    /**
     * 🔴 ثبت دوباره (مثلاً تعویض کلیدِ دستگاه) نباید topicِ ذخیره‌شده را پاک
     * کند. پاک شدنش یعنی یک محدودیتِ واقعی بی‌سروصدا از بین می‌رود — همان
     * منطقی که برای `user_id` هم اعمال شده.
     */
    public function test_resubscribing_without_a_topic_keeps_the_stored_one(): void
    {
        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
            'topic' => 'blog',
        ])->assertCreated();

        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => str_repeat('C', 87), 'auth' => str_repeat('D', 22)],
        ])->assertOk();

        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => self::ENDPOINT,
            'topic' => 'blog',
        ]);
    }

    /**
     * ‎`topic = null` صریح یعنی «بردارش» — این تنها راهِ پاک کردنِ عمدی است.
     */
    public function test_an_explicit_null_topic_clears_it(): void
    {
        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
            'topic' => 'blog',
        ])->assertCreated();

        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => str_repeat('C', 87), 'auth' => str_repeat('D', 22)],
            'topic' => null,
        ])->assertOk();

        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => self::ENDPOINT,
            'topic' => null,
        ]);
    }

    public function test_topic_length_is_validated(): void
    {
        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
            'topic' => str_repeat('x', 65),
        ])->assertJsonValidationErrors('topic');
    }

    /**
     * ⭐⭐ `locale` واقعاً نوشته می‌شود — چون فیلترِ اصلیِ spam روی همین است.
     *
     * قبلاً `locale` هم نوشته می‌شد ولی **هیچ‌جا** خوانده نمی‌شد، یعنی هر صفحهٔ
     * منتشرشده به همه می‌رفت.
     */
    public function test_locale_is_persisted_from_the_request(): void
    {
        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
            'locale' => 'en',
        ])->assertCreated();

        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => self::ENDPOINT,
            'locale' => 'en',
        ]);
    }

    /**
     * 🔴 `locale` فقط از فهرستِ زبان‌های سایت (`fa`/`en`).
     *
     * این ستون **کلیدِ فیلتر** اعلان است (`scopeInterestedIn`). قبلاً هر رشتهٔ
     * ≤۱۰ نویسه‌ای پذیرفته می‌شد، پس یک مقدارِ ناشناخته ردیف را طوری قفل می‌کرد که
     * هیچ اعلانی به آن نمی‌رسید (بی‌صدا) و در عین حال فیلتر را خنثی هم نمی‌کرد.
     */
    public function test_an_unknown_locale_is_rejected(): void
    {
        foreach (['de', 'xx', str_repeat('a', 11)] as $locale) {
            $this->postJson('/api/v1/push/subscribe', [
                'endpoint' => self::ENDPOINT,
                'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
                'locale' => $locale,
            ])->assertJsonValidationErrors('locale');
        }

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    /**
     * ⭐ `locale` مثل `topic` فقط وقتی نوشته می‌شود که فر واقعاً فرستاده باشد.
     *
     * نسخهٔ قبل در هر `store()` آن را با `app()->getLocale()` بازنویسی می‌کرد؛
     * پس یک تعویضِ کلیدِ دستگاه (درخواستی که اصلاً دربارهٔ زبان نبود) زبانِ واقعیِ
     * مشترک را عوض می‌کرد و اعلان‌هایش بی‌صدا فیلتر می‌شدند.
     */
    public function test_resubscribing_without_a_locale_keeps_the_stored_one(): void
    {
        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
            'locale' => 'en',
        ])->assertCreated();

        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => str_repeat('C', 87), 'auth' => str_repeat('D', 22)],
        ])->assertOk();

        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => self::ENDPOINT,
            'locale' => 'en',
        ]);
    }

    /**
     * نبودنِ `locale` در ردیفِ تازه یعنی «محدودیتی ندارم» (قاعدهٔ مشترکِ
     * `scopeInterestedIn`) — نه زبانِ سرور به‌عنوان پیش‌فرضِ ساختگی.
     */
    public function test_a_new_subscription_without_a_locale_is_unfiltered(): void
    {
        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
        ])->assertCreated();

        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => self::ENDPOINT,
            'locale' => null,
        ]);
    }

    /**
     * انتزاع `provider` برای پاک‌سازی هوشمند بعدی لازم است.
     */
    public function test_provider_is_detected_from_the_endpoint(): void
    {
        $cases = [
            'https://fcm.googleapis.com/fcm/send/x' => 'fcm',
            'https://updates.push.services.mozilla.com/wpush/v2/x' => 'mozilla',
            'https://wns2-by3p.notify.windows.com/w/?token=x' => 'edge',
            'https://push.example.com/x' => null,
        ];

        foreach ($cases as $endpoint => $expected) {
            $subscription = new PushSubscription(['endpoint' => $endpoint]);

            // `provider` یک accessor است (مشتق‌شده از endpoint)، پس مثل
            // ویژگی خوانده می‌شود نه مثل متد.
            $this->assertSame($expected, $subscription->provider, "endpoint: {$endpoint}");
        }
    }

    public function test_vapid_key_is_created_on_first_use(): void
    {
        $this->assertFalse(VapidKeys::exists());

        VapidKeys::get();

        $this->assertTrue(VapidKeys::exists());
    }

    /**
     * 🔴 رگرسیون: کلیدِ خصوصیِ ذخیره‌شده باید decrypt شود.
     *
     * نسخهٔ اول `get()` هنگام ذخیره `Crypt::encryptString()` را صدا
     * می‌زد، ولی هنگام **خواندن** همان ciphertext را برمی‌گرداند. یعنی
     * فراخوانیِ اول کار می‌کرد و از فراخوانیِ دوم به بعد امضا می‌شکست —
     * و هر اعلان با خطای ۴۰۱ رد می‌شد.
     *
     * تست‌های Unit قبلی هم هر بار `generate()` تازه صدا می‌زدند، پس این
     * مسیر را اصلاً نمی‌دیدند.
     */
    public function test_stored_private_key_is_decryptable_and_stays_stable(): void
    {
        $first = VapidKeys::get();

        $second = VapidKeys::get();

        $this->assertSame($first['publicKey'], $second['publicKey']);
        $this->assertSame($first['privateKey'], $second['privateKey']);

        // کلیدِ خوانده‌شده باید واقعاً یک کلیدِ خصوصیِ ES256 باشد، نه
        // ciphertext. اگر ciphertext بود، `openssl_sign` خطا می‌داد.
        $signature = VapidKeys::signEs256('payload', $second['privateKey']);

        $this->assertSame(64, strlen(\App\Services\Push\Ecdh::b64d($signature)));
    }

    /**
     * کلید ذخیره‌شده در دیتابیس نباید plaintext باشد.
     */
    public function test_stored_private_key_is_encrypted_at_rest(): void
    {
        $keys = VapidKeys::get();

        $stored = DB::table('settings')
            ->where('group', 'push')
            ->where('key', 'vapid.private_key')
            ->value('value');

        $this->assertNotSame($keys['privateKey'], (string) $stored);
    }

    /**
     * هدر `Authorization` باید از کلیدِ ذخیره‌شده ساخته شود — یعنی همان
     * مسیری که `PushSender` در زمان ارسال واقعی صدا می‌زند.
     */
    public function test_authorization_header_works_after_the_key_was_stored(): void
    {
        VapidKeys::get();

        $header = VapidKeys::authorizationHeader('https://fcm.googleapis.com', 'mailto:a@b.com');

        $this->assertStringStartsWith('vapid t=', $header);
        $this->assertStringContainsString(',k=', $header);

        $token = explode(',k=', $header)[0];
        $this->assertCount(3, explode('.', $token));
    }

    // ── ستون `vapid_public_key` — کلیدی که اشتراک زیرِ آن ساخته شده ─────

    /**
     * ⭐⭐ ستونِ `vapid_public_key` باید در لحظهٔ ثبت نوشته شود.
     *
     * تا پیش از این تغییر ستون وجود داشت ولی **هیچ‌چیز آن را نمی‌نوشت**. یعنی
     * بعد از چرخشِ کلیدِ VAPID هر اشتراک بی‌صدا ۴۰۱ می‌خورد و هیچ راهی نبود
     * که اپراتور بفهمد کدام‌ها را باید از کاربر بخواهد دوباره opt-in کند.
     *
     * این تست عمداً کلید را **قبل** از ثبت می‌خواند و می‌داند مقدارِ ذخیره‌شده
     * باید دقیقاً همان باشد.
     */
    public function test_a_new_subscription_stores_the_current_vapid_public_key(): void
    {
        $current = VapidKeys::get()['publicKey'];

        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
        ])->assertCreated();

        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => self::ENDPOINT,
            'vapid_public_key' => $current,
        ]);
    }

    /**
     * 🔴 تعویضِ کلیدِ دستگاه (همان endpoint، ثبتِ دوباره) **نباید** این ستون را
     * بازنویسی کند.
     *
     * دلیل: به‌روزرسانیِ این مسیر «تعویض کلیدِ دستگاه» است؛ جفتِ VAPID که سرویس
     * push برای این endpoint می‌شناسد با آن عوض نمی‌شود. بازنویسی یعنی یک ردیفِ
     * واقعاً مرده (که بعد از چرخش ساخته شده) دوباره «سالم» نشان داده می‌شود و
     * تشخیصِ لازم برای re-subscribe از بین می‌رود.
     */
    public function test_resubscribing_does_not_rewrite_the_recorded_vapid_key(): void
    {
        $current = VapidKeys::get()['publicKey'];

        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
        ])->assertCreated();

        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => str_repeat('C', 87), 'auth' => str_repeat('D', 22)],
        ])->assertOk();

        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => self::ENDPOINT,
            'vapid_public_key' => $current,
        ]);
    }

    /**
     * ⭐ `isStaleFor()`: کلیدِ فعلی ⇒ سالم؛ کلیدِ دیگر ⇒ کهنه.
     */
    public function test_is_stale_for_compares_the_recorded_key_against_the_current_one(): void
    {
        $current = VapidKeys::get()['publicKey'];

        $fresh = new PushSubscription(['vapid_public_key' => $current]);
        $rotated = new PushSubscription(['vapid_public_key' => 'rotated-public-key']);

        $this->assertFalse($fresh->isStaleFor($current));
        $this->assertTrue($rotated->isStaleFor($current));

        // برعکسش هم باید همان نتیجه را بدهد (نامتقارن نبودنِ مقایسه).
        $this->assertTrue($fresh->isStaleFor('rotated-public-key'));
        $this->assertFalse($rotated->isStaleFor('rotated-public-key'));
    }

    /**
     * ⭐ ردیفِ پیش از نوشتنِ این ستون `NULL` دارد ⇒ «نامعلوم»، نه «کهنه».
     *
     * گفتنِ «کهنه» دربارهٔ ردیفی که کلیدِ ثبت‌شده ندارد ادعای بی‌پشتوانه است و
     * پنل را پر از هشدارِ بی‌معنا می‌کند.
     */
    public function test_a_null_recorded_key_means_unknown_not_stale(): void
    {
        $current = VapidKeys::get()['publicKey'];

        $legacy = new PushSubscription(['vapid_public_key' => null]);

        $this->assertFalse($legacy->isStaleFor($current));
        $this->assertFalse($legacy->isStaleFor('rotated-public-key'));
    }

    /**
     * ⭐⭐ اپراتور باید در لیستِ مدیر ببیند کدام اشتراک‌ها بعد از چرخش مرده‌اند.
     *
     * قبلاً پاسخ فقط `id/provider/created_at` داشت، پس ردیفِ مرده و ردیفِ سالم
     * کاملاً یکسان دیده می‌شدند — در حالی که یکی کار می‌کرد و دیگری همهٔ pushها
     * را بی‌صدا ۴۰۱ می‌خورد.
     */
    public function test_the_admin_list_marks_subscriptions_made_under_an_old_key_as_stale(): void
    {
        $user = User::factory()->create();
        $current = VapidKeys::get()['publicKey'];

        $healthy = PushSubscription::query()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/healthy',
            'p256dh' => 'a',
            'auth' => 'b',
            'vapid_public_key' => $current,
            'user_id' => $user->id,
        ]);

        $rotated = PushSubscription::query()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/rotated',
            'p256dh' => 'a',
            'auth' => 'b',
            'vapid_public_key' => 'rotated-public-key',
            'user_id' => $user->id,
        ]);

        $legacy = PushSubscription::query()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/legacy',
            'p256dh' => 'a',
            'auth' => 'b',
            'vapid_public_key' => null,
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/push/subscriptions')
            ->assertOk();

        $rows = collect($response->json('data'))->keyBy('id');

        $this->assertFalse($rows[$healthy->id]['stale']);
        $this->assertTrue($rows[$rotated->id]['stale']);
        $this->assertFalse($rows[$legacy->id]['stale'], 'NULL یعنی نامعلوم، نه کهنه.');

        // ⚠️ فیلدهای قبلی نباید حذف یا تغییر نام داده باشند.
        $this->assertSame(3, $response->json('count'));
        $this->assertArrayHasKey('provider', $rows[$healthy->id]);
        $this->assertArrayHasKey('created_at', $rows[$healthy->id]);
    }

    /**
     * 🔴 افزودنِ `stale` نباید endpoint را لو بدهد — پاسخ همچنان باید بدون
     * هیچ اثری از آدرسِ مقصد باشد.
     */
    public function test_the_stale_flag_does_not_leak_the_endpoint(): void
    {
        $user = User::factory()->create();

        PushSubscription::query()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/supersecrettoken',
            'p256dh' => 'a',
            'auth' => 'b',
            'vapid_public_key' => 'rotated-public-key',
            'user_id' => $user->id,
        ]);

        $content = (string) $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/push/subscriptions')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('supersecrettoken', $content);
        $this->assertStringNotContainsString('fcm.googleapis.com', $content);
        $this->assertStringContainsString('"stale":true', $content);
    }
}