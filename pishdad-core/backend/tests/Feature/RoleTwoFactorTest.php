<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * WF-M8 — اجبار ۲FA برای نقش‌های منتخب.
 *
 * پوشش: ماندگاریِ فلگ روی جدولِ roles + قراردادِ پاسخِ ورود در دو حالت
 * («نقش ۲FA-اجباری بدونِ ۲FA» ⇒ توکن + requires_2fa_setup، و «۲FA فعال» ⇒ چالش).
 */
class RoleTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $this->seed(RolesPermissionsSeeder::class);
        $user = User::query()->create([
            'name' => 'مالک', 'email' => 'owner-'.uniqid().'@example.com',
            'password' => Hash::make('Owner!1234'), 'role' => 'admin',
        ]);
        $user->assignRole('owner');

        return $user->fresh();
    }

    private function user(string $email, array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'مدیر', 'email' => $email,
            'password' => Hash::make('Secret!123'), 'role' => 'admin',
        ], $overrides));
    }

    public function test_role_requires_2fa_flag_persists_via_api(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $created = $auth->postJson('/api/v1/admin/roles', [
            'name' => 'secure', 'permissions' => [], 'requires_2fa' => true,
        ])->assertCreated()->json('data');

        $this->assertTrue($created['requires_2fa']);
        $this->assertDatabaseHas('roles', ['name' => 'secure', 'requires_2fa' => true]);

        $roleId = $created['id'];

        $auth->putJson("/api/v1/admin/roles/{$roleId}", ['requires_2fa' => false])
            ->assertOk()
            ->assertJsonPath('data.requires_2fa', false);
        $this->assertDatabaseHas('roles', ['name' => 'secure', 'requires_2fa' => false]);

        // به‌روزرسانیِ فقط-دسترسی نباید فلگ را پاک کند.
        $auth->putJson("/api/v1/admin/roles/{$roleId}", ['requires_2fa' => true])->assertOk();
        $auth->putJson("/api/v1/admin/roles/{$roleId}", ['permissions' => []])->assertOk();
        $this->assertTrue((bool) Role::query()->where('name', 'secure')->value('requires_2fa'));
    }

    public function test_login_with_required_role_and_no_2fa_returns_setup_flag(): void
    {
        $role = Role::create(['name' => 'secure-role', 'guard_name' => 'web', 'requires_2fa' => true]);
        $this->user('secure@example.com')->assignRole($role);

        $res = $this->postJson('/api/v1/auth/login', [
            'email' => 'secure@example.com', 'password' => 'Secret!123',
        ]);

        $res->assertOk()
            ->assertJsonPath('requires_2fa_setup', true)
            ->assertJsonPath('two_factor_setup_required', true)
            ->assertJsonPath('two_factor_required', false);
        // مسدود نمی‌شود: توکن صادر می‌شود تا کاربر به پروفایل برود.
        $this->assertNotEmpty($res->json('token'));
    }

    public function test_login_with_required_role_and_2fa_enabled_returns_challenge(): void
    {
        $role = Role::create(['name' => 'secure-role-2fa', 'guard_name' => 'web', 'requires_2fa' => true]);
        $this->user('secure2@example.com', [
            'google2fa_secret' => 'JBSWY3DPEHPK3PXP', 'google2fa_enabled' => true,
        ])->assignRole($role);

        $res = $this->postJson('/api/v1/auth/login', [
            'email' => 'secure2@example.com', 'password' => 'Secret!123',
        ]);

        $res->assertOk()
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonMissingPath('requires_2fa_setup');
        $this->assertNull($res->json('token'));
        $this->assertNotEmpty($res->json('challenge'));
    }

    public function test_role_without_flag_does_not_force_setup(): void
    {
        $role = Role::create(['name' => 'plain-role', 'guard_name' => 'web']);
        $this->user('plain@example.com')->assignRole($role);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'plain@example.com', 'password' => 'Secret!123',
        ])->assertOk()
            ->assertJsonPath('requires_2fa_setup', false)
            ->assertJsonPath('two_factor_required', false);
    }
}
