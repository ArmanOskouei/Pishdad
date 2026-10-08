<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** WF-H13 — پاک‌سازی دستی کش سایت (سراسری + per-page) + نگهبان دسترسی. */
class CachePurgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    private function user(array $permissions = []): User
    {
        $user = User::query()->create([
            'name' => 'کاربر تست',
            'email' => 'u'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user->fresh();
    }

    private function page(User $owner, string $slug): Page
    {
        return Page::query()->create([
            'user_id' => $owner->id,
            'title' => 'صفحه '.$slug,
            'slug' => $slug,
            'status' => Page::STATUS_DRAFT,
            'blocks' => [],
        ]);
    }

    public function test_global_purge_dispatches_site_and_all_page_tags(): void
    {
        Http::fake(['*' => Http::response(['message' => 'ok'], 200)]);
        config(['revalidate.url' => 'http://front.local/api/revalidate']);

        $user = $this->user(['settings.edit']);
        $this->page($user, 'about');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/cache/purge')
            ->assertOk()
            ->assertJsonPath('data.dispatched', true);

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/api/revalidate')
            && in_array('pages', $req['tags'], true)
            && in_array('site-chrome', $req['tags'], true)
            && in_array('site-homepage', $req['tags'], true)
            && in_array('page:about', $req['tags'], true));
    }

    public function test_page_purge_dispatches_page_and_pages_tags(): void
    {
        Http::fake(['*' => Http::response(['message' => 'ok'], 200)]);
        config(['revalidate.url' => 'http://front.local/api/revalidate']);

        $user = $this->user(['pages.edit']);
        $this->page($user, 'contact');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/cache/purge-page', ['slug' => 'contact'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'contact')
            ->assertJsonPath('data.dispatched', true);

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/api/revalidate')
            && in_array('pages', $req['tags'], true)
            && in_array('page:contact', $req['tags'], true)
            && ! in_array('site-chrome', $req['tags'], true));
    }

    public function test_host_page_purge_also_dispatches_home_tags(): void
    {
        Http::fake(['*' => Http::response(['message' => 'ok'], 200)]);
        config(['revalidate.url' => 'http://front.local/api/revalidate']);

        $user = $this->user(['pages.edit']);
        $this->page($user, 'home');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/cache/purge-page', ['slug' => 'home'])
            ->assertOk();

        Http::assertSent(fn ($req) => in_array('page:home', $req['tags'], true)
            && in_array('site-homepage', $req['tags'], true));
    }

    public function test_page_purge_requires_slug(): void
    {
        $user = $this->user(['pages.edit']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/cache/purge-page', [])
            ->assertStatus(422);
    }

    public function test_global_purge_requires_settings_edit_permission(): void
    {
        $user = $this->user(['pages.edit']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/cache/purge')
            ->assertStatus(403);
    }

    public function test_page_purge_requires_pages_edit_permission(): void
    {
        $user = $this->user(['settings.edit']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/cache/purge-page', ['slug' => 'about'])
            ->assertStatus(403);
    }
}
