<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * E90 — روتِ عمومیِ `GET /api/v1/site/media/{id}`.
 *
 * قرارداد: سطل s3 خصوصی است و مرورگر هرگز URL مستقیمِ MinIO نمی‌بیند؛
 * بایت‌ها از همین روت با mime-allowlist می‌آیند.
 */
class SiteMediaRouteTest extends TestCase
{
    use RefreshDatabase;

    private function media(string $path = 'media/1/a.png', string $mime = 'image/png'): Media
    {
        Storage::fake('s3');
        Storage::disk('s3')->put($path, 'BYTES-'.$path);

        return Media::query()->create([
            'disk' => 's3',
            'path' => $path,
            'original_name' => basename($path),
            'mime' => $mime,
            'size' => 100,
        ]);
    }

    public function test_serves_the_bytes_with_public_cache(): void
    {
        $media = $this->media();

        $res = $this->get('/api/v1/site/media/'.$media->id);

        $res->assertOk();
        $this->assertSame('BYTES-media/1/a.png', $res->streamedContent());
        $this->assertSame('image/png', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('public', (string) $res->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
    }

    public function test_missing_or_foreign_disk_is_404(): void
    {
        $this->get('/api/v1/site/media/999999')->assertNotFound();

        $local = Media::query()->create([
            'disk' => 'public', 'path' => 'media/1/b.png',
            'original_name' => 'b.png', 'mime' => 'image/png', 'size' => 10,
        ]);
        $this->get('/api/v1/site/media/'.$local->id)->assertNotFound();
    }

    public function test_non_image_mime_is_never_served(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('media/1/evil.html', '<script>alert(1)</script>');
        $media = Media::query()->create([
            'disk' => 's3', 'path' => 'media/1/evil.html',
            'original_name' => 'evil.html', 'mime' => 'text/html', 'size' => 25,
        ]);

        // حتی اگر فایل روی دیسک باشد، پسوندِ خارج از allowlist ⇒ ۴۰۴.
        $this->get('/api/v1/site/media/'.$media->id)->assertNotFound();
    }

    public function test_svg_is_sandboxed(): void
    {
        $media = $this->media('media/1/v.svg', 'image/svg+xml');

        $res = $this->get('/api/v1/site/media/'.$media->id);

        $res->assertOk();
        $this->assertSame('sandbox', $res->headers->get('Content-Security-Policy'));
    }

    public function test_variant_file_must_belong_to_the_same_row(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('media/1/a-640.webp', 'VARIANT');
        $media = Media::query()->create([
            'disk' => 's3', 'path' => 'media/1/a.png',
            'original_name' => 'a.png', 'mime' => 'image/png', 'size' => 100,
            'variants' => ['items' => [
                ['width' => 640, 'sources' => ['image/webp' => 'media/1/a-640.webp']],
            ]],
        ]);

        // نسخهٔ ثبت‌شده در همین رکورد ⇒ ۲۰۰.
        $this->get('/api/v1/site/media/'.$media->id.'?file='.rawurlencode('media/1/a-640.webp'))
            ->assertOk();

        // فایلِ غریبه (حتی موجود روی دیسک) ⇒ ۴۰۴؛ traversal هم ⇒ ۴۰۴.
        Storage::disk('s3')->put('media/1/stranger.png', 'X');
        $this->get('/api/v1/site/media/'.$media->id.'?file='.rawurlencode('media/1/stranger.png'))
            ->assertNotFound();
        $this->get('/api/v1/site/media/'.$media->id.'?file='.rawurlencode('../.env'))
            ->assertNotFound();
    }

    public function test_chrome_brand_urls_point_to_the_backend_not_minio(): void
    {
        $user = User::query()->create([
            'name' => 'ت', 'email' => 'brand'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'admin',
        ]);
        $media = $this->media('media/1/logo.png');
        $media->forceFill(['user_id' => $user->id])->save();

        \App\Models\Setting::set('site', 'global', array_merge(
            \App\Models\Setting::get('site', 'global', []),
            ['logo_media_id' => $media->id],
        ));

        $data = $this->getJson('/api/v1/site/chrome')->assertOk()->json('data');

        $this->assertStringEndsWith('/api/v1/site/media/'.$media->id, $data['logo_url']);
        $this->assertStringNotContainsString('9100', $data['logo_url']);
    }
}
