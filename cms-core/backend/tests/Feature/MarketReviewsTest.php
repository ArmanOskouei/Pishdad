<?php

namespace Tests\Feature;

use App\Models\MarketLicense;
use App\Models\MarketReview;
use App\Models\Plugin;
use App\Models\User;
use App\Services\Market\CoreMajor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * WF-H18 — امتیاز/نظرات آیتم بازار + شمار نصب فعال + بازبینی مرکزی.
 *
 * قفل می‌کند سه چیز را: (۱) نظر فقط برای خریدارِ تأییدشده ثبت می‌شود؛ (۲) هر
 * کاربر یک‌بار؛ (۳) میانگین/شمار و «نصب فعال» از دادهٔ واقعی می‌آیند و فقط
 * نظرِ تأییدشده شمرده می‌شود.
 */
class MarketReviewsTest extends TestCase
{
    use RefreshDatabase;

    private function buyer(): User
    {
        return User::query()->create([
            'name' => 'Buyer', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Buyer!1234'), 'role' => 'user',
        ]);
    }

    private function operator(): User
    {
        return User::query()->create([
            'name' => 'Operator', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Operator!1234'), 'role' => 'operator',
        ]);
    }

    private function plugin(int $price = 0): Plugin
    {
        return Plugin::query()->create([
            'name' => 'Market Widget',
            'slug' => 'market-widget-'.uniqid(),
            'version' => '1.0.0',
            'source' => Plugin::SOURCE_MARKET,
            'review_status' => Plugin::REVIEW_APPROVED,
            'manifest' => ['name' => 'Market Widget', 'price' => $price, 'currency' => 'IRT'],
        ]);
    }

    private function grantLicense(Plugin $plugin, User $user): MarketLicense
    {
        return MarketLicense::query()->create([
            'user_id' => $user->id,
            'plugin_id' => $plugin->id,
            'valid_until_major' => CoreMajor::current(),
            'active' => true,
        ]);
    }

    private function reviewedRow(Plugin $plugin, User $user, int $rating, string $status = MarketReview::STATUS_PENDING): MarketReview
    {
        return MarketReview::query()->create([
            'plugin_id' => $plugin->id,
            'user_id' => $user->id,
            'rating' => $rating,
            'comment' => 'نظر آزمایشی '.$rating,
            'status' => $status,
            'reviewed_at' => $status === MarketReview::STATUS_PENDING ? null : now(),
        ]);
    }

    public function test_buyer_with_license_submits_review_that_starts_pending(): void
    {
        $plugin = $this->plugin();
        $buyer = $this->buyer();
        $this->grantLicense($plugin, $buyer);

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/catalog/{$plugin->id}/reviews", [
                'rating' => 5,
                'comment' => 'عالی و بدون مشکل نصب شد.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', MarketReview::STATUS_PENDING)
            ->assertJsonPath('data.rating', 5);

