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

    /**
     * WF-M9 — endpointِ شمارش کدهای بازیابی: بدون لو دادن خودِ کدها،
     * «باقی‌مانده/کل/هشدار» می‌دهد و پس از مصرف و بازتولید درست به‌روز می‌شود.
     */
    public function test_recovery_code_count_endpoint(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');
        $google2fa = new Google2FA;

        // پیش از فعال‌سازی: صفر، بدون هشدار و بدون کدِ plaintext.
        $auth->getJson('/api/v1/admin/profile/2fa/recovery-codes')->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.remaining', 0)
            ->assertJsonPath('data.total', 8)
            ->assertJsonPath('data.low', false)
            ->assertJsonMissingPath('data.recovery_codes');

        // فعال‌سازی → ۸ کد؛ و شمارنده همان را می‌گوید.
        $secret = $auth->postJson('/api/v1/admin/profile/2fa/enable')->assertOk()->json('data.secret');
        $auth->postJson('/api/v1/admin/profile/2fa/enable', [
            'code' => $google2fa->getCurrentOtp($secret),
        ])->assertOk()->assertJsonCount(8, 'data.recovery_codes');

        $count = $auth->getJson('/api/v1/admin/profile/2fa/recovery-codes')->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.remaining', 8)
            ->assertJsonPath('data.low', false);
        $count->assertJsonMissingPath('data.recovery_codes');

        // مصرف ۶ کد → باقی‌مانده ۲ و هشدارِ کم‌شدن.
        $user->refresh();
        $user->forceFill(['recovery_codes' => array_slice($user->recovery_codes, 0, 2)])->save();
        $auth->getJson('/api/v1/admin/profile/2fa/recovery-codes')->assertOk()
            ->assertJsonPath('data.remaining', 2)
            ->assertJsonPath('data.low', true);

        // بازتولید با مدرک → دوباره ۸ و بدون هشدار.
        $auth->postJson('/api/v1/admin/profile/2fa/recovery-codes', ['password' => 'OldPass!123'])
            ->assertOk()->assertJsonCount(8, 'data.recovery_codes');
        $auth->getJson('/api/v1/admin/profile/2fa/recovery-codes')->assertOk()
            ->assertJsonPath('data.remaining', 8)
            ->assertJsonPath('data.low', false);
    }

    /**
     * E9 — کدهای بازیابی: «نمایش یک‌بار» + «یک‌بارمصرف» از مسیرِ عمومی.
     *
     * این تست دو ویژگی امنیتی را قفل می‌کند:
     *  ۱) بدنهٔ plaintext کدها فقط در پاسخِ فعال‌سازی برمی‌گردد؛ نه در
     *     `/2fa/methods` و نه در `/profile`.
     *  ۲) مصرفِ یک کد از مسیرِ لاگین، آن را حذف می‌کند (`remaining` از ۸ به ۷)
     *     و تلاشِ دوباره با همان کد شکست می‌خورد.
     */
    public function test_recovery_codes_are_shown_once_and_single_use(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');
        $google2fa = new Google2FA;

        $secret = $auth->postJson('/api/v1/admin/profile/2fa/enable')->assertOk()->json('data.secret');
        $codes = $auth->postJson('/api/v1/admin/profile/2fa/enable', [
            'code' => $google2fa->getCurrentOtp($secret),
        ])->assertOk()->json('data.recovery_codes');
        $this->assertCount(8, $codes);

        // نمایشِ یک‌بار: هیچ endpoint دیگری نباید plaintext را لو بدهد.
        $methods = $auth->getJson('/api/v1/admin/profile/2fa/methods')->assertOk();
        $methods->assertJsonMissingPath('data.recovery_codes');
        $methods->assertJsonPath('data.recovery_codes_remaining', 8);

        $show = $auth->getJson('/api/v1/admin/profile')->assertOk();
        $show->assertJsonMissingPath('data.recovery_codes');

        foreach ($codes as $plain) {
            $this->assertStringNotContainsString($plain, (string) $methods->getContent());
            $this->assertStringNotContainsString($plain, (string) $show->getContent());
        }

        // مصرفِ یک‌باره از مسیر لاگین.
        $challenge = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'OldPass!123',
        ])->assertOk()->json('challenge');

        $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge' => $challenge, 'code' => $codes[0],
        ])->assertOk()->assertJsonStructure(['token']);

        // ⭐ مصرف واقعی روی DB ثبت شد: از ۸ به ۷.
        //
        // عمداً از `$user->fresh()` می‌خوانیم نه از توکنِ تازه: `actingAs` در
        // تست بر هدرِ Bearer اولویت دارد و همان نمونهٔ درون‌حافظهٔ کهنهٔ کاربر
        // را برمی‌گرداند — پس `/2fa/methods` مقدارِ قبل از مصرف را نشان می‌داد.
        $this->assertCount(7, $user->fresh()->recovery_codes);

        // همان کد دوباره باید رد شود.
        $challenge2 = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'OldPass!123',
        ])->assertOk()->json('challenge');
        $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge' => $challenge2, 'code' => $codes[0],
        ])->assertStatus(401);
    }

    /**
     * E9 — بازتولید کدهای بازیابی: نیازمند مدرک، ابطال قبلی، نمایش یک‌باره.
     *
     * هم مسیر تأیید با رمز عبور و هم مسیر تأیید با کد TOTP پوشش داده می‌شود؛
     * سپس ثابت می‌شود کدِ مجموعهٔ قبلی از مسیر لاگین رد و کدِ مجموعهٔ آخر کار
     * می‌کند (و همچنان یک‌بارمصرف است).
     */
    public function test_regenerate_recovery_codes_requires_proof_and_invalidates_old(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');
        $google2fa = new Google2FA;

        $secret = $auth->postJson('/api/v1/admin/profile/2fa/enable')->assertOk()->json('data.secret');
        $old = $auth->postJson('/api/v1/admin/profile/2fa/enable', [
            'code' => $google2fa->getCurrentOtp($secret),
        ])->assertOk()->json('data.recovery_codes');
        $this->assertCount(8, $old);

        // بدون مدرک معتبر رد می‌شود.
        $auth->postJson('/api/v1/admin/profile/2fa/recovery-codes')->assertStatus(422);
        $auth->postJson('/api/v1/admin/profile/2fa/recovery-codes', ['password' => 'Wrong!1234'])
            ->assertStatus(422);

        // مسیر تأیید با رمز عبور.
        $viaPassword = $auth->postJson('/api/v1/admin/profile/2fa/recovery-codes', ['password' => 'OldPass!123'])
            ->assertOk()
            ->assertJsonPath('message', 'کدهای بازیابی بازتولید شد. کدهای قبلی دیگر معتبر نیستند.');
        $new = $viaPassword->json('data.recovery_codes');
        $this->assertCount(8, $new);
        $this->assertEmpty(array_intersect($old, $new), 'کدهای تازه نباید با کدهای قبلی یکی باشند');

        // مسیر تأیید با کد TOTP — و ابطال مجموعهٔ قبلی.
        $latest = $auth->postJson('/api/v1/admin/profile/2fa/recovery-codes', [
            'code' => $google2fa->getCurrentOtp($secret),
        ])->assertOk()->json('data.recovery_codes');
        $this->assertCount(8, $latest);
        $this->assertEmpty(array_intersect($new, $latest));

        // نمایش یک‌بار: plaintext در هیچ endpoint دیگری لو نمی‌رود.
        $methods = $auth->getJson('/api/v1/admin/profile/2fa/methods')->assertOk()
            ->assertJsonPath('data.recovery_codes_remaining', 8);
        $show = $auth->getJson('/api/v1/admin/profile')->assertOk();
        foreach ($latest as $plain) {
            $this->assertStringNotContainsString($plain, (string) $methods->getContent());
            $this->assertStringNotContainsString($plain, (string) $show->getContent());
        }

        // کدِ مجموعهٔ قبلی (new) از مسیر لاگین رد می‌شود.
        $challenge = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'OldPass!123',
        ])->assertOk()->json('challenge');
        $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge' => $challenge, 'code' => $new[0],
        ])->assertStatus(401);

        // و کدِ مجموعهٔ آخر کار می‌کند.
        $challenge2 = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'OldPass!123',
        ])->assertOk()->json('challenge');
        $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge' => $challenge2, 'code' => $latest[0],
        ])->assertOk()->assertJsonStructure(['token']);
        $this->assertCount(7, $user->fresh()->recovery_codes);
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
