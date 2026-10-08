<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * F0.4/E3 — «سرویس پیامک هنوز فعال نشده است» باید **در عمل** درست باشد.
 *
 * ## چیزی که این تست قفل می‌کند
 *
 * پیش از این موج، `sms/request` همیشه یک کد در کش می‌گذاشت و بعد می‌گفت «سرویس
 * فعال نشده». این یعنی کاربر می‌رفت `/sms/verify`، کدِ نرسیده را از یک جای
 * نامرئی **حدس می‌زد** و عملاً قابلِ دور زدن بود — به‌علاوهٔ اینکه پیام
 * «کدی ارسال نشد» با وجودِ داشتنِ کد در کش، خودش دروغ بود.
 *
 * قراردادِ فعلی:
 *
 *  - تا وقتی درایور واقعاً **تحویل نداده**، در production هیچ کدی در کش نمی‌رود.
 *  - در production اصلاً `debug_code` برنمی‌گردد (چون راهی برای گرفتنش نیست).
 *  - درگاهِ واقعی (`panel`) با کلیدِ کامل ⇒ `sms.sent` و کد می‌رود.
 *
 * ⭐ تست‌ها هر دو محیط را جدا می‌سنجند چون رفتار **عمداً** فرق می‌کند: در
 * توسعه `debug_code` برمی‌گردد تا مسیر قابلِ تست باشد، و همین کد در کش
 * می‌ماند. اگر این تفاوت را تست نکنیم، یا کسی آن را «دروغ» پاک می‌کند و
 * توسعه‌دهنده می‌ماند بدون راهِ تست، یا کسی فکر می‌کند production هم کد را
 * می‌دهد.
 */
class SmsRequestHonestyTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'name' => 'کاربر تست',
            'email' => 'sms'.uniqid().'@example.com',
            'password' => Hash::make('Secret!1234'),
            'role' => 'admin',
        ]);
    }

    private function auth(User $user): self
    {
        $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken);

        return $this;
    }

    /**
     * `isProduction()` فقط `$this['env']` را می‌خواند، پس همین یک خط کافی است.
     */
    private function forceProduction(): void
    {
        $this->app['env'] = 'production';
        $this->assertTrue($this->app->isProduction(), 'قرار بود محیط به production تغییر کند.');
    }

    private function fakePanel(array $body, int $status = 200): void
    {
        Http::fake(['panel.example.test/*' => Http::response($body, $status)]);
    }

    private function asNullDriver(): void
    {
        config(['sms.driver' => 'null']);
    }

    private function asConfiguredPanel(): void
    {
        config([
            'sms.driver' => 'panel',
            'sms.panel.url' => 'https://panel.example.test',
            'sms.panel.api_key' => 'k',
            'sms.panel.sender' => '10004346',
        ]);
    }

    // --------------------------------------------------- production: صادقانه بسته

    public function test_in_production_the_null_driver_promises_nothing_and_stores_nothing(): void
    {
        $this->forceProduction();
        $this->asNullDriver();
        $user = $this->user();

        $this->auth($user)
            ->postJson('/api/v1/admin/profile/sms/request', ['phone' => '09121234567'])
            ->assertOk()
            ->assertJsonPath('code', 'sms.not_configured')
            ->assertJsonMissingPath('data.debug_code');

        // ⭐ کلیدِ اصلی: کدی که در کش بماند ولی پیامش نرفته، یعنی مسیرِ
        // `/sms/verify` قابلِ حدس زدن است.
        $this->assertNull(
            Cache::get("sms_pending:{$user->id}"),
            'وقتی پیامکی نرفته، نباید کدی در کش برای حدس زدن بماند.',
        );
    }

    public function test_in_production_the_log_driver_also_promises_nothing(): void
    {
        $this->forceProduction();
        config(['sms.driver' => 'log']);
        $user = $this->user();

        $this->auth($user)
            ->postJson('/api/v1/admin/profile/sms/request', ['phone' => '09121234567'])
            ->assertOk()
            ->assertJsonPath('code', 'sms.not_configured')
            ->assertJsonMissingPath('data.debug_code');
    }

    public function test_a_panel_that_rejects_the_message_leaves_no_guessable_code(): void
    {
        $this->forceProduction();
        $this->asConfiguredPanel();

        // درگاه HTTP 200 می‌دهد ولی `status: 401` ⇒ پیام را رد کرده. این
        // دقیقاً همان تله‌ای است که فقط با نگاه کردن به کدِ HTTP از دست می‌رود.
        $this->fakePanel(['status' => 401]);

        $user = $this->user();

        $this->auth($user)
            ->postJson('/api/v1/admin/profile/sms/request', ['phone' => '09121234567'])
            ->assertOk()
            ->assertJsonPath('code', 'sms.not_configured')
            ->assertJsonPath('data.driver', 'panel');

        $this->assertNull(
            Cache::get("sms_pending:{$user->id}"),
            'درگاهی که پیام را رد کرد نباید کدی برای حدس زدن باقی بگذارد.',
        );
    }

    // ------------------------------------------------ توسعه: کدِ دیباگ عمداً هست

    public function test_outside_production_the_debug_code_is_returned_for_manual_testing(): void
    {
        $this->asNullDriver();
        $user = $this->user();

        $res = $this->auth($user)
            ->postJson('/api/v1/admin/profile/sms/request', ['phone' => '09121234567'])
            ->assertOk()
            ->assertJsonPath('code', 'sms.not_configured');

        $code = $res->json('data.debug_code');
        $this->assertNotNull($code, 'در محیط غیرپروداکشن باید کد برای تست دستی برگردد.');
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);

        $pending = Cache::get("sms_pending:{$user->id}");
        $this->assertIsArray($pending);
        $this->assertSame((string) $code, $pending['code']);
    }

    // ------------------------------------------------- درگاهِ واقعی: پیام واقعاً رفت

    public function test_a_configured_panel_reports_sent_and_stores_the_code(): void
    {
        $this->asConfiguredPanel();
        $this->fakePanel(['status' => 200]);

        $user = $this->user();

        $this->auth($user)
            ->postJson('/api/v1/admin/profile/sms/request', ['phone' => '09121234567'])
            ->assertOk()
            ->assertJsonPath('code', 'sms.sent');

        $pending = Cache::get("sms_pending:{$user->id}");
        $this->assertIsArray($pending, 'وقتی پیام واقعاً رفت، کد باید در کش باشد.');
        $this->assertSame('09121234567', $pending['phone']);
    }

    public function test_a_transport_error_is_reported_without_promising_delivery(): void
    {
        $this->forceProduction();
        $this->asConfiguredPanel();

        // پنل خراب است — گذراست، پس تلاشِ مجدد معنی دارد ولی باز هم نباید
        // «ارسال شد» گفت.
        Http::fake(['panel.example.test/*' => Http::response('gateway down', 502)]);
        $user = $this->user();

        $this->auth($user)
            ->postJson('/api/v1/admin/profile/sms/request', ['phone' => '09121234567'])
            ->assertOk()
            ->assertJsonPath('code', 'sms.not_configured');
    }

    public function test_an_invalid_phone_is_rejected_before_any_driver_is_touched(): void
    {
        $this->asNullDriver();
        $user = $this->user();

        $this->auth($user)
            ->postJson('/api/v1/admin/profile/sms/request', ['phone' => '12345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }
}