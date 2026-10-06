<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WF-H12 — تحلیل سایت بدون کوکی: ثبت بازدید، تجمع، فیلتر بازه و پرمیشن.
 */
class AnalyticsTest extends TestCase
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
            'name' => 'تحلیل‌گر',
            'email' => 'a'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    /** @param array<string, mixed> $overrides */
    private function row(array $overrides = []): void
    {
        DB::table('page_views')->insert(array_merge([
            'path' => '/',
            'slug' => null,
            'referrer' => null,
            'country' => null,
            'device' => 'desktop',
            'created_at' => now(),
        ], $overrides));
    }

    public function test_public_view_is_recorded_without_pii(): void
    {
        $page = Page::query()->create([
            'title' => 'درباره ما',
            'slug' => 'about',
            'locale' => 'fa',
            'status' => Page::STATUS_PUBLISHED,
        ]);

        $this->withHeaders([
            'CF-IPCountry' => 'IR',
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile',
        ])->postJson('/api/v1/site/analytics/view', [
            'path' => '/about?utm=1',
            'referrer' => 'https://www.google.com/search?q=private',
        ])->assertOk()->assertJsonPath('ok', true);

        $row = DB::table('page_views')->first();
        $this->assertNotNull($row);
        $this->assertSame('/about', $row->path);
        $this->assertSame('about', $row->slug);
        $this->assertSame('www.google.com', $row->referrer);
        $this->assertSame('IR', $row->country);
        $this->assertSame('mobile', $row->device);
        $this->assertSame($page->id, (int) $row->page_id);

        $this->assertFalse(Schema::hasColumn('page_views', 'ip'));
        $this->assertFalse(Schema::hasColumn('page_views', 'user_agent'));
    }

    public function test_unknown_country_stays_null(): void
    {
        $this->withHeaders(['CF-IPCountry' => 'XX', 'User-Agent' => 'curl/8'])
            ->postJson('/api/v1/site/analytics/view', ['path' => '/en/about'])
            ->assertOk();

        $row = DB::table('page_views')->first();
        $this->assertNull($row->country);
        $this->assertSame('about', $row->slug);
    }

    public function test_admin_aggregates_for_range(): void
    {
        $today = now()->toDateString();
        $this->row(['path' => '/', 'country' => 'IR', 'referrer' => 'google.com']);
        $this->row(['path' => '/', 'country' => 'IR']);
        $this->row(['path' => '/about', 'country' => 'DE', 'device' => 'mobile']);
        $this->row(['path' => '/old', 'created_at' => now()->subDays(10)]);

        $res = $this->actingAs($this->user(), 'sanctum')->getJson(
            "/api/v1/admin/analytics?from={$today}&to={$today}"
        );

        $res->assertOk()
            ->assertJsonPath('data.totals.views', 3)
            ->assertJsonPath('data.totals.unique_paths', 2)
            ->assertJsonPath('data.top_pages.0.key', '/')
            ->assertJsonPath('data.top_pages.0.views', 2)
            ->assertJsonPath('data.countries.0.key', 'IR')
            ->assertJsonPath('data.top_referrers.0.key', 'google.com');

        $series = $res->json('data.series');
        $this->assertCount(1, $series);
        $this->assertSame(3, $series[0]['views']);
        $this->assertSame($today, $series[0]['date']);
    }

    public function test_country_coverage_is_reported_honestly(): void
    {
        $today = now()->toDateString();
        $this->row(['country' => 'IR']);
        $this->row();
        $this->row();

        $res = $this->actingAs($this->user(), 'sanctum')->getJson(
            "/api/v1/admin/analytics?from={$today}&to={$today}"
        );

        $res->assertOk()
            ->assertJsonPath('data.totals.views', 3)
            ->assertJsonPath('data.totals.country_coverage', 33.3);
    }

    public function test_read_requires_permission(): void
    {
        $this->getJson('/api/v1/admin/analytics')->assertUnauthorized();

        $this->actingAs($this->user(['pages.view']), 'sanctum')
            ->getJson('/api/v1/admin/analytics')
            ->assertForbidden();

        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/v1/admin/analytics')
            ->assertOk();
    }

    /** @param array<string, mixed> $overrides */
    private function vital(array $overrides = []): void
    {
        DB::table('page_vitals')->insert(array_merge([
            'path' => '/',
            'slug' => null,
            'metric' => 'lcp',
            'value' => 1000,
            'created_at' => now(),
        ], $overrides));
    }

    public function test_public_vitals_are_recorded_without_pii(): void
    {
        $page = Page::query()->create([
            'title' => 'درباره ما',
            'slug' => 'about',
            'locale' => 'fa',
            'status' => Page::STATUS_PUBLISHED,
        ]);

        $this->postJson('/api/v1/site/analytics/vitals', [
            'path' => '/about?utm=1',
            'lcp' => 2400,
            'inp' => 180,
            'cls' => 0.05,
        ])->assertOk()->assertJsonPath('ok', true);

        $rows = DB::table('page_vitals')->orderBy('metric')->get();
        $this->assertCount(3, $rows);
        $this->assertSame('cls', $rows[0]->metric);
        $this->assertSame('inp', $rows[1]->metric);
        $this->assertSame('lcp', $rows[2]->metric);
        $this->assertSame('about', $rows[2]->slug);
        $this->assertSame('/about', $rows[2]->path);
        $this->assertSame($page->id, (int) $rows[2]->page_id);
        $this->assertSame(2400.0, (float) $rows[2]->value);
        $this->assertSame(0.05, (float) $rows[0]->value);

        $this->assertFalse(Schema::hasColumn('page_vitals', 'ip'));
        $this->assertFalse(Schema::hasColumn('page_vitals', 'user_agent'));
    }

    public function test_vitals_from_bots_are_not_recorded(): void
    {
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'])
            ->postJson('/api/v1/site/analytics/vitals', ['path' => '/', 'lcp' => 900])
            ->assertOk();

        $this->assertSame(0, DB::table('page_vitals')->count());
    }

    public function test_vitals_with_no_metric_are_a_noop(): void
    {
        $this->postJson('/api/v1/site/analytics/vitals', ['path' => '/'])
            ->assertOk();

        $this->assertSame(0, DB::table('page_vitals')->count());
    }

    public function test_admin_aggregates_vitals_p75_per_metric(): void
    {
        $today = now()->toDateString();
        $this->vital(['metric' => 'lcp', 'value' => 1000]);
        $this->vital(['metric' => 'lcp', 'value' => 2000]);
        $this->vital(['metric' => 'lcp', 'value' => 3000.5]);
        $this->vital(['metric' => 'lcp', 'value' => 4000]);
        $this->vital(['metric' => 'inp', 'value' => 120]);
        $this->vital(['metric' => 'cls', 'value' => 0.02]);
        // بیرون از بازه — نباید در صدک بیاید.
        $this->vital(['metric' => 'lcp', 'value' => 99999, 'created_at' => now()->subDays(30)]);

        $res = $this->actingAs($this->user(), 'sanctum')->getJson(
            "/api/v1/admin/analytics?from={$today}&to={$today}"
        );

        $res->assertOk()
            ->assertJsonPath('data.vitals.0.metric', 'lcp')
            ->assertJsonPath('data.vitals.0.samples', 4)
            ->assertJsonPath('data.vitals.0.p75', 3000.5)
            ->assertJsonPath('data.vitals.1.metric', 'inp')
            ->assertJsonPath('data.vitals.1.samples', 1)
            ->assertJsonPath('data.vitals.1.p75', 120)
            ->assertJsonPath('data.vitals.2.metric', 'cls')
            ->assertJsonPath('data.vitals.2.samples', 1);
    }

    public function test_vitals_are_reported_as_empty_when_no_samples(): void
    {
        $today = now()->toDateString();

        $res = $this->actingAs($this->user(), 'sanctum')->getJson(
            "/api/v1/admin/analytics?from={$today}&to={$today}"
        );

        $vitals = $res->assertOk()->json('data.vitals');
        $this->assertCount(3, $vitals);
        foreach ($vitals as $vital) {
            $this->assertSame(0, $vital['samples']);
            $this->assertNull($vital['p75']);
        }
    }
}