        $this->assertDatabaseHas('market_reviews', [
            'plugin_id' => $plugin->id,
            'user_id' => $buyer->id,
            'status' => MarketReview::STATUS_PENDING,
        ]);
    }

    public function test_non_buyer_cannot_submit_review(): void
    {
        $plugin = $this->plugin();
        $stranger = $this->buyer();

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/market/catalog/{$plugin->id}/reviews", ['rating' => 4])
            ->assertStatus(403);

        $this->assertDatabaseMissing('market_reviews', ['plugin_id' => $plugin->id, 'user_id' => $stranger->id]);
    }

    public function test_paid_order_without_license_also_counts_as_buyer(): void
    {
        $plugin = $this->plugin(50000);
        $buyer = $this->buyer();
        $orderId = $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/catalog/{$plugin->id}/checkout")
            ->assertCreated()->json('data.id');

        // هنوز لایسنس/نصب نیست — ولی خریدارِ پرداخت‌شده باید بتواند نظر بدهد.
        \App\Models\MarketOrder::findOrFail($orderId)->forceFill([
            'status' => \App\Models\MarketOrder::STATUS_PAID,
            'paid_at' => now(),
        ])->save();

        $this->assertDatabaseMissing('market_licenses', ['plugin_id' => $plugin->id]);

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/catalog/{$plugin->id}/reviews", ['rating' => 3])
            ->assertCreated();
    }

    public function test_rating_out_of_range_is_rejected(): void
    {
        $plugin = $this->plugin();
        $buyer = $this->buyer();
        $this->grantLicense($plugin, $buyer);

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/catalog/{$plugin->id}/reviews", ['rating' => 6])
            ->assertStatus(422);

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/catalog/{$plugin->id}/reviews", ['rating' => 0])
            ->assertStatus(422);
    }

    public function test_one_review_per_user_per_plugin(): void
    {
        $plugin = $this->plugin();
        $buyer = $this->buyer();
        $this->grantLicense($plugin, $buyer);

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/catalog/{$plugin->id}/reviews", ['rating' => 5])
            ->assertCreated();

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/catalog/{$plugin->id}/reviews", ['rating' => 1])
            ->assertStatus(409);

        $this->assertSame(1, MarketReview::query()
            ->where('plugin_id', $plugin->id)->where('user_id', $buyer->id)->count());
    }

    public function test_average_count_and_active_installs_come_from_real_data(): void
    {
        $plugin = $this->plugin();
        $first = $this->buyer();
        $second = $this->buyer();
        $this->grantLicense($plugin, $first);
        $this->grantLicense($plugin, $second);

        $one = $this->reviewedRow($plugin, $first, 4);
        $two = $this->reviewedRow($plugin, $second, 2);

        // پیش از تأیید: هیچ‌کدام در میانگین/فهرست عمومی نیستند.
        $before = $this->actingAs($first, 'sanctum')
            ->getJson("/api/v1/market/catalog/{$plugin->id}/reviews")->assertOk();
        $before->assertJsonPath('meta.rating_count', 0);
        $before->assertJsonPath('meta.rating', null);
        $before->assertJsonPath('meta.active_installs', 2);

        $operator = $this->operator();
        $this->actingAs($operator, 'sanctum')->postJson("/api/v1/market/reviews/{$one->id}/approve")->assertOk();
        $this->actingAs($operator, 'sanctum')->postJson("/api/v1/market/reviews/{$two->id}/approve")->assertOk();

        $res = $this->actingAs($first, 'sanctum')
            ->getJson("/api/v1/market/catalog/{$plugin->id}/reviews")->assertOk();

        $this->assertSame(3.0, (float) $res->json('meta.rating'));
        $this->assertSame(2, (int) $res->json('meta.rating_count'));
        $this->assertSame(2, (int) $res->json('meta.active_installs'));
        $this->assertCount(2, $res->json('data'));
        $this->assertSame('Buyer', $res->json('data.0.author'));
    }

    public function test_catalog_detail_exposes_rating_reviews_and_can_review(): void
    {
        $plugin = $this->plugin();
        $buyer = $this->buyer();
        $this->grantLicense($plugin, $buyer);

        $detail = $this->actingAs($buyer, 'sanctum')
            ->getJson("/api/v1/market/catalog/{$plugin->id}")->assertOk();

        $this->assertArrayHasKey('rating', $detail->json('data'));
        $this->assertArrayHasKey('rating_count', $detail->json('data'));
        $this->assertArrayHasKey('active_installs', $detail->json('data'));
        $this->assertIsArray($detail->json('data.reviews'));
        $this->assertTrue($detail->json('data.can_review'));
        $this->assertFalse($detail->json('data.has_reviewed'));

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/market/catalog/{$plugin->id}/reviews", ['rating' => 5])
            ->assertCreated();

        $again = $this->actingAs($buyer, 'sanctum')
            ->getJson("/api/v1/market/catalog/{$plugin->id}")->assertOk();
        $this->assertFalse($again->json('data.can_review'));
        $this->assertTrue($again->json('data.has_reviewed'));
    }

    public function test_moderation_queue_is_operator_only_and_approve_reject_work(): void
    {
        $plugin = $this->plugin();
        $buyer = $this->buyer();
        $this->grantLicense($plugin, $buyer);
        $review = $this->reviewedRow($plugin, $buyer, 5);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/market/reviews')->assertForbidden();

        $operator = $this->operator();
        $queue = $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/market/reviews')->assertOk()->json('data.data');
        $this->assertNotEmpty(array_filter($queue, fn ($r) => (int) $r['id'] === $review->id));

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/market/reviews/{$review->id}/reject", ['note' => 'اسپم'])
            ->assertOk()
            ->assertJsonPath('data.status', MarketReview::STATUS_REJECTED);

        // ردشده به فهرست عمومی نمی‌رسد و دوباره قابل رد نیست.
        $this->assertCount(0, $this->actingAs($buyer, 'sanctum')
            ->getJson("/api/v1/market/catalog/{$plugin->id}/reviews")->json('data'));
        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/market/reviews/{$review->id}/approve")
            ->assertStatus(422);
    }
}
