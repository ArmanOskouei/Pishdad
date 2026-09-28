<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Pishdad\Plugins\Central\Models\Client;
use Pishdad\Plugins\Central\Models\CoreVersion;
use Tests\TestCase;

/** تسک ۵.۶ — DoD: رول‌اوت پلکانی با confirm + رکورد per-install + rollback. */
class UpdatesTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::query()->create([
            'name' => 'اپراتور', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Operator!1'), 'role' => 'operator',
        ]);
    }

    private function client(string $version = '1.0.0'): Client
    {
        return Client::query()->create([
            'name' => 'مشتری '.uniqid(), 'tier' => 'budget', 'status' => 'active',
            'site_id' => 'site-'.uniqid(), 'current_version' => $version,
        ]);
    }

    public function test_registry_reports_per_install_version(): void
    {
        $auth = $this->actingAs($this->operator(), 'sanctum');
        CoreVersion::query()->create(['version' => '1.0.0']);
        CoreVersion::query()->create(['version' => '2.0.0', 'is_latest' => true]);
        $this->client('1.0.0');
        $this->client('2.0.0');

        $res = $auth->getJson('/api/v1/p/central/updates')->assertOk()->json('data');
        $this->assertEquals('2.0.0', $res['latest']['version']);
        $this->assertTrue(collect($res['installs'])->firstWhere('current_version', '1.0.0')['update_available']);
        $this->assertFalse(collect($res['installs'])->firstWhere('current_version', '2.0.0')['update_available']);
    }

    public function test_rollout_requires_confirm_and_batches_installs(): void
    {
        $auth = $this->actingAs($this->operator(), 'sanctum');
        CoreVersion::query()->create(['version' => '2.0.0', 'is_latest' => true]);
        $this->client('1.0.0');
        $this->client('1.0.0');
        $this->client('1.0.0');

        $auth->postJson('/api/v1/p/central/updates/rollout', ['version' => '2.0.0'])
            ->assertStatus(422);

        $res = $auth->postJson('/api/v1/p/central/updates/rollout', [
            'version' => '2.0.0', 'batch_size' => 2, 'confirm' => true,
        ])->assertCreated()->json('data');

        $this->assertCount(2, $res['batches']); // ۳ نصب در بچ‌های ۲تایی
        $this->assertEquals('1.0.0', $res['batches'][0][0]['previous_version']);
        $this->assertEquals(0, $res['batches'][0][0]['batch_index']);
        $this->assertEquals(1, $res['batches'][1][0]['batch_index']);
    }

    public function test_rollback_restores_previous_version(): void
    {
        $auth = $this->actingAs($this->operator(), 'sanctum');
        CoreVersion::query()->create(['version' => '2.0.0', 'is_latest' => true]);
        $client = $this->client('1.0.0');

        $auth->postJson('/api/v1/p/central/updates/rollout', [
            'version' => '2.0.0', 'confirm' => true,
        ])->assertCreated();

        // شبیه‌سازی اعمال روی نصب.
        $client->forceFill(['current_version' => '2.0.0'])->save();

        $auth->postJson("/api/v1/p/central/updates/installs/{$client->id}/rollback")
            ->assertOk()->assertJsonPath('data.current_version', '1.0.0');

        $this->assertDatabaseHas('central_update_rollout_items', [
            'client_id' => $client->id, 'status' => 'rolled_back',
        ]);

        // بازگشت دوم ممکن نیست.
        $auth->postJson("/api/v1/p/central/updates/installs/{$client->id}/rollback")
            ->assertStatus(422);
    }
}
