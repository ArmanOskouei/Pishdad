<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Notifications\TelegramSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * E73 — توکنِ رباتِ تلگرام از پنل (نه فقط `.env`).
 *
 * پیش‌تر توکن فقط در `.env` بود و در پنل هیچ راهی برای دیدن یا عوض‌کردنش
 * نبود؛ کاربر chat_id می‌داد ولی پیامی نمی‌رفت. حالا سه مسیر هست (خواندنِ
 * ماسک‌شده، ذخیره/پاک‌سازی، پیامِ آزمایشی) و هر سه پشت `perm:settings.edit`
 * اند — رازِ نصب است، نه تنظیمِ شخصی.
 *
 * سه قاعده قفل می‌شود: ① توکنِ کامل هرگز به مرورگر نمی‌رود ② `.env` اگر پر
 * باشد همیشه برنده است ③ خطای provider (توکن غلط، بلاک، قطعی) ۴۲۲ِ تمیز
 * می‌دهد نه ۵۰۰. ارسالِ واقعی با `Http::fake` سنجیده می‌شود تا تست به
 * اینترنت و رباتِ واقعی نیاز نداشته باشد.
 */
class TelegramBotSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/notification-preferences/telegram-bot';

    private function manager(array $permissions = []): User
    {
        $user = User::query()->create([
            'name' => 'مدیر',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Pass!1234'),
            'role' => 'editor',
        ]);

        if ($permissions !== []) {
            $role = Role::query()->firstOrCreate(['name' => 'r'.uniqid(), 'guard_name' => 'web']);
            foreach ($permissions as $p) {
                Permission::query()->firstOrCreate(['name' => $p, 'guard_name' => 'web']);
            }
            $role->givePermissionTo($permissions);
            $user->assignRole($role);
        }

        return $user->fresh();
    }

    private function asEditor(): User
    {
        return $this->manager(['settings.view', 'settings.edit']);
    }

    private function auth(User $user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    public function test_bot_endpoints_need_settings_edit(): void
    {
        $auth = $this->auth($this->manager());

        $auth->getJson(self::URL)->assertForbidden();
        $auth->putJson(self::URL, ['token' => '1234567890:'.str_repeat('A', 30)])->assertForbidden();
        $auth->postJson(self::URL.'/test', [])->assertForbidden();
    }

    public function test_unconfigured_bot_reports_cleanly(): void
    {
        $auth = $this->auth($this->asEditor());

        $auth->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.masked', null);
    }

    public function test_garbage_token_is_rejected(): void
    {
        $auth = $this->auth($this->asEditor());

        // رشتهٔ خالی/فاصله یعنی پاک‌سازی است، نه توکن — آن را اینجا نمی‌سنجیم.
        foreach (['nope', 'abc:short', '123:'.str_repeat('x', 100)] as $bad) {
            $auth->putJson(self::URL, ['token' => $bad])->assertStatus(422);
        }

        // هیچ‌کدام نباید چیزی ذخیره کرده باشد.
        $this->assertFalse(TelegramSender::configured());
    }

    public function test_token_roundtrips_masked_and_never_leaks(): void
    {
        $auth = $this->auth($this->asEditor());
        $token = '1234567890:AAbbCCddEEffGGhhIIjjKKllMMnn';

        $res = $auth->putJson(self::URL, ['token' => $token])->assertOk();
        $res->assertJsonPath('data.configured', true);

        $masked = $res->json('data.masked');
        $this->assertIsString($masked);
        $this->assertStringStartsWith('1234567890:AA***', $masked);
        // توکنِ کامل نباید هیچ‌جای پاسخ باشد.
        $this->assertStringNotContainsString($token, $res->getContent());

        $auth->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.masked', $masked);
    }

    public function test_empty_token_clears_the_panel_value(): void
    {
        $auth = $this->auth($this->asEditor());
        $token = '1234567890:AAbbCCddEEffGGhhIIjjKKllMMnn';

        $auth->putJson(self::URL, ['token' => $token])->assertOk();
        $this->assertTrue(TelegramSender::configured());

        $auth->putJson(self::URL, ['token' => null])->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.masked', null);
    }

    public function test_env_token_always_wins_over_the_panel(): void
    {
        config()->set('telegram.bot_token', '9999999999:ENVenvENVenvENVenvENVenv00');

        $auth = $this->auth($this->asEditor());

        // حتی با توکنِ پنل، ماسک باید مالِ env باشد.
        $auth->putJson(self::URL, ['token' => '1234567890:AAbbCCddEEffGGhhIIjjKKllMMnn'])->assertOk();
        $masked = TelegramSender::masked();
        $this->assertIsString($masked);
        $this->assertStringStartsWith('9999999999:EN***', $masked);
    }

    public function test_test_message_needs_a_destination(): void
    {
        $auth = $this->auth($this->asEditor());
        $auth->putJson(self::URL, ['token' => '1234567890:AAbbCCddEEffGGhhIIjjKKllMMnn'])->assertOk();

        // نه chat داده شده، نه کاربر chat_id دارد.
        $auth->postJson(self::URL.'/test', [])->assertStatus(422);
    }

    public function test_test_message_reaches_the_provider(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]], 200)]);

        $auth = $this->auth($this->asEditor());
        $auth->putJson(self::URL, ['token' => '1234567890:AAbbCCddEEffGGhhIIjjKKllMMnn'])->assertOk();

        $auth->postJson(self::URL.'/test', ['chat_id' => '12345'])->assertOk()
            ->assertJson(['message' => 'پیام آزمایشی فرستاده شد؛ تلگرام را ببینید.']);

        Http::assertSent(fn ($req) => str_contains((string) $req->url(), '/sendMessage'));
    }

    public function test_provider_rejection_is_a_clean_422(): void
    {
        // توکنِ غلط: تلگرام 401 می‌دهد (نه exception شبکه).
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        $auth = $this->auth($this->asEditor());
        $auth->putJson(self::URL, ['token' => '1234567890:AAbbCCddEEffGGhhIIjjKKllMMnn'])->assertOk();

        $auth->postJson(self::URL.'/test', ['chat_id' => '12345'])->assertStatus(422);
    }

    public function test_connection_errors_never_leak_the_token(): void
    {
        // cURL نشانیِ کامل (شاملِ توکن) را در پیامِ خطا می‌گذارد؛ پاسخِ API
        // نباید آن را برگرداند — نه در متن، نه در لاگِ کاربر.
        $token = '1234567890:AAbbCCddEEffGGhhIIjjKKllMMnn';
        Http::fake(['api.telegram.org/*' => function () use ($token): never {
            throw new \Illuminate\Http\Client\ConnectionException(
                "cURL error 7: Failed to connect to api.telegram.org:443 for https://api.telegram.org/bot{$token}/sendMessage"
            );
        }]);

        $auth = $this->auth($this->asEditor());
        $auth->putJson(self::URL, ['token' => $token])->assertOk();

        $res = $auth->postJson(self::URL.'/test', ['chat_id' => '12345'])->assertStatus(422);
        $this->assertStringNotContainsString($token, $res->getContent());
        $this->assertStringContainsString('/bot***', $res->getContent());
    }
}
