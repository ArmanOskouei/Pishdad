<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageRevision;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WF-M11 — ثبت عبارت‌های جستجو و گزارش پرجستجوترین‌ها و بی‌نتیجه‌ها.
 */
class SearchReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    /** @param list<string> $permissions */
    private function user(array $permissions = ['analytics.view']): User
    {
        $user = User::query()->create([
            'name' => 'گزارش‌گر جستجو',
            'email' => 'sr'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function publish(User $owner, string $slug, string $title): Page
    {
        $page = Page::query()->create([
            'user_id' => $owner->id, 'title' => $title, 'slug' => $slug,
            'status' => 'draft', 'blocks' => [], 'meta' => null,
        ]);
        $rev = PageRevision::query()->create([
            'page_id' => $page->id, 'version' => 1,
            'blocks' => [], 'meta' => null, 'created_by' => $owner->id,
        ]);
        $page->forceFill([
            'status' => Page::STATUS_PUBLISHED,
            'published_revision_id' => $rev->id,
            'published_at' => now(),
        ])->save();

        return $page->fresh();
    }

    /** @param array<string, mixed> $overrides */
    private function row(array $overrides = []): void
    {
        DB::table('search_queries')->insert(array_merge([
            'query' => 'تست',
            'results_count' => 0,
            'created_at' => now(),
        ], $overrides));
    }

    public function test_public_search_records_query_and_result_count(): void
    {
        $this->publish($this->user(), 'about-search-report', 'درباره شرکت ما');

        $this->getJson('/api/v1/site/search?q='.urlencode('شرکت'))->assertOk();

        $row = DB::table('search_queries')->first();
        $this->assertNotNull($row);
        $this->assertSame('شرکت', $row->query);
        $this->assertGreaterThanOrEqual(1, (int) $row->results_count);
    }

    public function test_public_search_records_zero_result_queries(): void
    {
        $this->getJson('/api/v1/site/search?q='.urlencode('واژهٔ یکتای ناموجود'))->assertOk();

        $row = DB::table('search_queries')->first();
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->results_count);
    }

    public function test_records_carry_no_pii_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('search_queries', 'ip'));
        $this->assertFalse(Schema::hasColumn('search_queries', 'user_agent'));
        $this->assertFalse(Schema::hasColumn('search_queries', 'session_id'));
    }

    public function test_admin_aggregates_top_and_zero_result_queries(): void
    {
        $today = now()->toDateString();

        $this->row(['query' => 'قیمت', 'results_count' => 3]);
        $this->row(['query' => 'قیمت', 'results_count' => 2]);
        $this->row(['query' => 'قیمت', 'results_count' => 1]);
        $this->row(['query' => 'گارانتی', 'results_count' => 0]);
        $this->row(['query' => 'گارانتی', 'results_count' => 0]);
        $this->row(['query' => 'ارسال', 'results_count' => 0]);
        // بیرون از بازه — نباید در گزارش بیاید.
        $this->row(['query' => 'قدیمی', 'results_count' => 0, 'created_at' => now()->subDays(40)]);

        $res = $this->actingAs($this->user(), 'sanctum')->getJson(
            "/api/v1/admin/search-report?from={$today}&to={$today}"
        );

        $res->assertOk()
            ->assertJsonPath('data.totals.searches', 6)
            ->assertJsonPath('data.totals.unique_queries', 3)
            ->assertJsonPath('data.totals.zero_result_searches', 3)
            ->assertJsonPath('data.totals.zero_result_rate', 50)
            ->assertJsonPath('data.top_queries.0.query', 'قیمت')
            ->assertJsonPath('data.top_queries.0.searches', 3)
            ->assertJsonPath('data.zero_result_queries.0.query', 'گارانتی')
            ->assertJsonPath('data.zero_result_queries.0.searches', 2)
            ->assertJsonPath('data.zero_result_queries.1.query', 'ارسال')
            ->assertJsonPath('data.zero_result_queries.1.searches', 1);
    }

    public function test_report_is_empty_when_no_searches(): void
    {
        $today = now()->toDateString();

        $res = $this->actingAs($this->user(), 'sanctum')->getJson(
            "/api/v1/admin/search-report?from={$today}&to={$today}"
        );

        $res->assertOk()
            ->assertJsonPath('data.totals.searches', 0)
            ->assertJsonPath('data.totals.zero_result_rate', 0)
            ->assertJsonCount(0, 'data.top_queries')
            ->assertJsonCount(0, 'data.zero_result_queries');
    }

    public function test_read_requires_permission(): void
    {
        $this->getJson('/api/v1/admin/search-report')->assertUnauthorized();

        $this->actingAs($this->user(['pages.view']), 'sanctum')
            ->getJson('/api/v1/admin/search-report')
            ->assertForbidden();

        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/v1/admin/search-report')
            ->assertOk();
    }
}
