<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * WF-C7 — بایگانی بلاگ.
 *
 * قرارداد: نوشته = صفحهٔ `published` با `meta.page_type = 'blog'`.
 * دسته/برچسب اختیاریاند (`meta.category`/`meta.categories` و
 * `meta.tag`/`meta.tags`). draft و صفحههای غیرِ blog هرگز درز نمیکنند.
 */
class BlogArchiveTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مشتری تست',
            'email' => 'u'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
    }

    private function page(User $owner, string $slug, array $meta = [], string $status = 'draft'): Page
    {
        $blocks = [['type' => 'text', 'data' => ['body' => '<p>خلاصهٔ '.$slug.'</p>']]];

        $page = Page::query()->create([
            'user_id' => $owner->id,
            'title' => 'نوشته '.$slug,
            'slug' => $slug,
            'status' => Page::STATUS_DRAFT,
            'blocks' => $blocks,
            'meta' => $meta,
        ]);

        if ($status === Page::STATUS_PUBLISHED) {
            $revision = PageRevision::query()->create([
                'page_id' => $page->id,
                'version' => 1,
                'blocks' => $blocks,
                'meta' => $meta,
                'created_by' => $owner->id,
            ]);
            $page->forceFill([
                'status' => Page::STATUS_PUBLISHED,
                'published_revision_id' => $revision->id,
                'published_at' => now(),
            ])->save();
        }

        return $page->fresh();
    }

    /** @return list<string> */
    private function slugs(\Illuminate\Testing\TestResponse $response): array
    {
        return collect($response->json('data.data'))->pluck('slug')->all();
    }

    public function test_only_published_blog_pages_are_listed(): void
    {
        $user = $this->user();
        $this->page($user, 'post-a', ['page_type' => 'blog'], 'published');
        $this->page($user, 'post-b', ['page_type' => 'blog'], 'draft');
        $this->page($user, 'about', ['page_type' => 'single'], 'published');

        $response = $this->getJson('/api/v1/site/blog')->assertOk();

        $this->assertSame(['post-a'], $this->slugs($response));
        $this->assertSame(1, $response->json('data.total'));
    }

    public function test_blog_listing_is_paginated(): void
    {
        $user = $this->user();
        for ($i = 1; $i <= 12; $i++) {
            $this->page($user, 'p'.$i, ['page_type' => 'blog'], 'published');
        }

        $first = $this->getJson('/api/v1/site/blog?per_page=5')->assertOk();
        $this->assertCount(5, $first->json('data.data'));
        $this->assertSame(1, $first->json('data.current_page'));
        $this->assertSame(3, $first->json('data.last_page'));
        $this->assertSame(12, $first->json('data.total'));

        $third = $this->getJson('/api/v1/site/blog?per_page=5&page=3')->assertOk();
        $this->assertCount(2, $third->json('data.data'));
    }

    public function test_category_and_tag_filters_use_meta(): void
    {
        $user = $this->user();
        $this->page($user, 'cat-tech', ['page_type' => 'blog', 'category' => 'tech'], 'published');
        $this->page($user, 'cat-news', ['page_type' => 'blog', 'categories' => ['news', 'world']], 'published');
        $this->page($user, 'tag-php', ['page_type' => 'blog', 'tags' => ['php', 'laravel']], 'published');
        $this->page($user, 'tag-js', ['page_type' => 'blog', 'tag' => 'js'], 'published');
        $this->page($user, 'plain', ['page_type' => 'blog'], 'published');

        $this->assertSame(['cat-tech'], $this->slugs($this->getJson('/api/v1/site/blog?category=tech')->assertOk()));
        $this->assertSame(['cat-news'], $this->slugs($this->getJson('/api/v1/site/blog?category=news')->assertOk()));
        $this->assertSame(['tag-php'], $this->slugs($this->getJson('/api/v1/site/blog?tag=php')->assertOk()));
        $this->assertSame(['tag-js'], $this->slugs($this->getJson('/api/v1/site/blog?tag=js')->assertOk()));
    }

    public function test_blog_slug_strips_legacy_blog_prefix(): void
    {
        $user = $this->user();
        $this->page($user, 'blog/legacy-post', ['page_type' => 'blog'], 'published');

        $response = $this->getJson('/api/v1/site/blog')->assertOk();

        $this->assertSame('legacy-post', $response->json('data.data.0.slug'));
    }

    public function test_blog_feed_contains_published_items_only(): void
    {
        $user = $this->user();
        $this->page($user, 'feed-one', ['page_type' => 'blog', 'description' => 'خلاصهٔ فید'], 'published');
        $this->page($user, 'feed-draft', ['page_type' => 'blog'], 'draft');
        $this->page($user, 'feed-page', ['page_type' => 'single'], 'published');

        $response = $this->get('/api/v1/site/blog/feed');
        $response->assertOk();

        $this->assertStringContainsString('application/rss+xml', (string) $response->headers->get('Content-Type'));
        $body = (string) $response->getContent();
        $this->assertStringContainsString('<rss', $body);
        $this->assertStringContainsString('feed-one', $body);
        $this->assertStringContainsString('<item>', $body);
        $this->assertSame(1, substr_count($body, '<item>'));
        $this->assertStringContainsString('خلاصهٔ فید', $body);
    }

    public function test_single_page_payload_falls_back_to_owner_as_author(): void
    {
        $user = $this->user();
        $this->page($user, 'authored', ['page_type' => 'blog'], 'published');

        $this->getJson('/api/v1/site/pages/authored')->assertOk()
            ->assertJsonPath('data.meta.author_name', 'مشتری تست');
    }

    public function test_single_page_payload_keeps_explicit_author(): void
    {
        $user = $this->user();
        $this->page($user, 'authored-2', ['page_type' => 'blog', 'author_name' => 'نویسندهٔ مهمان'], 'published');

        $this->getJson('/api/v1/site/pages/authored-2')->assertOk()
            ->assertJsonPath('data.meta.author_name', 'نویسندهٔ مهمان');
    }
}
