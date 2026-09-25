<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'کاربر تست',
            'email' => 'user@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ], $overrides));
    }

    public function test_login_success_without_2fa_returns_token(): void
    {
        $this->makeUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com',
            'password' => 'Secret!123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'email', 'created_at_jalali']])
            ->assertJsonPath('two_factor_required', false);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_wrong_password_and_unknown_email_share_same_message(): void
    {
        $this->makeUser();

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com', 'password' => 'Wrong!123',
        ]);
        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com', 'password' => 'Secret!123',
        ]);

        $wrongPassword->assertStatus(401)->assertJsonPath('message', 'اعتبار ورود نامعتبر است.');
        $unknownEmail->assertStatus(401)->assertJsonPath('message', 'اعتبار ورود نامعتبر است.');
    }

    public function test_login_brute_force_locks_out(): void
    {
        $this->makeUser(['email' => 'locked@example.com']);

        $last = null;
        for ($i = 0; $i < 12; $i++) {
            $last = $this->postJson('/api/v1/auth/login', [
                'email' => 'locked@example.com', 'password' => 'Wrong!123',
            ]);
        }

        $last->assertStatus(429)
            ->assertJsonPath('message', 'تلاش‌های ورود بیش از حد مجاز است. لطفاً دقایقی دیگر تلاش کنید.');
    }

    public function test_login_with_2fa_returns_challenge_then_verify_succeeds(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $this->makeUser(['email' => 'tfa@example.com', 'google2fa_secret' => $secret, 'google2fa_enabled' => true]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'tfa@example.com', 'password' => 'Secret!123',
        ]);
        $login->assertOk()->assertJsonPath('two_factor_required', true);
        $challenge = $login->json('challenge');
        $this->assertNotEmpty($challenge);
        // No full token before the second step.
        $this->assertNull($login->json('token'));

        $code = (new Google2FA)->getCurrentOtp($secret);
        $verify = $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge' => $challenge, 'code' => $code,
        ]);
        $verify->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_2fa_verify_wrong_code_fails_with_same_message(): void
    {
        $this->makeUser(['email' => 'tfa2@example.com', 'google2fa_secret' => 'JBSWY3DPEHPK3PXP', 'google2fa_enabled' => true]);

        $challenge = $this->postJson('/api/v1/auth/login', [
            'email' => 'tfa2@example.com', 'password' => 'Secret!123',
        ])->json('challenge');

        $this->postJson('/api/v1/auth/2fa/verify', ['challenge' => $challenge, 'code' => '000000'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'اعتبار ورود نامعتبر است.');
    }

    public function test_2fa_recovery_code_is_single_use(): void
    {
        $plain = 'RECOVERY01';
        $user = $this->makeUser([
            'email' => 'rec@example.com',
            'google2fa_secret' => 'JBSWY3DPEHPK3PXP',
            'google2fa_enabled' => true,
            'recovery_codes' => [Hash::make($plain)],
        ]);

        $challenge = $this->postJson('/api/v1/auth/login', [
            'email' => 'rec@example.com', 'password' => 'Secret!123',
        ])->json('challenge');

        $this->postJson('/api/v1/auth/2fa/verify', ['challenge' => $challenge, 'code' => $plain])
            ->assertOk()->assertJsonStructure(['token']);

        $this->assertSame([], $user->fresh()->recovery_codes);
    }

    public function test_logout_revokes_current_token(): void
    {
        $this->makeUser();
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com', 'password' => 'Secret!123',
        ])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'با موفقیت خارج شدید.');

        // Old token no longer works (fresh guard: RequestGuard memoizes per app instance).
        Auth::forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/refresh')
            ->assertStatus(401);
    }

    public function test_refresh_rotates_token(): void
    {
        $this->makeUser();
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com', 'password' => 'Secret!123',
        ])->json('token');

        $new = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/refresh')
            ->assertOk()->json('token');

        $this->assertNotSame($token, $new);
        Auth::forgetGuards(); // force re-authentication on the next call
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/refresh')->assertStatus(401);
        $this->withHeader('Authorization', "Bearer {$new}")
            ->postJson('/api/v1/auth/refresh')->assertOk();
    }

    public function test_forgot_password_never_enumerates(): void
    {
        $this->makeUser();

        foreach (['user@example.com', 'ghost@example.com'] as $email) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])
                ->assertOk()
                ->assertJsonPath('message', 'اگر حسابی با این ایمیل وجود داشته باشد، لینک بازیابی ارسال شد.');
        }
    }

    public function test_reset_password_with_valid_token_then_login_with_new_password(): void
    {
        $user = $this->makeUser();
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'user@example.com',
            'password' => 'NewSecret!456',
            'password_confirmation' => 'NewSecret!456',
        ])->assertOk()->assertJsonPath('message', 'رمز عبور با موفقیت تغییر کرد.');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com', 'password' => 'NewSecret!456',
        ])->assertOk();
    }

    public function test_reset_password_with_invalid_token_fails(): void
    {
        $this->makeUser();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'invalid-token',
            'email' => 'user@example.com',
            'password' => 'NewSecret!456',
            'password_confirmation' => 'NewSecret!456',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'توکن بازیابی نامعتبر یا منقضی است.');
    }
}
