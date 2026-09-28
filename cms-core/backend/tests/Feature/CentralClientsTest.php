<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Pishdad\Plugins\Central\Models\Backup;
use Pishdad\Plugins\Central\Models\Client;
use Tests\TestCase;

/** تسک ۱.۴ — DoD: CRUD + ارتقا تیر + توکن یکبارمصرف + تعلیق + بکاپ Stub. */
class CentralClientsTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::query()->create([
            'name' => 'اپراتور', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'operator',
        ]);
    }

    private function client(array $overrides = []): Client
    {
        return Client::query()->create(array_merge([
            'name' => 'مشتری '.uniqid(), 'tier' => 'budget', 'status' => 'active',
            'site_id' => 'site-'.uniqid(),
        ], $overrides));
    }

    public function test_store_creates_client_with_subscription(): void
    {
        $op = $this->operator();

        $res = $this->actingAs($op, 'sanctum')->postJson('/api/v1/p/central/clients', [
            'name' => 'شرکت آلفا',
            'domain' => 'alpha.example.com',
        ]);

        $res->assertCreated()->assertJsonPath('message', 'مشتری ساخته شد.');
        $this->assertNotNull($res->json('data.subscription.id'));
    }

    public function test_index_filters_and_detail(): void
    {
        $op = $this->operator();
        $auth = $this->actingAs($op, 'sanctum');

        $this->client(['tier' => 'budget', 'status' => 'active']);
        $this->client(['tier' => 'enterprise', 'status' => 'suspended']);
        $target = $this->client(['tier' => 'standard', 'status' => 'active']);

        $auth->getJson('/api/v1/p/central/clients?tier=standard')->assertOk()->assertJsonPath('data.total', 1);
        $auth->getJson('/api/v1/p/central/clients?status=suspended')->assertOk()->assertJsonPath('data.total', 1);

        $detail = $auth->getJson("/api/v1/p/central/clients/{$target->id}");
        $detail->assertOk()->assertJsonPath('data.health_status', 'healthy');
    }

    public function test_upgrade_tier_allows_only_next_step(): void
    {
        $op = $this->operator();
        $auth = $this->actingAs($op, 'sanctum');
        $client = $this->client(['tier' => 'budget']);

        // پرش دو پله ممنوع.
        $auth->postJson("/api/v1/p/central/clients/{$client->id}/upgrade-tier", ['tier' => 'enterprise'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'ارتقا فقط یک پله رو به جلو مجاز است (Budget→Standard→Enterprise).');

        // بازگشت ممنوع.
        $auth->postJson("/api/v1/p/central/clients/{$client->id}/upgrade-tier", ['tier' => 'budget'])
            ->assertStatus(422);

        // یک پله مجاز + لاگ.
        $auth->postJson("/api/v1/p/central/clients/{$client->id}/upgrade-tier", ['tier' => 'standard'])
            ->assertOk()->assertJsonPath('message', 'تیر مشتری ارتقا یافت.');
        $this->assertSame('standard', $client->fresh()->tier);
        $this->assertDatabaseHas('central_billing_events', [
            'client_id' => $client->id, 'type' => 'tier_upgrade',
        ]);
    }

    public function test_support_token_is_single_use_with_ttl(): void
    {
        $op = $this->operator();
        $auth = $this->actingAs($op, 'sanctum');
        $client = $this->client();

        $issued = $auth->postJson("/api/v1/p/central/clients/{$client->id}/support-token")
            ->assertCreated();
        $plain = $issued->json('data.token');
        $this->assertNotEmpty($plain);
        // هش ذخیره شده، نه متن خام.
        $this->assertDatabaseMissing('central_support_tokens', ['token_hash' => $plain]);

        // مصرف اول موفق.
        $auth->postJson("/api/v1/p/central/clients/{$client->id}/support-token/consume", ['token' => $plain])
            ->assertOk()->assertJsonPath('message', 'توکن پذیرفته شد.');

        // مصرف دوم ناموفق (410).
        $auth->postJson("/api/v1/p/central/clients/{$client->id}/support-token/consume", ['token' => $plain])
            ->assertStatus(410)
            ->assertJsonPath('message', 'توکن نامعتبر، منقضی یا قبلاً مصرف شده است.');

        // توکن غریبه هم نامعتبر.
        $auth->postJson("/api/v1/p/central/clients/{$client->id}/support-token/consume", ['token' => 'sup_wrong'])
            ->assertStatus(410);
    }

    public function test_support_token_expires(): void
    {
        $op = $this->operator();
        $auth = $this->actingAs($op, 'sanctum');
        $client = $this->client();

        $plain = $auth->postJson("/api/v1/p/central/clients/{$client->id}/support-token")->json('data.token');

        $this->travel(16)->minutes();

        $auth->postJson("/api/v1/p/central/clients/{$client->id}/support-token/consume", ['token' => $plain])
            ->assertStatus(410);
    }

    public function test_suspend_resume_delete_flow(): void
    {
        $op = $this->operator();
        $auth = $this->actingAs($op, 'sanctum');
        $client = $this->client();

        $auth->postJson("/api/v1/p/central/clients/{$client->id}/suspend")
            ->assertOk()->assertJsonPath('message', 'مشتری معلق شد.');
        $this->assertSame('suspended', $client->fresh()->status);

        $auth->postJson("/api/v1/p/central/clients/{$client->id}/resume")
            ->assertOk()->assertJsonPath('message', 'مشتری بازگشایی شد.');
        $this->assertSame('active', $client->fresh()->status);

        $auth->postJson("/api/v1/p/central/clients/{$client->id}/delete")
            ->assertOk()->assertJsonPath('message', 'مشتری حذف شد.');
        $this->assertSame('deleted', $client->fresh()->status);
    }

    public function test_backup_and_restore_stub_records(): void
    {
        $op = $this->operator();
        $auth = $this->actingAs($op, 'sanctum');
        $client = $this->client();

        $backup = $auth->postJson("/api/v1/p/central/clients/{$client->id}/backup")
            ->assertCreated();
        $backupId = $backup->json('data.id');
        $this->assertStringStartsWith('stub://', $backup->json('data.path'));

        $auth->getJson("/api/v1/p/central/clients/{$client->id}/backups")
            ->assertOk()->assertJsonPath('data.total', 1);

        $auth->postJson("/api/v1/p/central/clients/{$client->id}/restore", ['backup_id' => $backupId])
            ->assertOk()->assertJsonPath('message', 'ریستور آغاز شد (Stub).');
        $this->assertSame('restoring', Backup::query()->find($backupId)->status);
    }

    public function test_superadmin_is_never_listed_but_can_operate_central(): void
    {
        $super = User::query()->create([
            'name' => 'سوپرادمین', 'email' => 'super@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'super-admin',
            'is_super_admin' => true, 'is_hidden' => true,
        ]);

        // لیست معمولی کاربران سوپرادمین مخفی را ندارد.
        $this->assertSame(0, User::query()->visible()->where('is_super_admin', true)->count());

        // اما گیت مرکزی را پاس می‌کند.
        $this->actingAs($super, 'sanctum')->getJson('/api/v1/p/central/plans')->assertOk();
    }
}
