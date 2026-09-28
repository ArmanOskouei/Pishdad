<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Pishdad\Plugins\Central\Models\Client;
use Tests\TestCase;

/** دسته ۱ (برابری UI/UX) — جستجوی متنی مشتریان (نام/دامنه/site_id/ایمیل مالک). */
class CentralClientSearchTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::query()->create([
            'name' => 'اپراتور', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'operator',
        ]);
    }

    public function test_search_matches_name_domain_site_and_owner_email(): void
    {
        $op = $this->operator();
        $owner = User::query()->create([
            'name' => 'مالک', 'email' => 'owner-search@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'admin',
        ]);
        Client::query()->create([
            'name' => 'فروشگاه نمونه', 'domain' => 'shop.example.ir',
            'tier' => 'budget', 'status' => 'active',
            'owner_user_id' => $owner->id, 'site_id' => 'site-alpha-1',
        ]);
        Client::query()->create([
            'name' => 'کلینیک سپید', 'tier' => 'standard', 'status' => 'active',
            'site_id' => 'site-beta-2',
        ]);
        $auth = $this->actingAs($op, 'sanctum');

        $auth->getJson('/api/v1/p/central/clients?search='.urlencode('فروشگاه'))
            ->assertOk()->assertJsonPath('data.total', 1);
        $auth->getJson('/api/v1/p/central/clients?search=shop.example')
            ->assertOk()->assertJsonPath('data.total', 1);
        $auth->getJson('/api/v1/p/central/clients?search=site-beta-2')
            ->assertOk()->assertJsonPath('data.total', 1);
        $auth->getJson('/api/v1/p/central/clients?search=owner-search@example.com')
            ->assertOk()->assertJsonPath('data.total', 1);
        // ترکیب با فیلتر موجود tier.
        $auth->getJson('/api/v1/p/central/clients?search='.urlencode('کلینیک').'&tier=budget')
            ->assertOk()->assertJsonPath('data.total', 0);
    }
}
