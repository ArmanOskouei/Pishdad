<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Pishdad\Plugins\Central\Models\Client;
use Pishdad\Plugins\Central\Models\CoreVersion;
use Pishdad\Plugins\Central\Models\UpdateRolloutItem;
use Tests\TestCase;

/** دسته UIUX — آپدیت‌ها: رول‌اوت جاری + توقف/ادامه + وضعیت per-row. */
class UpdatesExtrasTest extends TestCase
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

    public function test_current_rollout_progress_and_pause_resume(): void
    {
        $auth = $this->actingAs($this->operator(), 'sanctum');
        CoreVersion::query()->create(['version' => '2.0.0', 'is_latest' => true]);
        $this->client('1.0.0');
        $this->client('1.0.0');

        $auth->getJson('/api/v1/p/central/updates/rollouts/current')->assertOk()
            ->assertJsonPath('data.rollout', null);

        $rolloutId = $auth->postJson('/api/v1/p/central/updates/rollout', [
            'version' => '2.0.0', 'confirm' => true,
        ])->assertCreated()->json('data.rollout.id');

        $auth->getJson('/api/v1/p/central/updates/rollouts/current')->assertOk()
            ->assertJsonPath('data.rollout.id', $rolloutId)
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.pending', 2)
            ->assertJsonPath('data.progress', 0);

        $auth->postJson("/api/v1/p/central/updates/rollouts/{$rolloutId}/pause")->assertOk()
            ->assertJsonPath('message', 'رول‌اوت متوقف شد.');
        $auth->postJson("/api/v1/p/central/updates/rollouts/{$rolloutId}/pause")->assertStatus(422);

        $auth->postJson("/api/v1/p/central/updates/rollouts/{$rolloutId}/resume")->assertOk()
            ->assertJsonPath('message', 'رول‌اوت ادامه یافت.');
        $auth->postJson("/api/v1/p/central/updates/rollouts/{$rolloutId}/resume")->assertStatus(422);
    }

    public function test_index_exposes_last_rollout_status(): void
    {
        $auth = $this->actingAs($this->operator(), 'sanctum');
        CoreVersion::query()->create(['version' => '2.0.0', 'is_latest' => true]);
        $client = $this->client('1.0.0');

        $auth->postJson('/api/v1/p/central/updates/rollout', [
            'version' => '2.0.0', 'confirm' => true,
        ])->assertCreated();

        UpdateRolloutItem::query()->where('client_id', $client->id)
            ->update(['status' => 'failed']);

        $res = $auth->getJson('/api/v1/p/central/updates')->assertOk()->json('data');
        $row = collect($res['installs'])->firstWhere('client_id', $client->id);
        $this->assertSame('failed', $row['last_rollout_status']);
    }
}
