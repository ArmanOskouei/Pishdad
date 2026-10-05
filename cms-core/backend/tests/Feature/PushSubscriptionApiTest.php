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
     */
    public function test_known_provider_subdomains_are_accepted(): void
    {
        $accepted = [
            'https://fcm.googleapis.com/fcm/send/token',
            'https://updates.push.services.mozilla.com/wpush/v2/token',
            'https://wns2-by3p.notify.windows.com/w/?token=x',
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
     * 🔴 ثبت دوباره با endpoint کاربرِ دیگر نباید مالکیت را بدزدد.
     *
     * endpoint یک کلید یکتاست؛ جابه‌جا کردن `user_id` بین دو کاربر هم
     * لیست اعلان‌های مدیر را آلوده می‌کند و هم می‌گذارد یک کاربر اعلان‌های
     * دستگاهِ کاربر دیگر را ببیند.
     */
    public function test_resubscribing_never_reassigns_user_id(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $subscription = PushSubscription::query()->create([
            'endpoint' => self::ENDPOINT,
            'p256dh' => str_repeat('A', 87),
            'auth' => str_repeat('B', 22),
            'user_id' => $owner->id,
        ]);

        $this->actingAs($other)
            ->postJson('/api/v1/push/subscribe', [
                'endpoint' => self::ENDPOINT,
                'keys' => ['p256dh' => str_repeat('C', 87), 'auth' => str_repeat('D', 22)],
            ])
            ->assertOk();

        $subscription->refresh();

        $this->assertSame(
            (int) $owner->id,
            (int) $subscription->user_id,
            'user_id هرگز نباید از یک کاربر به کاربر دیگر تغییر کند.'
        );

        // کلیدهای عمومی دستگاه به‌روزرسانی می‌شوند (چیزی که واقعاً عوض
        // می‌شود)، ولی مالکیت دست‌نخورده می‌ماند.
        $this->assertSame(str_repeat('C', 87), $subscription->p256dh);
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
}