<?php

namespace Tests\Feature;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** WF-H8 — تاریخچهٔ ورود: ثبت موفق/ناموفق + endpoint جدیدترین-اول و scoped به کاربر. */
class LoginHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'مدیر',
            'email' => 'login-history@example.com',
            'password' => Hash::make('Secret!1234'),
            'role' => 'admin',
        ], $overrides));
    }

    public function test_records_successful_and_failed_login_attempts(): void
    {
        $user = $this->user();
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        $this->withHeader('User-Agent', $ua)
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-pass'])
            ->assertStatus(401);

        $this->withHeader('User-Agent', $ua)
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Secret!1234'])
            ->assertOk();

        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => '127.0.0.1',
            'successful' => false,
        ]);
        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'email' => $user->email,
            'successful' => true,
        ]);
        $this->assertSame(2, LoginEvent::query()->count());
    }

    public function test_records_unknown_email_failure_without_user(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ghost@example.com', 'password' => 'whatever',
        ])->assertStatus(401);

        $this->assertDatabaseHas('login_events', [
            'user_id' => null,
            'email' => 'ghost@example.com',
            'successful' => false,
        ]);
    }

    public function test_two_factor_failure_and_success_are_recorded(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $user = $this->user([
            'email' => 'tfa-history@example.com',
            'google2fa_secret' => $secret,
            'google2fa_enabled' => true,
        ]);

        $challenge = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Secret!1234',
        ])->assertOk()->assertJsonPath('two_factor_required', true)->json('challenge');

        // مرحلهٔ اول ۲FA (رمز درست) هنوز ورودِ کامل نیست؛ چیزی ثبت نمی‌شود.
        $this->assertSame(0, LoginEvent::query()->count());

        $this->postJson('/api/v1/auth/2fa/verify', ['challenge' => $challenge, 'code' => '000000'])
            ->assertStatus(401);
        $this->assertDatabaseHas('login_events', ['user_id' => $user->id, 'successful' => false]);

        $challenge2 = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Secret!1234',
        ])->json('challenge');
        $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge' => $challenge2, 'code' => (new Google2FA)->getCurrentOtp($secret),
        ])->assertOk()->assertJsonStructure(['token']);
        $this->assertDatabaseHas('login_events', ['user_id' => $user->id, 'successful' => true]);
    }

    public function test_login_history_endpoint_returns_newest_first_scoped_to_user(): void
    {
        $user = $this->user(['email' => 'mine@example.com']);
        $other = $this->user(['email' => 'other@example.com']);

        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        foreach (range(1, 12) as $i) {
            LoginEvent::query()->create([
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => '10.0.0.'.$i,
                'user_agent' => $ua,
                'successful' => $i % 2 === 0,
            ]);
        }
        LoginEvent::query()->create([
            'user_id' => $other->id, 'email' => $other->email, 'successful' => false,
        ]);

        $data = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/profile/login-history')
            ->assertOk()
            ->assertJsonStructure(['data' => ['count', 'events' => [[
                'id', 'successful', 'ip', 'os', 'os_version', 'browser',
                'browser_version', 'device', 'device_type', 'created_at',
            ]]]])
            ->json('data');

        // فقط رویدادهای همین کاربر و حداکثر ۱۰ تا.
        $this->assertSame(10, $data['count']);
        $this->assertSame(1, LoginEvent::query()->where('user_id', $other->id)->count());

        $ips = array_column($data['events'], 'ip');
        $this->assertSame('10.0.0.12', $ips[0]);
        $this->assertSame('10.0.0.3', $ips[9]);

        $first = $data['events'][0];
        $this->assertTrue($first['successful']);
        $this->assertSame('Windows', $first['os']);
        $this->assertSame('10.0', $first['os_version']);
        $this->assertSame('Chrome', $first['browser']);
        $this->assertSame('Chrome روی Windows', $first['device']);
        $this->assertSame('دسکتاپ', $first['device_type']);
        $this->assertNotEmpty($first['created_at']);
    }
}
