<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Page;
use App\Models\PageRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تست غنی‌سازی رسانه در بلوک‌های صفحهٔ عمومی (تسک B1.1).
 *
 * ## قرارداد بین بک‌اند و فرانت
 *
 * محتوا فقط `media_id` را نگه می‌دارد (نه URL). اگر URL ذخیره می‌شد، با
 * جابه‌جایی فایل در فضای ذخیره‌سازی، محتوا می‌شکست.
 *
 * پس `Site\PageController::withMediaUrls()` پیش از ارسال، کلیدهای زیر را
 * **افزودنی** تزریق می‌کند:
 *
 * • بلوک `image` / `hero` ← `url`
 * • بلوک `gallery` ← `url_0`, `url_1`, …
 * • پوستر ویدیو ← `poster_url`
 *
 * و `BlockRenderer` دقیقاً همین کلیدها را می‌خواند. این تست همان قرارداد
 * را قفل می‌کند تا کسی یک طرف را عوض نکند و طرف دیگر بی‌سروصدا خراب شود.
 */
class PublicPageMediaUrlTest extends TestCase
{
    use RefreshDatabase;

    private function seedMedia(): Media
    {
        // `path` نسبی به دیسک عمومی است؛ کنترلر با `disks.s3.url` join می‌کند.
        return Media::query()->create([
            'path' => 'shared/abc.jpg',
            'original_name' => 'abc.jpg',
            'mime' => 'image/jpeg',
            'size' => 1024,
        ]);
    }

    private function seedPageWithBlocks(array $blocks): Page
    {
        $page = Page::query()->create([
            'title' => 'صفحهٔ آزمون',
            'slug' => 'test-media',
            'status' => Page::STATUS_PUBLISHED,
            'blocks' => $blocks,
            'meta' => [],
        ]);

        $revision = PageRevision::query()->create([
            'page_id' => $page->id,
            'version' => 1,
            'blocks' => $blocks,
            'meta' => [],
        ]);

        $page->forceFill(['published_revision_id' => $revision->id])->save();

        return $page->fresh();
    }

    public function test_image_block_gets_a_resolved_url(): void
    {
        $media = $this->seedMedia();

        $this->seedPageWithBlocks([
            ['type' => 'image', 'data' => ['media_id' => $media->id, 'alt' => 'توضیح']],
        ]);

        $response = $this->getJson('/api/v1/site/pages/test-media')->assertOk();

        $data = $response->json('data.blocks.0.data');

        $this->assertArrayHasKey('url', $data);
        $this->assertStringEndsWith('/shared/abc.jpg', $data['url']);
        $this->assertSame('توضیح', $data['alt']);
    }

    /**
     * 🔴 این همان باگی است که بلوک گالری را از کار انداخته بود.
     *
     * گالری `media_ids` (آرایه) دارد، ولی قبلاً فقط `media_id` مفرد resolve
     * می‌شد ⇒ `url_0` هرگز ساخته نمی‌شد و `BlockRenderer` هیچ عکسی نشان
     * نمی‌داد.
     */
    public function test_gallery_block_gets_positional_urls(): void
    {
        $first = $this->seedMedia();
        $second = Media::query()->create([
            'path' => 'shared/def.png',
            'original_name' => 'def.png',
            'mime' => 'image/png',
            'size' => 2048,
        ]);

        $this->seedPageWithBlocks([
            ['type' => 'gallery', 'data' => [
                'media_ids' => [$first->id, $second->id],
                'columns' => 2,
            ]],
        ]);

        $data = $this->getJson('/api/v1/site/pages/test-media')->assertOk()->json('data.blocks.0.data');

        $this->assertStringEndsWith('/shared/abc.jpg', $data['url_0']);
        $this->assertStringEndsWith('/shared/def.png', $data['url_1']);
        $this->assertSame(2, $data['columns']);
    }

    public function test_video_poster_is_resolved(): void
    {
        $media = $this->seedMedia();

        $this->seedPageWithBlocks([
            ['type' => 'video', 'data' => [
                'url' => 'https://example.com/a.mp4',
                'poster_media_id' => $media->id,
            ]],
        ]);

        $data = $this->getJson('/api/v1/site/pages/test-media')->assertOk()->json('data.blocks.0.data');

        $this->assertStringEndsWith('/shared/abc.jpg', $data['poster_url']);
    }

    /**
     * اگر رسانه حذف شده باشد نباید کلیدی ساخته شود — تا `BlockRenderer` به
     *‌جای تصویر شکسته، متن راهنما نشان دهد.
     */
    public function test_missing_media_leaves_the_block_without_urls(): void
    {
        $this->seedPageWithBlocks([
            ['type' => 'image', 'data' => ['media_id' => 999999, 'alt' => 'ناموجود']],
        ]);

        $data = $this->getJson('/api/v1/site/pages/test-media')->assertOk()->json('data.blocks.0.data');

        $this->assertArrayNotHasKey('url', $data);
        $this->assertSame('ناموجود', $data['alt']);
    }

