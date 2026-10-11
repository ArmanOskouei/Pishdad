<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Page;
use App\Models\SidePreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** خروجی عمومی: resolve نشانی مدیا در بلوک‌ها (image/hero/gallery + سایدبارها). */
class SiteMediaResolveTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مدیر تست',
            'email' => 'm'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
    }

    private function media(User $user, string $path): Media
    {
        return Media::query()->create([
            'user_id' => $user->id, 'disk' => 's3', 'path' => $path,
            'original_name' => basename($path), 'mime' => 'image/png', 'size' => 100,
        ]);
    }

    private function publishedPage(User $user, string $slug, array $blocks): Page
    {
        $page = Page::query()->create([
            'user_id' => $user->id, 'title' => 'ت', 'slug' => $slug,
            'status' => 'draft', 'blocks' => $blocks,
        ]);
        $rev = $page->snapshot($blocks, null, $user->id, 'ایجاد');
        $page->forceFill([
            'status' => Page::STATUS_PUBLISHED,
            'published_revision_id' => $rev->id,
            'published_at' => now(),
        ])->save();

        return $page->fresh();
    }

    public function test_public_page_resolves_image_hero_gallery_urls(): void
    {
        // E90: نشانی s3 از روتِ بک‌اند می‌آید، نه MinIO مستقیم.
        $user = $this->user();
        $a = $this->media($user, 'media/1/a.png');
        $b = $this->media($user, 'media/1/b.png');
        $c = $this->media($user, 'media/1/c.png');

        $this->publishedPage($user, 'res1', [
            ['type' => 'image', 'data' => ['media_id' => $a->id, 'alt' => 'الف']],
            ['type' => 'hero', 'data' => ['title' => 'T', 'image_id' => $b->id]],
            ['type' => 'gallery', 'data' => ['media_ids' => [$b->id, $c->id, 999999]]],
            ['type' => 'text', 'data' => ['body' => 'سلام']],
        ]);

        $data = $this->getJson('/api/v1/site/pages/res1')->assertOk()->json('data');
        $blocks = $data['blocks'];

        $this->assertStringEndsWith('/api/v1/site/media/'.$a->id, $blocks[0]['data']['url']);
        $this->assertStringEndsWith('/api/v1/site/media/'.$b->id, $blocks[1]['data']['url']);
        $this->assertStringEndsWith('/api/v1/site/media/'.$b->id, $blocks[2]['data']['url_0']);
        $this->assertStringEndsWith('/api/v1/site/media/'.$c->id, $blocks[2]['data']['url_1']);
        $this->assertArrayNotHasKey('url_2', $blocks[2]['data']);
        $this->assertArrayNotHasKey('url', $blocks[3]['data']);
    }

    public function test_public_page_enriches_sidebar_preset_blocks(): void
    {
        // E90: نشانی s3 از روتِ بک‌اند می‌آید، نه MinIO مستقیم.
        $user = $this->user();
        $m = $this->media($user, 'media/1/s.png');
        $preset = SidePreset::query()->create([
            'user_id' => $user->id, 'name' => 'ساید', 'side' => 'right',
            'blocks' => [['type' => 'image', 'data' => ['media_id' => $m->id]]],
        ]);

        $page = $this->publishedPage($user, 'res2', [
            ['type' => 'text', 'data' => ['body' => 'x']],
        ]);
        $page->forceFill(['right_preset_id' => $preset->id, 'right_enabled' => true])->save();

        $data = $this->getJson('/api/v1/site/pages/res2')->assertOk()->json('data');

        $this->assertStringEndsWith('/api/v1/site/media/'.$m->id, $data['sidebars']['right']['blocks'][0]['data']['url']);
    }
}
