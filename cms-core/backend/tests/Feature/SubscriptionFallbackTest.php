<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * L-B12 — مسیرهای legacy اشتراک وقتی افزونهٔ «اشتراک» نصب/فعال نیست.
 *
 * قبلاً به افزونهٔ غایب forward می‌شدند و ۴۰۴ می‌دادند؛ UI قدیمی این‌ها را در
 * هر صفحهٔ پنل می‌خواند ⇒ نویزِ کنسول. پاسخِ درست «اشتراکی وجود ندارد» است.
 */
class SubscriptionFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'name' => 'Manager', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'admin',
        ]);
    }

    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/subscription')->assertStatus(401);
    }

    public function test_show_returns_null_when_no_subscription_plugin(): void
    {
        Sanctum::actingAs($this->user());

        $this->getJson('/api/v1/admin/subscription')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_invoices_and_plans_return_empty_lists(): void
    {
        Sanctum::actingAs($this->user());

        $this->getJson('/api/v1/admin/subscription/invoices')
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->getJson('/api/v1/admin/subscription/plans')
            ->assertOk()
            ->assertJsonPath('data', []);
    }
}
