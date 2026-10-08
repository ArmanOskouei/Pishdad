<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Redirect;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** WF-C2 — ریدایرکت ۳۰۱/۳۰۲: CRUD ادمین، فهرست عمومی، شمارش بازدید، پیشنهاد تغییر اسلاگ. */
class RedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    private function user(array $perms): User
    {
        $user = User::query()->create([
            'name' => 'مدیر',
            'email' => 'rd'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
        $user->givePermissionTo(...$perms);

        return $user->fresh();
    }

    public function test_admin_crud_and_public_map_and_hit(): void
    {
        $user = $this->user(['settings.view', 'settings.edit']);

        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/redirects', [
            'from_path' => 'old-page',
            'to_path' => '/new-page',
            'status_code' => 301,
        ]);
        $created->assertCreated()->assertJsonPath('data.from_path', '/old-page');

        $id = $created->json('data.id');

        $this->getJson('/api/v1/site/redirects')
            ->assertOk()
            ->assertJsonPath('data.0.from_path', '/old-page')
            ->assertJsonPath('data.0.to_path', '/new-page')
            ->assertJsonPath('data.0.status_code', 301);

        $this->postJson('/api/v1/site/redirects/hit', ['path' => '/old-page'])->assertOk();
        $this->assertSame(1, (int) Redirect::query()->findOrFail($id)->hits);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/admin/redirects/{$id}", ['active' => false])
            ->assertOk();

        $this->getJson('/api/v1/site/redirects')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_validation_normalizes_path_and_rejects_duplicate_and_bad_code(): void
    {
        $user = $this->user(['settings.view', 'settings.edit']);

        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/redirects', [
            'from_path' => 'plain',
            'to_path' => '/x',
        ]);
        $created->assertCreated()->assertJsonPath('data.from_path', '/plain');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/redirects', [
            'from_path' => '/plain',
            'to_path' => '/y',
        ])->assertStatus(422);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/redirects', [
            'from_path' => '/sc',
            'to_path' => '/y',
            'status_code' => 307,
        ])->assertStatus(422);
    }

    public function test_slug_change_returns_redirect_suggestion(): void
    {
        $user = $this->user(['pages.view', 'pages.edit']);

        $page = Page::query()->create([
            'user_id' => $user->id,
            'title' => 'صفحه',
            'slug' => 'old-slug',
            'status' => Page::STATUS_DRAFT,
            'blocks' => [],
        ]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/admin/pages/{$page->id}", [
            'slug' => 'new-slug',
        ])
            ->assertOk()
            ->assertJsonPath('redirect_suggestion.from_path', '/old-slug')
            ->assertJsonPath('redirect_suggestion.to_path', '/new-slug');
    }
}
