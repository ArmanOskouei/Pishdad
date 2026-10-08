<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageRevision;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * WF-M19 — فید RSS از همهٔ محتوای منتشرشده: `GET /api/v1/site/feed`.
 *
 * برخلاف `site/blog/feed` (که فقط `page_type=blog` است) اینجا هر نوعِ محتوای
 * منتشرشده می‌آید. درز نکردن سه لایه دارد و هر سه باید سنجیده شود:
 * کوئری (draft/بدون نسخهٔ منتشرشده/زبانِ دیگر)، SoftDeletes، و noindex در متای
 * **نسخهٔ منتشرشده** (نه پیش‌نویس).
 */
class SiteFeedTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مشتری تست',
            'email' => 'feed'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<int, array<string, mixed>>|null  $revisionMeta
     */
    private function page(
        User $owner,
        string $slug,
        array $meta = [],
        string $status = Page::STATUS_PUBLISHED,
        string $locale = 'fa',
        ?array $revisionMeta = null,
        ?string $publishedAt = null,
    ): Page {
        $blocks = [['type' => 'text', 'data' => ['body' => '<p>متنِ '.$slug.'</p>']]];

        $page = Page::query()->create([
            'user_id' => $owner->id,
            'title' => 'صفحه '.$slug,
            'slug' => $slug,
            'locale' => $locale,
            'status' => Page::STATUS_DRAFT,
            'blocks' => $blocks,
            'meta' => $meta,
        ]);

        if ($status === Page::STATUS_PUBLISHED) {
            $revision = PageRevision::query()->create([
                'page_id' => $page->id,
                'version' => 1,
                'blocks' => $blocks,
                'meta' => $revisionMeta ?? $meta,
                'created_by' => $owner->id,
            ]);
            $page->forceFill([
                'status' => Page::STATUS_PUBLISHED,
                'published_revision_id' => $revision->id,
                'published_at' => $publishedAt ?? now(),
            ])->save();
        }

        return $page->fresh();
    }

    /** @return list<array<string, mixed>> */
    private function items(string $url = '/api/v1/site/feed'): array
    {
        $res = $this->getJson($url)->assertOk();
        $this->assertStringContainsString('public', (string) $res->headers->get('Cache-Control'));

        return $res->json('data');
    }

    /** @return list<string> */
    private function slugs(array $items): array
    {
        return array_map(static fn (array $i) => (string) $i['slug'], $items);
    }

    public function test_only_published_pages_of_any_type_are_listed(): void
    {
        $user = $this->user();
        $this->page($user, 'about', ['description' => 'دربارهٔ ما']);
        $this->page($user, 'post-a', ['page_type' => 'blog']);
        $this->page($user, 'draft-page', ['page_type' => 'blog'], Page::STATUS_DRAFT);

        $items = $this->items();

        $this->assertEqualsCanonicalizing(['about', 'post-a'], $this->slugs($items));
    }

    public function test_published_without_a_published_revision_never_leaks(): void
    {
        $user = $this->user();
        // `published` ولی بدون نسخهٔ منتشرشده: روی سایت وجود ندارد ⇒ در فید هم نه.
        $orphan = $this->page($user, 'orphan', [], Page::STATUS_DRAFT);
        $orphan->forceFill(['status' => Page::STATUS_PUBLISHED, 'published_at' => now()])->save();

        $this->page($user, 'live', ['description' => 'زنده']);

        $this->assertSame(['live'], $this->slugs($this->items()));
    }

    public function test_soft_deleted_pages_are_excluded(): void
    {
        $user = $this->user();
        $this->page($user, 'trashed', ['description' => 'حذف‌شده']);
        $trashed = $this->page($user, 'gone', ['description' => 'رفته به زباله‌دان']);
        $trashed->delete();

        $this->assertSame(['trashed'], $this->slugs($this->items()));
    }

    public function test_noindex_pages_are_excluded_from_the_public_feed(): void
    {
        $user = $this->user();
        $this->page($user, 'indexed', ['description' => 'ایندکس می‌شود']);
        $this->page($user, 'hidden-flag', ['noindex' => true, 'description' => 'پنهان']);
        $this->page($user, 'hidden-robots', ['robots' => 'noindex, nofollow', 'description' => 'پنهان']);
        // متای *پیش‌نویس* نباید فیدِ زنده را مسموم کند: نسخهٔ منتشرشده ایندکس است.
        $this->page($user, 'not-yet-hidden', ['noindex' => true], Page::STATUS_PUBLISHED, 'fa', ['description' => 'منتشر شده']);

        // ترتیبِ قراردادی: `published_at desc, id desc`. هر چهار فیکسچر در همان
        // ثانیه منتشر می‌شوند (ستون `published_at` دقتِ ثانیه دارد)، پس تساوی با
        // `id desc` شکسته می‌شود و صفحهٔ *دیرتر ساخته‌شده* اول می‌آید.
        $this->assertSame(
            ['not-yet-hidden', 'indexed'],
            $this->slugs($this->items()),
        );
    }

    public function test_items_are_newest_first_with_absolute_urls_and_summaries(): void
    {
        Setting::set('site', 'global', [
            'site_url' => 'https://armanaskouei.ir',
            'locale' => 'fa',
        ]);

        $user = $this->user();
        $this->page($user, 'older', ['description' => 'خلاصهٔ قدیمی'], publishedAt: '2026-01-01T00:00:00+00:00');
        $this->page($user, 'newer', ['page_type' => 'blog', 'excerpt' => 'خلاصهٔ جدید'], publishedAt: '2026-05-05T00:00:00+00:00');

        $items = $this->items();

        $this->assertSame(['newer', 'older'], $this->slugs($items));
        $this->assertSame('/blog/newer', $items[0]['path']);
        $this->assertSame('https://armanaskouei.ir/blog/newer', $items[0]['url']);
        $this->assertSame('/older', $items[1]['path']);
        $this->assertSame('https://armanaskouei.ir/older', $items[1]['url']);
        $this->assertSame('خلاصهٔ جدید', $items[0]['summary']);
        $this->assertSame('خلاصهٔ قدیمی', $items[1]['summary']);
        $this->assertSame('blog', $items[0]['page_type']);
        $this->assertNull($items[1]['page_type']);
        $this->assertNotNull($items[0]['published_at']);
    }

    public function test_secondary_locale_gets_a_path_prefix(): void
    {
        $user = $this->user();
        $this->page($user, 'about-fa', ['description' => 'فارسی']);
        $this->page($user, 'about-en', ['description' => 'English'], Page::STATUS_PUBLISHED, 'en');

        $fa = $this->items();
        $this->assertSame(['/about-fa'], array_column($fa, 'path'));

        $en = $this->items('/api/v1/site/feed?locale=en');
        $this->assertSame(['/en/about-en'], array_column($en, 'path'));
        $this->assertStringContainsString('/en/about-en', (string) $en[0]['url']);
    }

    public function test_blog_prefixed_slugs_still_resolve_to_the_archive_path(): void
    {
        $user = $this->user();
        $this->page($user, 'blog/legacy-slug', ['page_type' => 'blog']);

        $items = $this->items();

        $this->assertSame('blog/legacy-slug', $items[0]['slug']);
        $this->assertSame('/blog/legacy-slug', $items[0]['path']);
    }

    public function test_result_set_is_bounded(): void
    {
        $user = $this->user();
        for ($i = 1; $i <= 60; $i++) {
            $this->page($user, 'bulk-'.$i, ['description' => 'مورد '.$i]);
        }

        $items = $this->items();

        $this->assertCount(50, $items);
        // جدیدترین‌ها اول می‌مانند؛ ۵۰ تای اول با ترتیبِ نزولیِ id.
        $this->assertSame('bulk-60', $items[0]['slug']);
        $this->assertSame('bulk-11', $items[49]['slug']);
    }

    public function test_site_without_published_content_returns_an_empty_list(): void
    {
        $this->getJson('/api/v1/site/feed')->assertOk()->assertExactJson(['data' => []]);
    }
}
