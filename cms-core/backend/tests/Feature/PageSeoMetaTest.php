<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Page;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** WF-C1 — متای سئو هر صفحه: تصویر OG، کنونیکال، noindex. */
class PageSeoMetaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    private function user(): User
    {
        $user = User::query()->create([
            'name' => 'مدیر سئو',
            'email' => 'seo'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
        $user->givePermissionTo('pages.view', 'pages.edit');

        return $user->fresh();
    }

    private function media(User $user, string $path): Media
    {
        return Media::query()->create([
            'user_id' => $user->id, 'disk' => 's3', 'path' => $path,
            'original_name' => basename($path), 'mime' => 'image/png', 'size' => 100,
        ]);
    }

    public function test_public_page_resolves_og_image_media_id_to_url(): void
    {
        config(['filesystems.disks.s3.url' => 'http://cdn.test/cms']);
        $user = $this->user();
        $img = $this->media($user, 'media/1/og.png');

        $page = Page::query()->create([
            'user_id' => $user->id, 'title' => 'صفحه', 'slug' => 'seo-og',
            'status' => 'draft', 'blocks' => [],
            'meta' => [
                'og_image_media_id' => $img->id,
                'canonical' => 'https://ex.com/c',
                'noindex' => true,
            ],
        ]);
        $rev = $page->snapshot([], $page->meta, $user->id, 'ایجاد');
        $page->forceFill([
            'status' => Page::STATUS_PUBLISHED,
            'published_revision_id' => $rev->id,
            'published_at' => now(),
        ])->save();

        $data = $this->getJson('/api/v1/site/pages/seo-og')->assertOk()->json('data');

        $this->assertSame('http://cdn.test/cms/media/1/og.png', $data['meta']['og_image_url']);
        $this->assertSame('https://ex.com/c', $data['meta']['canonical']);
        $this->assertTrue($data['meta']['noindex']);
    }

    public function test_public_page_accepts_legacy_numeric_og_image(): void
    {
        config(['filesystems.disks.s3.url' => 'http://cdn.test/cms']);
        $user = $this->user();
        $img = $this->media($user, 'media/1/legacy.png');

        $page = Page::query()->create([
            'user_id' => $user->id, 'title' => 'قدیمی', 'slug' => 'seo-legacy',
            'status' => 'draft', 'blocks' => [],
            'meta' => ['og_image' => $img->id],
        ]);
        $rev = $page->snapshot([], $page->meta, $user->id, 'ایجاد');
        $page->forceFill([
            'status' => Page::STATUS_PUBLISHED,
            'published_revision_id' => $rev->id,
            'published_at' => now(),
        ])->save();

        $data = $this->getJson('/api/v1/site/pages/seo-legacy')->assertOk()->json('data');

        $this->assertSame('http://cdn.test/cms/media/1/legacy.png', $data['meta']['og_image_url']);
    }

    public function test_admin_persists_seo_meta_and_rejects_markup_in_canonical(): void
    {
        $user = $this->user();

        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/pages', [
            'title' => 'صفحه سئو',
            'slug' => 'seo-admin',
            'blocks' => [],
            'meta' => [
                'title' => 'عنوان سئو',
                'description' => 'توضیحات',
                'canonical' => 'https://ex.com/x',
                'noindex' => true,
            ],
        ]);
        $created->assertCreated();
        $id = $created->json('data.id');

        $meta = Page::query()->findOrFail($id)->meta;
        $this->assertSame('عنوان سئو', $meta['title']);
        $this->assertSame('https://ex.com/x', $meta['canonical']);
        $this->assertTrue($meta['noindex']);

        $bad = $this->actingAs($user, 'sanctum')->putJson("/api/v1/admin/pages/{$id}", [
            'meta' => ['canonical' => '<script>alert(1)</script>'],
        ]);
        $bad->assertStatus(422);
    }
}
