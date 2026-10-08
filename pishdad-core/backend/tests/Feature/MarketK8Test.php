<?php

namespace Tests\Feature;

use App\Models\MarketLedgerEntry;
use App\Models\MarketLicense;
use App\Models\MarketNotice;
use App\Models\MarketOrder;
use App\Models\MarketPayout;
use App\Models\Plugin;
use App\Models\PublisherKey;
use App\Models\User;
use App\Services\Market\CoreMajor;
use App\Services\Market\MarketException;
use App\Services\Market\ReviewService;
use App\Services\Plugins\PluginSignatureVerifier;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use ZipArchive;

/**
 * K8 — marketplace: trust store, review, yank, install, orders/ledger/payouts.
 */
class MarketK8Test extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $this->seed(RolesPermissionsSeeder::class);
        $user = User::query()->create([
            'name' => 'Owner', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Owner!1234'), 'role' => 'admin',
        ]);
        $user->assignRole('owner');

        return $user->fresh();
    }

    private function operator(): User
    {
        return User::query()->create([
            'name' => 'Operator', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Operator!1234'), 'role' => 'operator',
        ]);
    }

    /** @return array{public: string, secret: string, fingerprint: string} */
    private function keypair(): array
    {
        $kp = sodium_crypto_sign_keypair();
        $raw = sodium_crypto_sign_publickey($kp);

        return [
            'public' => base64_encode($raw),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($kp)),
            'fingerprint' => hash('sha256', $raw),
        ];
    }

    private function registerPublisher(string $slug, ?array $keys = null): array
    {
        $keys ??= $this->keypair();
        $owner = $this->owner();

        $res = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/market/publishers', [
            'name' => 'Pub '.$slug, 'slug' => $slug, 'public_key' => $keys['public'],
        ]);
        $res->assertCreated();

        return [$keys, $owner];
    }

    private function zipFor(array $manifest, string $secret): string
    {
        $manifest['signature'] = app(PluginSignatureVerifier::class)->sign($manifest, $secret);
        $path = tempnam(sys_get_temp_dir(), 'mkt').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->close();

        return $path;
    }

    private function upload(string $path, User $user)
    {
        return $this->actingAs($user, 'sanctum')->post('/api/v1/market/submissions', [
            'file' => new UploadedFile($path, 'pkg.zip', 'application/zip', null, true),
        ]);
    }

    public function test_publisher_registration_and_public_trust_store(): void
    {
        $keys = $this->keypair();
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/market/publishers', [
            'name' => 'Acme', 'slug' => 'acme', 'public_key' => 'not-a-key',
        ])->assertStatus(422);

        $res = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/market/publishers', [
            'name' => 'Acme', 'slug' => 'acme', 'public_key' => $keys['public'],
        ]);
        $res->assertCreated();
        $this->assertSame($keys['fingerprint'], $res->json('data.key_fingerprint'));

        // Duplicate key under another slug is rejected.
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/market/publishers', [
            'name' => 'Acme 2', 'slug' => 'acme-2', 'public_key' => $keys['public'],
        ])->assertStatus(422);

        // Public trust store (no auth) exposes the fingerprint, no secrets.
        $store = $this->getJson('/api/v1/market/publishers')->assertOk()->json('data');
        $this->assertSame('acme', $store[0]['slug']);
        $this->assertSame($keys['fingerprint'], $store[0]['key_fingerprint']);
        $this->assertArrayNotHasKey('public_key', $store[0]);
    }

    public function test_submit_rejects_package_with_errors_422(): void
    {
        [$keys, $owner] = $this->registerPublisher('acme');

        // K1.7-A: unsigned package has >= 1 error -> 422, never installed.
        $path = tempnam(sys_get_temp_dir(), 'mkt').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode([
            'slug' => 'bad-pkg', 'name' => 'Bad',
            'publisher' => ['key_id' => $keys['fingerprint']],
        ]));
        $zip->close();

        $res = $this->upload($path, $owner);
        $res->assertStatus(422);
        $this->assertNotEmpty($res->json('details.errors'));
        $this->assertDatabaseMissing('plugins', ['slug' => 'bad-pkg']);
    }

    public function test_submit_approve_install_free_plugin(): void
    {
        [$keys, $owner] = $this->registerPublisher('acme');
        $operator = $this->operator();

        $path = $this->zipFor([
            'slug' => 'free-pkg', 'name' => 'Free', 'version' => '1.0.0',
            'publisher' => ['key_id' => $keys['fingerprint']],
        ], $keys['secret']);

        $res = $this->upload($path, $owner);
        $res->assertCreated();
        $this->assertSame(Plugin::REVIEW_PENDING, $res->json('data.review_status'));
        $this->assertSame(Plugin::SOURCE_MARKET, $res->json('data.source'));
        $pluginId = $res->json('data.id');

        // Non-operator cannot approve.
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/market/plugins/{$pluginId}/approve")
            ->assertForbidden();

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/market/plugins/{$pluginId}/approve", ['note' => 'looks good'])
            ->assertOk();
        $this->assertSame(Plugin::REVIEW_APPROVED, Plugin::find($pluginId)->review_status);

        // K8.4 — install without money creates a license (K0.9: current major).
        $install = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/market/plugins/{$pluginId}/install");
        $install->assertCreated();
        $this->assertSame(CoreMajor::current(), (int) $install->json('data.valid_until_major'));

        $license = MarketLicense::query()->firstOrFail();
        $this->assertTrue($license->coversCore());
    }

    public function test_reject_blocks_install(): void
    {
        [$keys, $owner] = $this->registerPublisher('acme');
        $operator = $this->operator();

        $path = $this->zipFor([
            'slug' => 'rej-pkg', 'name' => 'Rej', 'version' => '1.0.0',
            'publisher' => ['key_id' => $keys['fingerprint']],
        ], $keys['secret']);
        $pluginId = $this->upload($path, $owner)->assertCreated()->json('data.id');

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/market/plugins/{$pluginId}/reject", ['note' => 'unsafe'])
            ->assertOk();
        $this->assertSame(Plugin::REVIEW_REJECTED, Plugin::find($pluginId)->review_status);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/market/plugins/{$pluginId}/install")
            ->assertStatus(422);
    }

    public function test_yank_blocks_fresh_install_keeps_existing(): void
    {
        [$keys, $owner] = $this->registerPublisher('acme');
        $operator = $this->operator();

        $path = $this->zipFor([
            'slug' => 'yank-pkg', 'name' => 'Yank', 'version' => '2.0.0',
            'publisher' => ['key_id' => $keys['fingerprint']],
        ], $keys['secret']);
        $pluginId = $this->upload($path, $owner)->assertCreated()->json('data.id');
        $this->actingAs($operator, 'sanctum')->postJson("/api/v1/market/plugins/{$pluginId}/approve")->assertOk();

        // Existing install before the yank.
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/market/plugins/{$pluginId}/install")->assertCreated();

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/market/plugins/{$pluginId}/yank", ['reason' => 'CVE-2026-0001'])
            ->assertOk();
        $this->assertTrue(Plugin::find($pluginId)->yanked);

        // Security notice emitted + publicly visible.
        $this->assertDatabaseHas('market_notices', ['plugin_id' => $pluginId, 'type' => MarketNotice::TYPE_YANK]);
        $this->getJson('/api/v1/market/notices')->assertOk();

        // Fresh installs blocked (410), existing license keeps working.
        $buyer = User::query()->create([
            'name' => 'B', 'email' => uniqid().'@example.com',
            'password' => Hash::make('B!123456'), 'role' => 'user',
        ]);
        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/plugins/{$pluginId}/install")
            ->assertStatus(410);
        $this->assertTrue(MarketLicense::query()->firstOrFail()->coversCore());

        // K0.7 — yanked rows are non-deletable at the market layer.
        try {
            app(ReviewService::class)->ensureDeletable(Plugin::find($pluginId));
            $this->fail('expected MarketException');
        } catch (MarketException $e) {
            $this->assertSame(409, $e->status);
        }

        // Yanked plugin disappears from the public listing but the record stays.
        $this->assertDatabaseHas('plugins', ['id' => $pluginId]);
        $list = $this->getJson('/api/v1/market/plugins')->assertOk()->json('data.data');
        $this->assertEmpty(array_filter($list, fn ($p) => $p['slug'] === 'yank-pkg'));
    }

    public function test_paid_order_ledger_and_manual_payout(): void
    {
        [$keys, $owner] = $this->registerPublisher('acme');
        $operator = $this->operator();

        $path = $this->zipFor([
            'slug' => 'paid-pkg', 'name' => 'Paid', 'version' => '1.0.0',
            'price' => 100000, 'currency' => 'IRT',
            'publisher' => ['key_id' => $keys['fingerprint']],
        ], $keys['secret']);
        $pluginId = $this->upload($path, $owner)->assertCreated()->json('data.id');
        $this->actingAs($operator, 'sanctum')->postJson("/api/v1/market/plugins/{$pluginId}/approve")->assertOk();

        // Priced plugin: install without order -> 402 payment required.
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/market/plugins/{$pluginId}/install")
            ->assertStatus(402);

        $orderId = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/market/orders', ['plugin_id' => $pluginId])
            ->assertCreated()->json('data.id');
        $this->assertSame(100000, MarketOrder::find($orderId)->amount);

        // Manual payment confirmation (no gateway).
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/market/orders/{$orderId}/pay")->assertOk();
        $this->assertTrue(MarketOrder::find($orderId)->isPaid());

        $kinds = MarketLedgerEntry::query()->where('order_id', $orderId)->pluck('amount', 'kind')->all();
        $this->assertSame(100000, (int) $kinds[MarketLedgerEntry::KIND_SALE]);
        $this->assertSame(10000, (int) $kinds[MarketLedgerEntry::KIND_PLATFORM_FEE]);
        $this->assertSame(90000, (int) $kinds[MarketLedgerEntry::KIND_PUBLISHER_SHARE]);
        $this->assertTrue(MarketLicense::query()->where('order_id', $orderId)->firstOrFail()->coversCore());

        // Install now works off the paid license.
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/market/plugins/{$pluginId}/install")
            ->assertCreated();

        // Manual payout bounded by unsettled share.
        $keyId = PublisherKey::query()->where('slug', 'acme')->value('id');
        $this->actingAs($operator, 'sanctum')->postJson('/api/v1/market/payouts', [
            'publisher_key_id' => $keyId, 'amount' => 999999,
        ])->assertStatus(422);

        $payoutId = $this->actingAs($operator, 'sanctum')->postJson('/api/v1/market/payouts', [
            'publisher_key_id' => $keyId, 'amount' => 90000, 'note' => 'manual transfer',
        ])->assertCreated()->json('data.id');

        $this->actingAs($operator, 'sanctum')->postJson("/api/v1/market/payouts/{$payoutId}/pay")->assertOk();
        $this->assertTrue(MarketPayout::find($payoutId)->isPaid());
        $this->assertDatabaseHas('market_ledger_entries', [
            'kind' => MarketLedgerEntry::KIND_PAYOUT, 'amount' => 90000,
        ]);

        $this->actingAs($operator, 'sanctum')->getJson('/api/v1/market/ledger')->assertOk();
    }

    public function test_license_valid_until_end_of_core_major(): void
    {
        $major = CoreMajor::current();
        $license = new MarketLicense(['valid_until_major' => $major, 'active' => true]);

        // Minor/patch free: same major, any minor/patch.
        $this->assertTrue($license->coversCore($major.'.0.0'));
        $this->assertTrue($license->coversCore($major.'.9.7'));

        // Next major ends validity.
        $this->assertFalse($license->coversCore(($major + 1).'.0.0'));

        $license->active = false;
        $this->assertFalse($license->coversCore($major.'.0.0'));
    }
}
