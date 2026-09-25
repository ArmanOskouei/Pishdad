<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** دسته UIUX — پروفایل: نشست‌ها + تأیید پیامکی (افزودنی). */
class ProfileExtrasTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مدیر تست',
            'email' => 'u'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
    }

    public function test_sessions_list_and_revoke_all_keeps_current(): void
    {
        $user = $this->user();
        $user->createToken('old-token');
        $current = $user->createToken('current')->plainTextToken;
        $auth = $this->withHeader('Authorization', 'Bearer '.$current);

        $auth->getJson('/api/v1/admin/profile/sessions')->assertOk()
            ->assertJsonPath('data.count', 2)
            ->assertJsonStructure(['data' => ['sessions' => [[
                'id', 'name', 'current', 'os', 'browser', 'device', 'ip',
                'created_at', 'last_used_at',
            ]]]]);

        $auth->postJson('/api/v1/admin/profile/sessions/revoke-all')->assertOk()
            ->assertJsonPath('message', 'همه نشست‌های دیگر باطل شد.')
            ->assertJsonPath('data.revoked', 1);

        $auth->getJson('/api/v1/admin/profile/sessions')->assertOk()
            ->assertJsonPath('data.count', 1);
    }

    /**
     * مسئله ۲: لاگین IP و user-agent را روی توکن ذخیره می‌کند و لیست نشست‌ها
     * آن را parseشده (os/browser/device) برمی‌گرداند.
     */
    public function test_login_stores_ip_and_user_agent_with_parsed_fields(): void
    {
        $user = User::query()->create([
            'name' => 'مدیر تست',
            'email' => 'login'.uniqid().'@example.com',
            'password' => Hash::make('Secret!1234'),
            'role' => 'admin',
        ]);
        $chromeWindows = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        $token = $this->withHeader('User-Agent', $chromeWindows)
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'Secret!1234',
            ])->assertOk()->json('token');
        $this->assertNotEmpty($token);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'ip_address' => '127.0.0.1',
        ]);

        $list = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'User-Agent' => $chromeWindows,
        ])->getJson('/api/v1/admin/profile/sessions')->assertOk()->json('data.sessions');

        $this->assertCount(1, $list);
        $session = $list[0];
        $this->assertSame('Windows', $session['os']);
        $this->assertSame('10.0', $session['os_version']);
        $this->assertSame('Chrome', $session['browser']);
        $this->assertNotEmpty($session['browser_version']);
        $this->assertSame('Chrome روی Windows', $session['device']);
        $this->assertSame('دسکتاپ', $session['device_type']);
        $this->assertSame('127.0.0.1', $session['ip']);
        $this->assertTrue($session['current']);
        $this->assertNotEmpty($session['created_at']);
        $this->assertNotEmpty($session['last_used_at']);
        $this->assertNotSame('api', $session['name']);
    }

    /** مسئله ۲: حذف تکی نشست (جز جاری) + ۴۲۲ برای جاری + ۴۰۴ برای ناموجود. */
    public function test_destroy_single_session(): void
    {
        $user = $this->user();
        $old = $user->createToken('old-token');
        $current = $user->createToken('current')->plainTextToken;
        $auth = $this->withHeader('Authorization', 'Bearer '.$current);

        $auth->deleteJson("/api/v1/admin/profile/sessions/{$old->accessToken->id}")
            ->assertOk()->assertJsonPath('message', 'نشست بسته شد.');
        $auth->getJson('/api/v1/admin/profile/sessions')->assertOk()
            ->assertJsonPath('data.count', 1);

        $auth->deleteJson('/api/v1/admin/profile/sessions/999999')->assertNotFound();

        $currentId = $user->tokens()->where('name', 'current')->value('id');
        $auth->deleteJson("/api/v1/admin/profile/sessions/{$currentId}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'نشست جاری را نمی‌توان با این روش بست؛ از «خروج» استفاده کنید.');
    }

    public function test_sms_request_verify_disconnect_flow(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $auth->postJson('/api/v1/admin/profile/sms/request', ['phone' => '123'])
            ->assertStatus(422);

        $code = $auth->postJson('/api/v1/admin/profile/sms/request', ['phone' => '09123456789'])
            ->assertOk()->json('data.debug_code');
        $this->assertNotEmpty($code);

        $auth->postJson('/api/v1/admin/profile/sms/verify', ['code' => '000000'])
            ->assertStatus(422)->assertJsonPath('message', 'کد تأیید صحیح نیست.');

        $auth->postJson('/api/v1/admin/profile/sms/verify', ['code' => $code])
            ->assertOk()->assertJsonPath('message', 'شماره موبایل تأیید شد.');

        $this->assertSame('09123456789', $user->fresh()->phone);

        $auth->getJson('/api/v1/admin/profile/2fa/methods')->assertOk()
            ->assertJsonPath('data.sms.enabled', true);

        $auth->deleteJson('/api/v1/admin/profile/sms')->assertOk()
            ->assertJsonPath('message', 'تأیید پیامکی قطع شد.');
    }
}
