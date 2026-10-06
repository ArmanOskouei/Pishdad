<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Page;
use App\Models\PageRevision;
use App\Services\Media\ImageOptimizer;
use App\Services\Media\ImageProcessor;
use App\Services\Media\NullImageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WF-C4 — بهینه‌سازی تصویر: نسخه‌های WebP/AVIF + چند-عرض.
 *
 * دو چیز قفل می‌شود:
 *  ۱. وقتی `media.variants` وجود دارد، `PageController` کنار `url`/`url_N`/
 *     `poster_url` کلیدهای `srcset` و `sources` (AVIF/WebP) را هم می‌دهد — بدون
 *     شکستن قرارداد قدیمی.
 *  ۲. نبودِ پردازندهٔ تصویر یا فراداده، مسیر **fail-soft** است: هیچ کلیدی اضافه
 *     نمی‌شود و آپلود هرگز نمی‌شکند.
 *
 * تست با پردازندهٔ ساختگی اجرا می‌شود تا به GD/Imagick واقعی نیاز نباشد (این
 * کانتینر هیچ‌کدام را ندارد، پس پاسخ واقعی همان fail-soft است).
 */
class MediaImageVariantsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> فراداده‌ای که یک آپلودِ پردازش‌شده می‌گذارد. */
    private function variants(int $base = 1, string $name = 'photo'): array
    {
        $dir = "media/{$base}";

        return [
            'processor' => 'gd',
            'widths' => [320, 640],
            'items' => [
                ['width' => 640, 'sources' => [
                    'image/jpeg' => "{$dir}/{$name}-640.jpg",
                    'image/webp' => "{$dir}/{$name}-640.webp",
                    'image/avif' => "{$dir}/{$name}-640.avif",
                ]],
                ['width' => 320, 'sources' => [
                    'image/jpeg' => "{$dir}/{$name}-320.jpg",
                    'image/webp' => "{$dir}/{$name}-320.webp",
                    'image/avif' => "{$dir}/{$name}-320.avif",
                ]],
            ],
        ];
    }

    private function media(array $overrides = []): Media
    {
        return Media::query()->create(array_merge([
            'disk' => 's3',
            'path' => 'media/1/photo.jpg',
            'original_name' => 'photo.jpg',
            'mime' => 'image/jpeg',
            'size' => 1000,
        ], $overrides));
    }

    private function publishedPage(array $blocks): Page
    {
        $page = Page::query()->create([
            'title' => 'صفحهٔ آزمون',
            'slug' => 'variants-media',
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

    public function test_image_block_gets_srcset_and_sources_when_variants_exist(): void
    {
        config(['filesystems.disks.s3.url' => 'http://cdn.test/cms']);
        $media = $this->media(['variants' => $this->variants()]);

        $this->publishedPage([
            ['type' => 'image', 'data' => ['media_id' => $media->id, 'alt' => 'عکس']],
        ]);

        $data = $this->getJson('/api/v1/site/pages/variants-media')->assertOk()->json('data.blocks.0.data');

        $this->assertSame('http://cdn.test/cms/media/1/photo.jpg', $data['url']);
        $this->assertSame(
            'http://cdn.test/cms/media/1/photo-320.jpg 320w, http://cdn.test/cms/media/1/photo-640.jpg 640w',
            $data['srcset'],
        );
        $this->assertSame([
            ['type' => 'image/avif', 'srcset' => 'http://cdn.test/cms/media/1/photo-320.avif 320w, http://cdn.test/cms/media/1/photo-640.avif 640w'],
            ['type' => 'image/webp', 'srcset' => 'http://cdn.test/cms/media/1/photo-320.webp 320w, http://cdn.test/cms/media/1/photo-640.webp 640w'],
        ], $data['sources']);
    }

    public function test_gallery_and_poster_get_positional_variant_keys(): void
    {
        config(['filesystems.disks.s3.url' => 'http://cdn.test/cms']);
        $a = $this->media(['variants' => $this->variants(1)]);
        $b = $this->media([
            'path' => 'media/2/pic.jpg',
            'original_name' => 'pic.jpg',
            'variants' => $this->variants(2, 'pic'),
        ]);

        $this->publishedPage([
            ['type' => 'gallery', 'data' => ['media_ids' => [$a->id, $b->id], 'columns' => 2]],
            ['type' => 'video', 'data' => ['url' => 'https://example.com/v.mp4', 'poster_media_id' => $a->id]],
        ]);

        $data = $this->getJson('/api/v1/site/pages/variants-media')->assertOk()->json('data.blocks');

        $this->assertArrayHasKey('url_0', $data[0]['data']);
        $this->assertStringContainsString('photo-320.jpg 320w', $data[0]['data']['srcset_0']);
        $this->assertSame('image/webp', $data[0]['data']['sources_0'][1]['type']);
        $this->assertStringContainsString('pic-640.webp 640w', $data[0]['data']['sources_1'][1]['srcset']);

        $this->assertArrayHasKey('poster_url', $data[1]['data']);
        $this->assertStringContainsString('photo-640.jpg 640w', $data[1]['data']['poster_srcset']);
        $this->assertSame('image/avif', $data[1]['data']['poster_sources'][0]['type']);
    }

    public function test_behavior_is_unchanged_when_variants_are_absent(): void
    {
        config(['filesystems.disks.s3.url' => 'http://cdn.test/cms']);
        $media = $this->media();

        $this->publishedPage([
            ['type' => 'image', 'data' => ['media_id' => $media->id]],
        ]);

        $data = $this->getJson('/api/v1/site/pages/variants-media')->assertOk()->json('data.blocks.0.data');

        $this->assertSame('http://cdn.test/cms/media/1/photo.jpg', $data['url']);
        $this->assertArrayNotHasKey('srcset', $data);
        $this->assertArrayNotHasKey('sources', $data);
    }

    public function test_optimizer_without_a_processor_is_fail_soft(): void
    {
        Storage::fake('public');
        $media = $this->media(['disk' => 'public', 'path' => 'media/1/photo.png', 'mime' => 'image/png']);

        $optimizer = new ImageOptimizer(new NullImageProcessor());

        $this->assertFalse($optimizer->isSupported());
        $this->assertNull($optimizer->optimize($media));
        $this->assertNull($media->fresh()->variants);
    }

    public function test_optimizer_generates_and_persists_variants_on_the_same_disk(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/1/photo.png', 'source-bytes');

        $processor = $this->fakeProcessor();
        $media = $this->media(['disk' => 'public', 'path' => 'media/1/photo.png', 'mime' => 'image/png']);

        $meta = (new ImageOptimizer($processor))->optimize($media);

        $this->assertNotNull($meta);
        $this->assertSame('fake', $meta['processor']);
        $this->assertSame([320, 640, 960, 1280], $meta['widths']);

        Storage::disk('public')->assertExists('media/1/photo-640.webp');
        Storage::disk('public')->assertExists('media/1/photo-320.png');
        Storage::disk('public')->assertExists('media/1/photo-1280.avif');

        $this->assertSame('fake', $media->fresh()->variants['processor']);
    }

    public function test_optimizer_fails_soft_when_the_source_cannot_be_read(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/1/broken.png', 'not-an-image');

        $processor = new class implements ImageProcessor
        {
            public function name(): string
            {
                return 'broken';
            }

            public function available(): bool
            {
                return true;
            }

            public function formats(): array
            {
                return ['image/png' => 'png'];
            }

            public function load(string $bytes): mixed
            {
                return null;
            }

            public function width(mixed $image): int
            {
                return 0;
            }

            public function encode(mixed $image, int $width, string $mime): ?string
            {
                return null;
            }

            public function release(mixed $image): void
            {
            }
        };

        $media = $this->media(['disk' => 'public', 'path' => 'media/1/broken.png']);
        $optimizer = new ImageOptimizer($processor);

        $this->assertTrue($optimizer->isSupported());
        $this->assertNull($optimizer->optimize($media));
        $this->assertNull($media->fresh()->variants);
    }

    private function fakeProcessor(): ImageProcessor
    {
        return new class implements ImageProcessor
        {
            public function name(): string
            {
                return 'fake';
            }

            public function available(): bool
            {
                return true;
            }

            public function formats(): array
            {
                return ['image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif'];
            }

            public function load(string $bytes): mixed
            {
                return $bytes !== '' ? new \stdClass() : null;
            }

            public function width(mixed $image): int
            {
                return 2000;
            }

            public function encode(mixed $image, int $width, string $mime): ?string
            {
                return "fake-{$width}-{$mime}";
            }

            public function release(mixed $image): void
            {
            }
        };
    }
}
