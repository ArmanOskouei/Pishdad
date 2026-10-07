<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * دسته UIUX — آواتارِ مدیران در payload فهرست.
 *
 * ⚠️ این تنها تستِ باقی‌ماندهٔ این فایل است. تستِ کنارش (صدورِ secret برای ثبتِ
 * کلیدِ سخت‌افزاریِ اپراتور با QR) روی مسیرهای افزونهٔ «پنل مرکزی» بود و با
 * حذفِ آن افزونه رفت.
 */
class OperatorHardwareSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_payload_includes_avatar_keys(): void
    {
        $this->seed(RolesPermissionsSeeder::class);
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
