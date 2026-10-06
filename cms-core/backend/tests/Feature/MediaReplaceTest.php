<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use App\Services\Media\ImageOptimizer;
use App\Services\Media\ImageProcessor;
use App\Services\Media\NullImageProcessor;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WF-M6 — جایگزینی فایل با حفظ URL.
 *
 * سه چیز قفل می‌شود:
 *  ۱. مسیر/URL ثابت می‌ماند؛ فقط `mime`/`size`/`original_name` تازه می‌شوند.
 *  ۲. پرمیشن `media.edit` تنها راه نوشتن است (viewer ⇒ 403).
 *  ۳. نسخه‌های تصویر روی همان دیسک بازتولید می‌شوند و در نبودِ موتور، مسیر
 *     **fail-soft** است (فرادادهٔ کهنه پاک می‌شود، جایگزینی نمی‌شکند).
 */
class MediaReplaceTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'admin'): User
    {
        $this->seed(RolesPermissionsSeeder::class);

        $user = User::query()->create([
            'name' => 'مدیر تست',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
        $user->assignRole($role);

        return $user->fresh();
    }

    private function media(User $user, array $overrides = []): Media
    {
        return Media::query()->create(array_merge([
            'user_id' => $user->id,
            'disk' => 'public',
            'path' => 'media/1/photo.jpg',
            'original_name' => 'photo.jpg',
            'mime' => 'image/jpeg',
            'size' => 3,
        ], $overrides));
    }

    /** فایل PNG واقعی (۱×۱) بدون نیاز به GD — finfo آن را image/png تشخیص می‌دهد. */
    private function pngFile(string $name = 'new.png'): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid('r', true).'.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    public function test_replace_keeps_path_and_url_and_updates_mime_size(): void
    {
        Storage::fake('public');
        $this->app->instance(ImageOptimizer::class, new ImageOptimizer(new NullImageProcessor()));

        $user = $this->user();
        $media = $this->media($user);
        Storage::disk('public')->put($media->path, 'old');

        $pathBefore = $media->path;
        $urlBefore = $media->url;

        $this->actingAs($user, 'sanctum')
            ->post("/api/v1/admin/media/{$media->id}/replace", ['file' => $this->pngFile('renamed.png')])
            ->assertOk()
            ->assertJsonPath('message', 'فایل جایگزین شد.');

        $fresh = $media->fresh();

        $this->assertSame($pathBefore, $fresh->path);
        $this->assertSame($urlBefore, $fresh->url);
        $this->assertSame('image/png', $fresh->mime);
        $this->assertSame('renamed.png', $fresh->original_name);
        $this->assertGreaterThan(3, $fresh->size);
        $this->assertNotSame('old', Storage::disk('public')->get($pathBefore));
    }

    public function test_replace_requires_media_edit_permission(): void
    {
        Storage::fake('public');
        $viewer = $this->user('viewer');
        $owner = $this->user();
        $media = $this->media($owner);
        Storage::disk('public')->put($media->path, 'old');

        $this->actingAs($viewer, 'sanctum')
            ->post("/api/v1/admin/media/{$media->id}/replace", ['file' => $this->pngFile()])
            ->assertStatus(403);

        $this->assertSame('old', Storage::disk('public')->get($media->path));
        $this->assertSame('image/jpeg', $media->fresh()->mime);
    }

    public function test_replace_regenerates_image_variants_on_the_same_disk(): void
    {
        Storage::fake('public');
        $this->app->instance(ImageOptimizer::class, new ImageOptimizer($this->fakeProcessor()));

        $user = $this->user();
        $media = $this->media($user, ['path' => 'media/1/photo.png', 'original_name' => 'photo.png', 'mime' => 'image/png']);
        Storage::disk('public')->put($media->path, 'old-bytes');

        $this->actingAs($user, 'sanctum')
            ->post("/api/v1/admin/media/{$media->id}/replace", ['file' => $this->pngFile()])
            ->assertOk();

        $fresh = $media->fresh();

        $this->assertSame('media/1/photo.png', $fresh->path);
        $this->assertSame('fake', $fresh->variants['processor'] ?? null);
        $this->assertSame([320, 640, 960, 1280], $fresh->variants['widths'] ?? []);
        Storage::disk('public')->assertExists('media/1/photo-320.png');
        Storage::disk('public')->assertExists('media/1/photo-640.webp');
        Storage::disk('public')->assertExists('media/1/photo-1280.avif');
    }

    public function test_replace_is_fail_soft_and_clears_stale_variants_without_a_processor(): void
    {
        Storage::fake('public');
        $this->app->instance(ImageOptimizer::class, new ImageOptimizer(new NullImageProcessor()));

        $user = $this->user();
        $media = $this->media($user, [
            'path' => 'media/1/photo.png',
            'original_name' => 'photo.png',
            'mime' => 'image/png',
            'variants' => ['items' => [
                ['width' => 640, 'sources' => ['image/png' => 'media/1/photo-640.png']],
            ]],
        ]);
        Storage::disk('public')->put($media->path, 'old-bytes');
        Storage::disk('public')->put('media/1/photo-640.png', 'stale-variant');

        $this->actingAs($user, 'sanctum')
            ->post("/api/v1/admin/media/{$media->id}/replace", ['file' => $this->pngFile()])
            ->assertOk();

        $this->assertNull($media->fresh()->variants);
        Storage::disk('public')->assertMissing('media/1/photo-640.png');
        Storage::disk('public')->assertExists('media/1/photo.png');
    }

    public function test_replace_rejects_missing_file(): void
    {
        $user = $this->user();
        $media = $this->media($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/media/{$media->id}/replace", [])
            ->assertStatus(422)->assertJsonPath('errors.file.0', 'فایل الزامی است.');
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
