<?php

namespace Tests\Feature;

use App\Models\MarketLicense;
use App\Models\MarketOrder;
use App\Models\Plugin;
use App\Services\Market\DeliveryService;
use App\Services\Market\MarketException;
use App\Services\Market\ReviewService;
use App\Services\Plugins\PluginSignatureVerifier;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * ECO6 — فروشگاه: کاتالوگ + checkout + تحویل ZIP.
 *
 * زنجیرهٔ کامل با فایل واقعی سنجیده می‌شود، نه mock: یک ZIP ساخته می‌شود،
 * آپلود و تأیید می‌شود، خریده می‌شود، دانلود می‌شود، محتوایش assert می‌شود و
 * بار دوم **قفل** است. اگر هر حلقه نبود، «تحویل» یعنی ادعا.
 */
class MarketStorefrontTest extends TestCase
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

    /**
     * ZIP واقعیِ قابل نصب — نه فقط manifest. یک فایل PHP داخلش می‌گذاریم تا
     * «محتوای تحویل‌شده» چیزی برای assert داشتن باشد.
     */
    private function zipFor(array $manifest, string $secret): string
    {
        $manifest['signature'] = app(PluginSignatureVerifier::class)->sign($manifest, $secret);
        $path = tempnam(sys_get_temp_dir(), 'mkt').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('Laravel/src/Hello.php', "<?php\n// ECO6 test artifact\nreturn 'hello';\n");
        $zip->close();

        return $path;
    }

    /**
     * ثبتِ کاملِ یک افزونهٔ تأییدشده با قیمت مشخص، و گذاشتنِ ZIP در storage
     * محلی روی `path` — چون تحویل از همان `plugin->path` می‌خواند.
     *
     * @return array{0: Plugin, 1: \App\Models\User} [plugin, operator]
     */
    private function approvedPlugin(int $price = 50000, array $extraManifest = []): array
    {
        $keys = $this->keypair();
        $publisher = $this->owner();
        $operator = $this->operator();

        $this->actingAs($publisher, 'sanctum')->postJson('/api/v1/market/publishers', [
            'name' => 'Pub', 'slug' => 'pub-'.uniqid(), 'public_key' => $keys['public'],
        ])->assertCreated();

        $slug = 'store-'.uniqid();
        $manifest = array_merge([
            'slug' => $slug, 'name' => 'Store Widget', 'version' => '1.2.3',
            'price' => $price, 'currency' => 'IRT',
            'publisher' => ['key_id' => $keys['fingerprint']],
        ], $extraManifest);

        $zipPath = $this->zipFor($manifest, $keys['secret']);
        $this->actingAs($publisher, 'sanctum')->post('/api/v1/market/submissions', [
            'file' => new UploadedFile($zipPath, 'pkg.zip', 'application/zip', null, true),
        ])->assertCreated();

        $plugin = Plugin::query()->where('slug', $slug)->firstOrFail();
        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/market/plugins/{$plugin->id}/approve")->assertOk();

        // فایل تحویل را روی storage محلی می‌گذاریم — همان مکانی که PluginController
        // برای آپلودهای واقعی استفاده می‌کند (`plugins/shared`).
        $stored = 'plugins/shared/'.$slug.'-test.zip';
        Storage::disk('local')->put($stored, (string) file_get_contents($zipPath));
        $plugin->forceFill(['path' => $stored])->save();

        return [$plugin->fresh(), $operator];
    }

    public function test_catalog_lists_only_approved_non_yanked_plugins(): void
    {
        [$plugin, $operator] = $this->approvedPlugin();
        $buyer = $this->buyer();

        $res = $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/market/catalog')->assertOk();
        $list = $res->json('data.data');
        $found = array_values(array_filter($list, fn ($p) => $p['slug'] === $plugin->slug));
        $this->assertCount(1, $found);
        $this->assertSame(50000, (int) $found[0]['price']);
        $this->assertArrayHasKey('delivery', $found[0]);
        $this->assertFalse($found[0]['delivery']['owned']);

        // Yank می‌شود و از کاتالوگ می‌رود، ولی رکورد می‌ماند.
        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/market/plugins/{$plugin->id}/yank", ['reason' => 'ECO6 test'])
            ->assertOk();

        $after = $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/market/catalog')->assertOk()->json('data.data');
        $this->assertEmpty(array_filter($after, fn ($p) => $p['slug'] === $plugin->slug));
        $this->assertDatabaseHas('plugins', ['id' => $plugin->id]);
    }

    public function test_checkout_creates_pending_order_and_is_honest_about_no_gateway(): void
    {
        [$plugin] = $this->approvedPlugin();
        $buyer = $this->buyer();

        $res = $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/catalog/{$plugin->id}/checkout")
            ->assertCreated();

        $orderId = $res->json('data.id');
        $this->assertSame(MarketOrder::STATUS_PENDING, $res->json('data.status'));
        $this->assertSame(50000, (int) $res->json('data.amount'));
        // صراحت: درگاه وجود ندارد، پس پاسخ نمی‌تواند ادعای پرداخت بکند.
        $this->assertFalse($res->json('gateway.available'));
        $this->assertSame('manual', $res->json('gateway.mode'));

        // بدون پرداخت، تحویل ۴۰۲ می‌دهد — نه یک لایسنسِ خودکار.
        $this->actingAs($buyer, 'sanctum')
            ->getJson("/api/v1/market/catalog/{$plugin->id}/download")
            ->assertStatus(402);

        $this->assertFalse(MarketOrder::find($orderId)->isPaid());
    }

    public function test_paid_order_delivers_zip_once_with_real_contents(): void
    {
        [$plugin] = $this->approvedPlugin();
        $buyer = $this->buyer();

        $orderId = $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/catalog/{$plugin->id}/checkout")
            ->assertCreated()->json('data.id');

        // پرداخت دستی (بدون درگاه) — همان BillingService::payOrder.
        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/orders/{$orderId}/pay")->assertOk();
        $this->assertTrue(MarketOrder::find($orderId)->isPaid());

        // تحویل اول: فایل ZIP واقعی برمی‌گردد.
        $res = $this->actingAs($buyer, 'sanctum')
            ->get("/api/v1/market/catalog/{$plugin->id}/download");
        $res->assertOk();
        $this->assertStringContainsString('application/zip', (string) $res->headers->get('content-type'));

        // محتوای تحویل‌شده را واقعاً باز می‌کنیم و assert می‌کنیم.
        $downloaded = $res->streamedContent();
        if ($downloaded === '' || $downloaded === false) {
            $downloaded = $res->getFile()->getContent();
        }
        $tmp = tempnam(sys_get_temp_dir(), 'dl').'.zip';
        file_put_contents($tmp, $downloaded);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp) === true, 'فایل تحویل‌شده ZIP بازشدنی نیست.');
        $manifestRaw = $zip->getFromName('manifest.json');
        $this->assertIsString($manifestRaw);
        $this->assertSame($plugin->slug, json_decode($manifestRaw, true)['slug']);
        $this->assertIsString($zip->getFromName('Laravel/src/Hello.php'));
        $zip->close();

        // لایسنس مهر تحویل خورده و checksum ثبت شده.
        $license = MarketLicense::query()->where('plugin_id', $plugin->id)->firstOrFail();
        $this->assertNotNull($license->delivered_at);
        $this->assertSame(hash('sha256', $downloaded), $license->delivered_checksum);

        // تحویل دوم: قفل.
        $this->actingAs($buyer, 'sanctum')
            ->getJson("/api/v1/market/catalog/{$plugin->id}/download")
            ->assertStatus(409);
    }

    public function test_other_user_cannot_deliver_without_purchase(): void
    {
        [$plugin] = $this->approvedPlugin();
        $buyer = $this->buyer();
        $stranger = $this->buyer();

        $orderId = $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/catalog/{$plugin->id}/checkout")
            ->assertCreated()->json('data.id');
        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/orders/{$orderId}/pay")->assertOk();

        // کسی که نمی‌خرد، دانلود نمی‌کند.
        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/market/catalog/{$plugin->id}/download")
            ->assertStatus(402);

        // وضعیت تحویلِ کاتالوگ برای غریبه owned=false است.
        $detail = $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/market/catalog/{$plugin->id}")->assertOk()->json('data');
        $this->assertFalse($detail['delivery']['owned']);
        $this->assertFalse($detail['delivery']['deliverable']);
    }

    public function test_delivery_service_flags_missing_file_as_404(): void
    {
        [$plugin] = $this->approvedPlugin();
        $buyer = $this->buyer();

        $orderId = $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/catalog/{$plugin->id}/checkout")
            ->assertCreated()->json('data.id');
        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/orders/{$orderId}/pay")->assertOk();

        // فایل روی دیسک نیست ⇒ تحویل باید ۴۰۴ بدهد، نه ZIP خالی.
        Storage::disk('local')->delete($plugin->path);

        try {
            app(DeliveryService::class)->deliver($plugin, $buyer);
            $this->fail('expected MarketException 404');
        } catch (MarketException $e) {
            $this->assertSame(404, $e->status);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // WF-H16 — جستجو/دسته/فیلتر + صفحهٔ جزئیات
    // ═══════════════════════════════════════════════════════════════════════

    public function test_catalog_search_matches_name_slug_and_description(): void
    {
        [$plugin] = $this->approvedPlugin(1000, [
            'name' => 'Alpha Analytics Widget',
            'description' => 'ابزار تحلیل داده‌های فروش',
        ]);
        $buyer = $this->buyer();

        foreach (['Alpha', $plugin->slug, 'تحلیل'] as $term) {
            $list = $this->actingAs($buyer, 'sanctum')
                ->getJson('/api/v1/market/catalog?q='.rawurlencode($term))->assertOk()->json('data.data');
            $this->assertNotEmpty(
                array_filter($list, fn ($p) => $p['slug'] === $plugin->slug),
                "جستجو برای «{$term}» باید همین افزونه را برگرداند."
            );
        }

        $miss = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/market/catalog?q=zzz-no-such-thing')->assertOk()->json('data.data');
        $this->assertEmpty(array_filter($miss, fn ($p) => $p['slug'] === $plugin->slug));
    }

    public function test_catalog_filters_by_free_and_paid(): void
    {
        [$free] = $this->approvedPlugin(0, ['name' => 'Free Widget']);
        [$paid] = $this->approvedPlugin(25000, ['name' => 'Paid Widget']);
        $buyer = $this->buyer();

        $freeList = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/market/catalog?price=free')->assertOk()->json('data.data');
        $this->assertNotEmpty(array_filter($freeList, fn ($p) => $p['slug'] === $free->slug));
        $this->assertEmpty(array_filter($freeList, fn ($p) => $p['slug'] === $paid->slug));

        $paidList = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/market/catalog?price=paid')->assertOk()->json('data.data');
        $this->assertNotEmpty(array_filter($paidList, fn ($p) => $p['slug'] === $paid->slug));
        $this->assertEmpty(array_filter($paidList, fn ($p) => $p['slug'] === $free->slug));
    }

    public function test_catalog_filters_by_category_from_manifest(): void
    {
        [$analytics] = $this->approvedPlugin(0, ['name' => 'Analytics Widget', 'category' => 'analytics']);
        [$seo] = $this->approvedPlugin(0, ['name' => 'SEO Widget', 'categories' => ['seo', 'tools']]);
        $buyer = $this->buyer();

        $res = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/market/catalog?category=analytics')->assertOk();
        $list = $res->json('data.data');
        $this->assertNotEmpty(array_filter($list, fn ($p) => $p['slug'] === $analytics->slug));
        $this->assertEmpty(array_filter($list, fn ($p) => $p['slug'] === $seo->slug));

        $meta = $res->json('meta.categories');
        $this->assertContains('analytics', $meta);
        $this->assertContains('seo', $meta);
        $this->assertContains('tools', $meta);
    }

    public function test_catalog_detail_exposes_description_screenshots_changelog_and_versions(): void
    {
        [$plugin] = $this->approvedPlugin(0, [
            'name' => 'Deluxe Widget',
            'long_description' => 'توضیح بلند و کامل افزونه.',
            'screenshots' => ['https://cdn.example.com/a.png', 'https://cdn.example.com/b.png'],
            'changelog' => "1.2.3 — افزودن حالت تاریک\n1.2.2 — رفع باگ",
            'category' => 'deluxe',
        ]);
        $buyer = $this->buyer();

        $data = $this->actingAs($buyer, 'sanctum')
            ->getJson("/api/v1/market/catalog/{$plugin->id}")->assertOk()->json('data');

        $this->assertSame('توضیح بلند و کامل افزونه.', $data['long_description']);
        $this->assertSame(['https://cdn.example.com/a.png', 'https://cdn.example.com/b.png'], $data['screenshots']);
        $this->assertStringContainsString('حالت تاریک', $data['changelog']);
        $this->assertSame('deluxe', $data['category']);
        $this->assertArrayHasKey('delivery', $data);
        $this->assertIsArray($data['versions']);
        $this->assertNotEmpty($data['versions']);
        $this->assertSame('1.2.3', $data['versions'][0]['version']);
        $this->assertStringContainsString('حالت تاریک', $data['versions'][0]['changelog']);
    }

    public function test_catalog_detail_hides_non_market_or_yanked_plugins(): void
    {
        [$plugin, $operator] = $this->approvedPlugin();
        $buyer = $this->buyer();

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/market/plugins/{$plugin->id}/yank", ['reason' => 'WF-H16 test'])
            ->assertOk();

        $this->actingAs($buyer, 'sanctum')
            ->getJson("/api/v1/market/catalog/{$plugin->id}")
            ->assertStatus(404);
    }
}