    /**
     * 🔴 رگرسیون: رسانه روی دیسکِ محلی هم باید URL بگیرد.
     *
     * نسخهٔ اولِ `withMediaUrls()` فقط `config('filesystems.disks.s3.url')`
     * را می‌خواند. یعنی روی یک نصبِ پیش‌فرض — که دیسکش `local` یا
     * `public` است، نه S3 — **هیچ تصویری** URL نمی‌گرفت و فرانت به‌جای
     * عکس، قالبِ «تصویر در دسترس نیست» را نشان می‌داد.
     *
     * تست‌های قبلی این را نمی‌دیدند چون `seedMedia()` ستون `disk` را پر
     * نمی‌کرد و مقدارِ پیش‌فرضِ ستون هم S3 بود.
     */
public function test_media_on_a_local_disk_still_gets_a_url(): void
    {
        $media = Media::query()->create([
            'disk' => 'public',
            'path' => 'media/shared/local.svg',
            'original_name' => 'local.svg',
            'mime' => 'image/svg+xml',
            'size' => 900,
        ]);

        $this->seedPageWithBlocks([
            ['type' => 'image', 'data' => ['media_id' => $media->id]],
        ]);

        $data = $this->getJson('/api/v1/site/pages/test-media')->assertOk()->json('data.blocks.0.data');

        $this->assertArrayHasKey(
            'url',
            $data,
            'رسانهٔ دیسک محلی هم باید URL عمومی بگیرد، نه فقط s3'
        );

        /*
         * ⚠️ `Storage::url()` روی دیسک محلی با `APP_URL` تنظیم‌شده، URL
         * *مطلق* می‌دهد (`http://host/storage/…`). پس اینجا مسیر را چک
         * می‌کنیم نه «شروع نشدن با http» — که اشتباه بود و در محیطِ واقعی
         * هم قرمز می‌شد.
         */
        $this->assertStringEndsWith('/media/shared/local.svg', $data['url']);
        $this->assertStringContainsString('/storage/', $data['url']);
        $this->assertStringNotContainsString('//localhost:9100', $data['url']);
    }

    /**
     * دو رسانه روی دو دیسکِ متفاوت باید هر کدام URL خودشان را بگیرند.
     *
     * اگر resolver فقط یک دیسک را نگاه کند، یکی از این دو بی‌صدا
     * بی‌URL می‌ماند و گالری ناقص رندر می‌شود.
     */
    public function test_media_on_mixed_disks_each_get_their_own_url(): void
    {
        $local = Media::query()->create([
            'disk' => 'public',
            'path' => 'media/shared/on-public.svg',
            'original_name' => 'on-public.svg',
            'mime' => 'image/svg+xml',
            'size' => 100,
        ]);

        $s3 = Media::query()->create([
            'disk' => 's3',
            'path' => 'shared/on-s3.svg',
            'original_name' => 'on-s3.svg',
            'mime' => 'image/svg+xml',
            'size' => 200,
        ]);

        $this->seedPageWithBlocks([
            ['type' => 'gallery', 'data' => ['media_ids' => [$local->id, $s3->id], 'columns' => 2]],
        ]);

        $data = $this->getJson('/api/v1/site/pages/test-media')->assertOk()->json('data.blocks.0.data');

        $this->assertStringEndsWith('/media/shared/on-public.svg', $data['url_0']);
        $this->assertStringEndsWith('/shared/on-s3.svg', $data['url_1']);
    }

    /**
     * دیسکِ ناشناس نباید کل URL را بی‌صدا خراب کند.
     *
     * resolver باید `null` بدهد تا فرانت قالبِ خالی نشان دهد — نه اینکه
     * یک URL ناقص تولید کند.
     *
     * ⚠️ ستون `media.disk` فقط ۲۰ کاراکتر است، پس نامِ دیسکِ نامعتبر باید
     * کوتاه بماند ولی وجود نداشته باشد.
     */
    public function test_an_unknown_disk_leaves_the_url_out(): void
    {
        $media = Media::query()->create([
            'disk' => 'nowhere',
            'path' => 'shared/x.svg',
            'original_name' => 'x.svg',
            'mime' => 'image/svg+xml',
            'size' => 100,
        ]);

        $this->seedPageWithBlocks([
            ['type' => 'image', 'data' => ['media_id' => $media->id]],
        ]);

        $data = $this->getJson('/api/v1/site/pages/test-media')->assertOk()->json('data.blocks.0.data');

        $this->assertArrayNotHasKey('url', $data);
    }

    /**
     * نباید کوئری به‌ازای هر بلوک زده شود.
     *
     * یک صفحهٔ واقعی ده‌ها بلوک دارد؛ بدون این، ده‌ها کوئری به دیتابیس
     * اضافه می‌شد.
     */
    public function test_many_blocks_are_resolved_with_a_single_query(): void
    {
        $first = $this->seedMedia();
        $second = Media::query()->create([
            'path' => 'shared/def.png', 'original_name' => 'def.png',
            'mime' => 'image/png', 'size' => 2048,
        ]);

        $blocks = [];
        for ($i = 0; $i < 8; $i++) {
            $blocks[] = ['type' => 'image', 'data' => ['media_id' => $first->id]];
        }
        $blocks[] = ['type' => 'gallery', 'data' => ['media_ids' => [$second->id]]];

        $this->seedPageWithBlocks($blocks);

        \Illuminate\Support\Facades\DB::enableQueryLog();

        $this->getJson('/api/v1/site/pages/test-media')->assertOk();

        $mediaQueries = array_filter(
            \Illuminate\Support\Facades\DB::getQueryLog(),
            fn (array $q) => str_contains($q['query'], 'from "media"'),
        );

        $this->assertLessThanOrEqual(
            1,
            count($mediaQueries),
            'همهٔ رسانه‌ها باید با یک کوئری resolve شوند',
        );
    }
}