<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** دسته UIUX — اپراتور: صدور secret برای ثبت کلید با QR + آواتار مدیران. */
class OperatorHardwareSetupTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::query()->create([
            'name' => 'اپراتور', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Operator!1'), 'role' => 'operator',
        ]);
    }

    public function test_hardware_setup_returns_secret_and_otpauth(): void
    {
        $auth = $this->actingAs($this->operator(), 'sanctum');

        $data = $auth->getJson('/api/central/operator/2fa/hardware/setup')
            ->assertOk()->json('data');

        $this->assertNotEmpty($data['secret']);
        $this->assertStringStartsWith('otpauth://', $data['otpauth_url']);

        // secret واقعی باید با کد TOTP همان لحظه قابل ثبت باشد.
        $code = (new Google2FA)->getCurrentOtp($data['secret']);
        $auth->postJson('/api/central/operator/2fa/hardware', [
            'name' => 'کلید QR', 'secret' => $data['secret'], 'code' => $code,
        ])->assertCreated()->assertJsonPath('message', 'کلید سخت‌افزاری ثبت شد.');
    }

    public function test_manager_payload_includes_avatar_keys(): void
    {
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $admin = User::query()->create([
            'name' => 'ادمین', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'admin',
        ]);
        $admin->assignRole('owner');

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/managers')
            ->assertOk()
            ->assertJsonStructure(['data' => ['data' => [['avatar_media_id', 'avatar_url']]]]);
    }
}
