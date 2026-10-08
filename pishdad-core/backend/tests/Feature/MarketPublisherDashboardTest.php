<?php

namespace Tests\Feature;

use App\Models\MarketLedgerEntry;
use App\Models\MarketPayout;
use App\Models\Plugin;
use App\Models\PublisherKey;
use App\Models\User;
use App\Services\Plugins\PluginSignatureVerifier;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use ZipArchive;

/**
 * WF-H19 — dashboard ناشر: پروفایل، پلاگین‌های من، فروش/سهم، درخواست تسویه،
 * و آپلود نسخهٔ جدید. همه‌چیز به خودِ کاربر محدود است.
 */
class MarketPublisherDashboardTest extends TestCase
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

    private function editor(): User
    {
        $this->seed(RolesPermissionsSeeder::class);
        $user = User::query()->create([
            'name' => 'Editor', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Editor!1234'), 'role' => 'editor',
        ]);
        $user->assignRole('editor');

        return $user->fresh();
    }

    private function operator(): User
    {
        return User::query()->create([
            'name' => 'Operator', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Operator!1234'), 'role' => 'operator',
        ]);
    }

    private function buyer(): User
    {
        return User::query()->create([
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

    /** @return array{public: string, secret: string, fingerprint: string} */
    private function registerPublisher(User $owner, string $slug): array
    {
        $keys = $this->keypair();
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/market/publishers', [
            'name' => 'Pub '.$slug, 'slug' => $slug, 'public_key' => $keys['public'],
        ])->assertCreated();

        return $keys;
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

    private function upload(User $user, string $path)
    {
        return $this->actingAs($user, 'sanctum')->post('/api/v1/market/submissions', [
            'file' => new UploadedFile($path, 'pkg.zip', 'application/zip', null, true),
        ]);
    }

    /** پلاگینِ پولیِ تأییدشده + یک فروشِ پرداخت‌شده. @return array{0: User, 1: int, 2: int} */
    private function paidPlugin(): array
    {
        $owner = $this->owner();
        $operator = $this->operator();
        $keys = $this->registerPublisher($owner, 'dash-pub');

        $path = $this->zipFor([
            'slug' => 'dash-pkg', 'name' => 'Dash', 'version' => '1.0.0',
            'price' => 100000, 'currency' => 'IRT',
            'publisher' => ['key_id' => $keys['fingerprint']],
        ], $keys['secret']);
        $pluginId = $this->upload($owner, $path)->assertCreated()->json('data.id');

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/market/plugins/{$pluginId}/approve")->assertOk();

        $buyer = $this->buyer();
        $orderId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/v1/market/orders', ['plugin_id' => $pluginId])
            ->assertCreated()->json('data.id');
        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/orders/{$orderId}/pay")->assertOk();

        $keyId = (int) PublisherKey::query()->where('slug', 'dash-pub')->value('id');

        return [$owner->fresh(), $pluginId, $keyId];
    }

    public function test_dashboard_aggregates_only_own_publisher_data(): void
    {
        [$owner, $pluginId, $keyId] = $this->paidPlugin();

        $res = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/market/me/publisher')->assertOk()->json('data');

        $this->assertCount(1, $res['publishers']);
        $this->assertSame('dash-pub', $res['publishers'][0]['slug']);
        $this->assertSame($keyId, $res['publishers'][0]['id']);
        $this->assertArrayNotHasKey('public_key', $res['publishers'][0]);

        $this->assertCount(1, $res['plugins']);
        $this->assertSame($pluginId, $res['plugins'][0]['id']);
        $this->assertSame(Plugin::REVIEW_APPROVED, $res['plugins'][0]['review_status']);
        $this->assertSame('1.0.0', $res['plugins'][0]['version']);

        $this->assertSame(100000, $res['sales']['gross']);
        $this->assertSame(10000, $res['sales']['platform_fee']);
        $this->assertSame(90000, $res['sales']['publisher_share']);
        $this->assertSame(0, $res['sales']['payout']);
        $this->assertSame(90000, $res['sales']['unsettled']);
        $this->assertSame(1, $res['sales']['sales_count']);
        $this->assertSame([], $res['payouts']);

        // کاربری که هیچ پلاگینی ندارد، دادهٔ ناشرِ دیگری را نمی‌بیند.
        $other = $this->editor();
        $empty = $this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/market/me/publisher')->assertOk()->json('data');
        $this->assertSame([], $empty['publishers']);
        $this->assertSame([], $empty['plugins']);
        $this->assertSame(0, $empty['sales']['publisher_share']);
        $this->assertSame([], $empty['payouts']);
    }

    public function test_dashboard_requires_plugins_edit_permission(): void
    {
        $plain = User::query()->create([
            'name' => 'Plain', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Plain!1234'), 'role' => 'user',
        ]);

        $this->actingAs($plain, 'sanctum')
            ->getJson('/api/v1/market/me/publisher')->assertForbidden();
        $this->actingAs($plain, 'sanctum')
            ->postJson('/api/v1/market/me/payouts', ['amount' => 1000])->assertForbidden();
    }

    public function test_payout_request_creates_pending_and_is_bounded_and_scoped(): void
    {
        [$owner, , $keyId] = $this->paidPlugin();

        $payoutId = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/market/me/payouts', ['amount' => 90000, 'note' => 'درخواست من'])
            ->assertCreated()->json('data.id');

        $this->assertDatabaseHas('market_payouts', [
            'id' => $payoutId, 'status' => MarketPayout::STATUS_PENDING, 'amount' => 90000,
        ]);
        // هیچ ردیف دفتریِ payout ساخته نمی‌شود — اجرا هنوز دستی است.
        $this->assertSame(0, MarketLedgerEntry::query()
            ->where('kind', MarketLedgerEntry::KIND_PAYOUT)->count());

        // درخواست دوم بیش از سهمِ تسویه‌نشده (۹۰٬۰۰۰ منهای pending) رد می‌شود.
        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/market/me/payouts', ['amount' => 1])->assertStatus(422);

        // مالکیت: کاربر دیگری با پرمیشن، نمی‌تواند برای کلیدِ ناشرِ اول تسویه بخواهد.
        $other = $this->editor();
        $this->actingAs($other, 'sanctum')
            ->postJson('/api/v1/market/me/payouts', [
                'amount' => 100, 'publisher_key_id' => $keyId,
            ])->assertForbidden();
    }

    public function test_new_version_upload_updates_row_and_requires_ownership(): void
    {
        $owner = $this->owner();
        $keys = $this->registerPublisher($owner, 'ver-pub');

        $path = $this->zipFor([
            'slug' => 'ver-pkg', 'name' => 'Ver', 'version' => '1.0.0',
            'publisher' => ['key_id' => $keys['fingerprint']],
        ], $keys['secret']);
        $pluginId = $this->upload($owner, $path)->assertCreated()->json('data.id');

        // کاربرِ غیرمالک (ولی با perm) نمی‌تواند نسخه بفرستد.
        $intruder = $this->editor();
        $intruderZip = $this->zipFor([
            'slug' => 'ver-pkg', 'name' => 'Ver', 'version' => '9.9.9',
            'publisher' => ['key_id' => $keys['fingerprint']],
        ], $keys['secret']);
        $this->actingAs($intruder, 'sanctum')
            ->post("/api/v1/market/me/plugins/{$pluginId}/versions", [
                'file' => new UploadedFile($intruderZip, 'pkg.zip', 'application/zip', null, true),
            ])->assertForbidden();

        // ناشر نسخهٔ جدید را می‌فرستد؛ ردیف به‌روزرسانی و به بازبینی برمی‌گردد.
        $newZip = $this->zipFor([
            'slug' => 'ver-pkg', 'name' => 'Ver', 'version' => '2.0.0',
            'publisher' => ['key_id' => $keys['fingerprint']],
        ], $keys['secret']);
        $updated = $this->actingAs($owner, 'sanctum')
            ->post("/api/v1/market/me/plugins/{$pluginId}/versions", [
                'file' => new UploadedFile($newZip, 'pkg.zip', 'application/zip', null, true),
            ])->assertCreated()->json('data');

        $this->assertSame('2.0.0', $updated['version']);
        $this->assertSame('1.0.0', $updated['previous_version']);
        $this->assertSame(Plugin::REVIEW_PENDING, $updated['review_status']);

        // اسلاگِ متفاوت با پلاگینِ موجود رد می‌شود (واگراییِ هویت).
        $wrongSlug = $this->zipFor([
            'slug' => 'other-pkg', 'name' => 'Other', 'version' => '2.0.1',
            'publisher' => ['key_id' => $keys['fingerprint']],
        ], $keys['secret']);
        $this->actingAs($owner, 'sanctum')
            ->post("/api/v1/market/me/plugins/{$pluginId}/versions", [
                'file' => new UploadedFile($wrongSlug, 'pkg.zip', 'application/zip', null, true),
            ])->assertStatus(422);
    }
}
