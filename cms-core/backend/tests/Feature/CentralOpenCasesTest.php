<?php

namespace Tests\Feature;

use App\Models\Central\ChabokanDeployment;
use App\Models\Central\Client;
use App\Models\Central\Invoice;
use App\Models\Central\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** دسته ۱ (برابری UI/UX) — پرونده‌های باز قابل اکشن داشبورد مرکزی. */
class CentralOpenCasesTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::query()->create([
            'name' => 'اپراتور', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'operator',
        ]);
    }

    public function test_open_cases_returns_actionable_items_with_deep_links(): void
    {
        $op = $this->operator();
        $auth = $this->actingAs($op, 'sanctum');

        // کیف پول زیر آستانه.
        config()->set('chabokan.wallet_balance', 1000);
        config()->set('chabokan.low_balance_threshold', 5000);

        // تعلیق نزدیک حذف (۶ روز از ۷ روز گذشته).
        $client = Client::query()->create([
            'name' => 'کلینیک سپید', 'tier' => 'standard', 'status' => 'suspended',
            'site_id' => 'site-'.uniqid(),
        ]);
        Subscription::query()->create([
            'client_id' => $client->id, 'site_id' => $client->site_id,
            'status' => Subscription::SUSPENDED,
            'due_at' => now()->subDays(6), 'suspended_at' => now()->subDays(6),
        ]);

        // دیپلوی ناموفق.
        ChabokanDeployment::query()->create([
            'service' => 'web', 'status' => ChabokanDeployment::FAILED,
        ]);

        // فاکتور سررسیدگذشته.
        Invoice::query()->create([
            'client_id' => $client->id, 'amount' => 50000,
            'status' => Invoice::PENDING, 'due_at' => now()->subDay(),
        ]);

        $res = $auth->getJson('/api/central/dashboard/open-cases');

        $res->assertOk();
        $kinds = collect($res->json('data'))->pluck('kind')->all();
        foreach (['wallet', 'suspension', 'deploy', 'overdue'] as $expected) {
            $this->assertContains($expected, $kinds, "case «{$expected}» نیست");
        }
        // هر مورد لینک عمیق اقدام دارد.
        foreach ($res->json('data') as $case) {
            $this->assertNotEmpty($case['action']);
            $this->assertNotEmpty($case['action_label']);
            $this->assertStringStartsWith('/central/', $case['action']);
        }
    }

    public function test_open_cases_empty_when_all_healthy(): void
    {
        $op = $this->operator();

        config()->set('chabokan.wallet_balance', 999999999);
        config()->set('chabokan.low_balance_threshold', 1000);
        config()->set('chabokan.stub_statuses', []);

        $this->actingAs($op, 'sanctum')->getJson('/api/central/dashboard/open-cases')
            ->assertOk()->assertJsonPath('data', []);
    }
}
