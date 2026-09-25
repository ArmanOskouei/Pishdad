<?php

namespace Tests\Feature;

use App\Models\Central\KillSwitch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** تسک ۵.۷ — DoD: توکن نصب با step-up 2FA + kill-switch دومرحله‌ای + لاگ فعالیت. */
class OperatorTest extends TestCase
{
    use RefreshDatabase;

    private function operator(bool $with2fa = true): User
    {
        $google2fa = new Google2FA;

        return User::query()->create([
            'name' => 'اپراتور', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Operator!1'), 'role' => 'operator',
            'google2fa_secret' => $with2fa ? $google2fa->generateSecretKey() : null,
            'google2fa_enabled' => $with2fa,
        ]);
    }

    /** ورود کامل 2FA تا مهر «تازه» برای step-up ثبت شود. */
    private function freshLogin(User $operator): void
    {
        $challenge = $this->postJson('/api/v1/auth/login', [
            'email' => $operator->email, 'password' => 'Operator!1',
        ])->assertOk()->json('challenge');

        $code = (new Google2FA)->getCurrentOtp($operator->google2fa_secret);
        $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge' => $challenge, 'code' => $code,
        ])->assertOk();
    }

    public function test_setup_token_requires_fresh_two_factor(): void
    {
        $operator = $this->operator();

        // بدون 2FA تازه → 403.
        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/central/operator/setup-tokens', ['purpose' => 'نصب جدید'])
            ->assertForbidden();

        $this->freshLogin($operator);

        $res = $this->actingAs($operator->fresh(), 'sanctum')
            ->postJson('/api/central/operator/setup-tokens', ['purpose' => 'نصب جدید'])
            ->assertCreated();

        $this->assertEquals(48, strlen($res->json('data.token')));
        $this->assertDatabaseHas('operator_setup_tokens', ['purpose' => 'نصب جدید']);
    }

    public function test_hardware_key_registration(): void
    {
        $auth = $this->actingAs($this->operator(false), 'sanctum');
        $secret = (new Google2FA)->generateSecretKey();
        $code = (new Google2FA)->getCurrentOtp($secret);

        $auth->postJson('/api/central/operator/2fa/hardware', [
            'name' => 'کلید یدکی', 'secret' => $secret, 'code' => '000000',
        ])->assertStatus(422);

        $auth->postJson('/api/central/operator/2fa/hardware', [
            'name' => 'کلید یدکی', 'secret' => $secret, 'code' => $code,
        ])->assertCreated()->assertJsonPath('message', 'کلید سخت‌افزاری ثبت شد.');
    }

    public function test_kill_switch_two_step_and_immutable_log(): void
    {
        $operator = $this->operator();
        $this->freshLogin($operator);
        $auth = $this->actingAs($operator->fresh(), 'sanctum');

        // بدون دلیل و بدون confirm → رد.
        $auth->postJson('/api/central/operator/kill-switch', ['confirm' => true])
            ->assertStatus(422);
        $auth->postJson('/api/central/operator/kill-switch', [
            'reason' => 'نفوذ تأییدشده به زیرساخت', 'confirm' => false,
        ])->assertStatus(422);

        $auth->postJson('/api/central/operator/kill-switch', [
            'reason' => 'نفوذ تأییدشده به زیرساخت', 'confirm' => true,
        ])->assertOk();

        $record = KillSwitch::query()->first();
        $this->assertNotNull($record);

        // لاگ غیرقابل حذف/ویرایش.
        $record->delete();
        $this->assertModelExists($record);
        $record->forceFill(['reason' => 'دست‌کاری'])->save();
        $this->assertEquals('نفوذ تأییدشده به زیرساخت', $record->fresh()->reason);
    }

    public function test_activity_log_lists_operator_actions(): void
    {
        $operator = $this->operator();
        $this->freshLogin($operator);
        $auth = $this->actingAs($operator->fresh(), 'sanctum');

        $auth->postJson('/api/central/operator/setup-tokens', ['purpose' => 'x'])->assertCreated();

        $auth->getJson('/api/central/operator/activity')->assertOk()
            ->assertJsonFragment(['action' => 'operator.setup_token']);
    }
}
