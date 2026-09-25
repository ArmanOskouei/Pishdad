<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** تسک ۵.۲ — DoD: تغییر رمز + فعال‌سازی 2FA + کد بازیابی + اسلات پلاگین. */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مدیر', 'email' => uniqid().'@example.com',
            'password' => Hash::make('OldPass!123'), 'role' => 'admin',
        ]);
    }

    public function test_show_and_update_profile(): void
    {
        $auth = $this->actingAs($this->user(), 'sanctum');

        $auth->getJson('/api/v1/admin/profile')->assertOk()->assertJsonPath('data.name', 'مدیر');

        $auth->putJson('/api/v1/admin/profile', [
            'name' => 'مدیر جدید', 'phone' => '09123456789',
        ])->assertOk()->assertJsonPath('message', 'پروفایل به‌روزرسانی شد.');

        $auth->putJson('/api/v1/admin/profile', ['phone' => '123'])->assertStatus(422);
    }

    public function test_change_password_checks_current_and_strength(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $auth->postJson('/api/v1/admin/profile/password', [
            'current_password' => 'Wrong!1234',
            'password' => 'NewPass!123', 'password_confirmation' => 'NewPass!123',
        ])->assertStatus(422)->assertJsonPath('message', 'رمز فعلی صحیح نیست.');

        $auth->postJson('/api/v1/admin/profile/password', [
            'current_password' => 'OldPass!123',
            'password' => 'weakpassword', 'password_confirmation' => 'weakpassword',
        ])->assertStatus(422);

        $auth->postJson('/api/v1/admin/profile/password', [
            'current_password' => 'OldPass!123',
            'password' => 'NewPass!123', 'password_confirmation' => 'NewPass!123',
        ])->assertOk();

        // نشست واقعی قبلی باطل می‌شود و رمز جدید کار می‌کند.
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'NewPass!123',
        ])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);

        // تغییر دوم همه نشست‌ها (از جمله توکن لاگین بالا) را باطل می‌کند.
        $auth->postJson('/api/v1/admin/profile/password', [
            'current_password' => 'NewPass!123',
            'password' => 'Brand!New99', 'password_confirmation' => 'Brand!New99',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Brand!New99',
        ])->assertOk()->assertJsonPath('two_factor_required', false);
    }

    public function test_two_factor_enable_disable_cycle(): void
    {
        $auth = $this->actingAs($this->user(), 'sanctum');
        $google2fa = new Google2FA;

        $step1 = $auth->postJson('/api/v1/admin/profile/2fa/enable')->assertOk();
        $secret = $step1->json('data.secret');
        $this->assertNotEmpty($step1->json('data.otpauth_url'));

        $auth->postJson('/api/v1/admin/profile/2fa/enable', ['code' => '000000'])
            ->assertStatus(422);

        $code = $google2fa->getCurrentOtp($secret);
        $step2 = $auth->postJson('/api/v1/admin/profile/2fa/enable', ['code' => $code])
            ->assertOk();
        $this->assertCount(8, $step2->json('data.recovery_codes'));

        $auth->getJson('/api/v1/admin/profile/2fa/methods')->assertOk()
            ->assertJsonPath('data.totp.enabled', true)
            ->assertJsonPath('data.recovery_codes_remaining', 8);

        // غیرفعال‌سازی بدون مدرک معتبر رد می‌شود.
        $auth->postJson('/api/v1/admin/profile/2fa/disable')->assertStatus(422);
        $auth->postJson('/api/v1/admin/profile/2fa/disable', ['password' => 'OldPass!123'])
            ->assertOk();
        $auth->getJson('/api/v1/admin/profile/2fa/methods')->assertOk()
            ->assertJsonPath('data.totp.enabled', false);
    }

    public function test_sections_returns_empty_ready_registry(): void
    {
        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/v1/admin/profile/sections')->assertOk()
            ->assertJsonPath('data.registry_version', '1.0')
            ->assertJsonPath('data.sections', []);
    }

    /** تسک ۵ — فیلدهای جدید پروفایل: آواتار (مالکیت) + نام/نام‌خانوادگی + درباره من. */
    public function test_profile_extended_fields_roundtrip(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $media = Media::query()->create([
            'user_id' => $user->id, 'disk' => 's3', 'path' => 'avatars/a.png',
            'original_name' => 'a.png', 'mime' => 'image/png', 'size' => 123,
        ]);

        $auth->putJson('/api/v1/admin/profile', [
            'first_name' => 'آرمان',
            'last_name' => 'اسکوئی',
            'bio' => 'درباره من…',
            'avatar_media_id' => $media->id,
        ])->assertOk()
            ->assertJsonPath('message', 'پروفایل به‌روزرسانی شد.')
            ->assertJsonPath('data.first_name', 'آرمان')
            ->assertJsonPath('data.last_name', 'اسکوئی')
            ->assertJsonPath('data.bio', 'درباره من…')
            ->assertJsonPath('data.avatar_media_id', $media->id);

        $auth->getJson('/api/v1/admin/profile')->assertOk()
            ->assertJsonPath('data.first_name', 'آرمان')
            ->assertJsonPath('data.avatar_media_id', $media->id);

        // اعتبارسنجی فارسی: سقف طول + وجود رسانه.
        $auth->putJson('/api/v1/admin/profile', ['first_name' => str_repeat('ا', 61)])
            ->assertStatus(422);
        $auth->putJson('/api/v1/admin/profile', ['bio' => str_repeat('ا', 1001)])
            ->assertStatus(422);
        $auth->putJson('/api/v1/admin/profile', ['avatar_media_id' => 999999])
            ->assertStatus(422);

        // پاک‌سازی آواتار مجاز است.
        $auth->putJson('/api/v1/admin/profile', ['avatar_media_id' => null])->assertOk()
            ->assertJsonPath('data.avatar_media_id', null);
    }

    /** مشترک نصب: آواتار می‌تواند هر رسانه موجود نصب باشد. */
    public function test_avatar_accepts_shared_media(): void
    {
        $me = $this->user();
        $other = $this->user();
        $shared = Media::query()->create([
            'user_id' => $other->id, 'disk' => 's3', 'path' => 'avatars/b.png',
            'original_name' => 'b.png', 'mime' => 'image/png', 'size' => 10,
        ]);

        $this->actingAs($me, 'sanctum')
            ->putJson('/api/v1/admin/profile', ['avatar_media_id' => $shared->id])
            ->assertOk()
            ->assertJsonPath('message', 'پروفایل به‌روزرسانی شد.')
            ->assertJsonPath('data.avatar_media_id', $shared->id);
    }

    public function test_update_email_requires_current_password_and_disables_two_factor(): void
    {
        $user = $this->user();
        $user->forceFill([
            'google2fa_secret' => 'JBSWY3DPEHPK3PXP',
            'google2fa_enabled' => true,
            'recovery_codes' => ['hashed-code'],
        ])->save();
        $other = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson('/api/v1/admin/profile', [
            'email' => $other->email,
            'current_password' => 'OldPass!123',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'این ایمیل قبلاً ثبت شده است');

        $auth->putJson('/api/v1/admin/profile', [
            'email' => 'new-profile@example.com',
            'current_password' => 'WrongPass!123',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'رمز فعلی صحیح نیست.');

        $auth->putJson('/api/v1/admin/profile', [
            'email' => 'new-profile@example.com',
            'current_password' => 'OldPass!123',
        ])->assertOk()
            ->assertJsonPath('message', 'پروفایل به‌روزرسانی شد. تأیید دومرحله‌ای غیرفعال شد؛ برای ادامه باید دوباره آن را فعال کنید.')
            ->assertJsonPath('data.email', 'new-profile@example.com')
            ->assertJsonPath('data.google2fa_enabled', false)
            ->assertJsonPath('data.two_factor_disabled', true);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'new-profile@example.com',
            'google2fa_enabled' => false,
        ]);
        $this->assertNull($user->fresh()->google2fa_secret);
        $this->assertSame([], $user->fresh()->recovery_codes);
    }

    public function test_update_email_does_not_require_password_when_unchanged(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/admin/profile', ['email' => $user->email, 'name' => 'نام تازه'])
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }
}
