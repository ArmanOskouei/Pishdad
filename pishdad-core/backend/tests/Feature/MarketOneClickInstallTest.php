<?php

namespace Tests\Feature;

use App\Models\MarketLicense;
use App\Models\MarketOrder;
use App\Models\Plugin;
use App\Services\Market\CoreMajor;
use App\Services\Plugins\PluginSignatureVerifier;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * WF-C5 — نصب یک‌کلیکی از فروشگاه.
 *
 * همان دنباله‌ای که دکمهٔ «نصب» فرانت صدا می‌زند روی HTTP سنجیده می‌شود:
 * رایگان ⇒ checkout + تأیید + install؛ پولی ⇒ install بدون لایسنس ۴۰۲ و
 * پیش از هر سفارشی رد می‌شود. هیچ درگاه/کد جدیدی اینجا ساخته نشده — همه
 * همان سرویس‌های K8.4/K8.5 هستند.
 */
class MarketOneClickInstallTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): \App\Models\User
    {
        $this->seed(RolesPermissionsSeeder::class);
        $user = \App\Models\User::query()->create([
            'name' => 'Owner', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Owner!1234'), 'role' => 'admin',
        ]);
        $user->assignRole('owner');

        return $user->fresh();
    }

    private function operator(): \App\Models\User
    {
        return \App\Models\User::query()->create([
            'name' => 'Operator', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Operator!1234'), 'role' => 'operator',
        ]);
    }

    private function buyer(): \App\Models\User
    {
        return \App\Models\User::query()->create([
            'name' => 'Buyer', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Buyer!1234'), 'role' => 'user',
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

    private function zipFor(array $manifest, string $secret): string
    {
        $manifest['signature'] = app(PluginSignatureVerifier::class)->sign($manifest, $secret);
        $path = tempnam(sys_get_temp_dir(), 'mkt').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('Laravel/src/Hello.php', "<?php\n// WF-C5 test artifact\nreturn 'hello';\n");
        $zip->close();

        return $path;
    }

    /** @return array{0: Plugin, 1: \App\Models\User} */
    private function approvedPlugin(int $price = 0): array
    {
        $keys = $this->keypair();
        $publisher = $this->owner();
        $operator = $this->operator();

        $this->actingAs($publisher, 'sanctum')->postJson('/api/v1/market/publishers', [
            'name' => 'Pub', 'slug' => 'pub', 'public_key' => $keys['public'],
        ])->assertCreated();

        $slug = 'oneclick-'.uniqid();
        $manifest = [
            'slug' => $slug, 'name' => 'One Click Widget', 'version' => '1.0.0',
            'price' => $price, 'currency' => 'IRT',
            'publisher' => ['key_id' => $keys['fingerprint']],
        ];

        $zipPath = $this->zipFor($manifest, $keys['secret']);
        $this->actingAs($publisher, 'sanctum')->post('/api/v1/market/submissions', [
            'file' => new UploadedFile($zipPath, 'pkg.zip', 'application/zip', null, true),
        ])->assertCreated();

        $plugin = Plugin::query()->where('slug', $slug)->firstOrFail();
        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/market/plugins/{$plugin->id}/approve")->assertOk();

        $stored = 'plugins/shared/'.$slug.'-test.zip';
        Storage::disk('local')->put($stored, (string) file_get_contents($zipPath));
        $plugin->forceFill(['path' => $stored])->save();

        return [$plugin->fresh(), $operator];
    }

    public function test_free_one_click_install_records_order_and_grants_license(): void
    {
        [$plugin] = $this->approvedPlugin(0);
        $buyer = $this->buyer();

        // همان ترتیب دکمه: checkout ⇒ تأیید مبلغ صفر ⇒ install بازار (K8.4).
        $orderId = $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/catalog/{$plugin->id}/checkout")
            ->assertCreated()->json('data.id');

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/orders/{$orderId}/pay")->assertOk();

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/plugins/{$plugin->id}/install")
            ->assertCreated()
            ->assertJsonPath('data.valid_until_major', CoreMajor::current());

        $license = MarketLicense::query()
            ->where('user_id', $buyer->id)->where('plugin_id', $plugin->id)->firstOrFail();
        $this->assertTrue($license->coversCore());
        $this->assertSame($orderId, (int) $license->order_id);
        $this->assertSame(0, (int) MarketOrder::find($orderId)->amount);
        $this->assertTrue(MarketOrder::find($orderId)->isPaid());
    }

    public function test_catalog_exposes_status_used_by_install_button(): void
    {
        [$plugin] = $this->approvedPlugin(0);
        $buyer = $this->buyer();

        $res = $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/market/catalog')->assertOk();
        $row = collect($res->json('data.data'))->firstWhere('slug', $plugin->slug);

        $this->assertNotNull($row);
        $this->assertArrayHasKey('active', $row);
        $this->assertFalse($row['active']);
        $this->assertSame(Plugin::REVIEW_APPROVED, $row['review_status']);
        $this->assertFalse($row['delivery']['owned']);
    }

    public function test_paid_one_click_install_without_license_is_rejected_402(): void
    {
        [$plugin] = $this->approvedPlugin(50000);
        $buyer = $this->buyer();

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/plugins/{$plugin->id}/install")
            ->assertStatus(402);

        // رد شدن نباید سفارش یا لایسنسِ ناخواسته بسازد.
        $this->assertDatabaseMissing('market_orders', ['plugin_id' => $plugin->id]);
        $this->assertDatabaseMissing('market_licenses', ['plugin_id' => $plugin->id]);
    }

    public function test_paid_one_click_install_succeeds_after_manual_payment(): void
    {
        [$plugin] = $this->approvedPlugin(50000);
        $buyer = $this->buyer();

        $orderId = $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/catalog/{$plugin->id}/checkout")
            ->assertCreated()->json('data.id');
        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/orders/{$orderId}/pay")->assertOk();

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/plugins/{$plugin->id}/install")
            ->assertCreated();

        $this->assertTrue(
            MarketLicense::query()->where('user_id', $buyer->id)->where('plugin_id', $plugin->id)
                ->firstOrFail()->coversCore()
        );
    }
}
