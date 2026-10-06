<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Page;
use App\Models\PageRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * WF-L1 — نقطهٔ کانونی تصویر: ذخیره/اعتبارسنجی + انتشار در payload صفحهٔ عمومی.
 *
 * x/y نرمالِ 0..1 هستند. `null` یعنی «وسط» (پیش‌فرض مرورگر)، پس مقداردهیِ
 * دوباره با null باید پاک‌کردن حساب شود، نه صفر.
 */
class MediaFocalPointTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مدیر تست',
            'email' => 'f'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
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

    public function test_update_persists_focal_point_and_returns_it(): void
    {
        $user = $this->user();
        $media = $this->media();
        $auth = $this->actingAs($user, 'sanctum');

        $res = $auth->putJson("/api/v1/admin/media/{$media->id}", [
            'focal_x' => 0.25,
            'focal_y' => 0.8,
        ]);

        $res->assertOk()->assertJsonPath('message', 'فایل به‌روزرسانی شد.');

        $fresh = $media->fresh();
        $this->assertEqualsWithDelta(0.25, $fresh->focal_x, 0.0001);
        $this->assertEqualsWithDelta(0.8, $fresh->focal_y, 0.0001);

        // payload خودش هم باید مختصات را بدهد (نه فقط DB).
        $this->assertEqualsWithDelta(0.25, $res->json('data.focal_x'), 0.0001);
        $this->assertEqualsWithDelta(0.8, $res->json('data.focal_y'), 0.0001);
    }

    public function test_focal_point_must_be_between_zero_and_one(): void
    {
        $user = $this->user();
        $media = $this->media();
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson("/api/v1/admin/media/{$media->id}", ['focal_x' => 1.5])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['focal_x']);

        $auth->putJson("/api/v1/admin/media/{$media->id}", ['focal_y' => -0.1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['focal_y']);

        $auth->putJson("/api/v1/admin/media/{$media->id}", ['focal_x' => 'کج'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['focal_x']);

        // مقدار ناصحیح‌نشده نباید جایی نوشته شود.
        $this->assertNull($media->fresh()->focal_x);
        $this->assertNull($media->fresh()->focal_y);
    }

    public function test_focal_point_can_be_cleared_with_null(): void
    {
        $user = $this->user();
        $media = $this->media(['focal_x' => 0.4, 'focal_y' => 0.6]);
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson("/api/v1/admin/media/{$media->id}", [
            'focal_x' => null,
            'focal_y' => null,
        ])->assertOk();

        $this->assertNull($media->fresh()->focal_x);
        $this->assertNull($media->fresh()->focal_y);
    }

    public function test_site_page_payload_exposes_focal_point(): void
    {
        config(['filesystems.disks.s3.url' => 'http://cdn.test/cms']);

        $media = $this->media(['focal_x' => 0.3, 'focal_y' => 0.7]);

        $page = Page::query()->create([
            'title' => 'صفحهٔ آزمون',
            'slug' => 'focal-media',
            'status' => Page::STATUS_PUBLISHED,
            'blocks' => [
                ['type' => 'image', 'data' => ['media_id' => $media->id, 'alt' => 'عکس']],
                ['type' => 'gallery', 'data' => ['media_ids' => [$media->id], 'columns' => 1]],
            ],
            'meta' => [],
        ]);

        $revision = PageRevision::query()->create([
            'page_id' => $page->id,
            'version' => 1,
            'blocks' => $page->blocks,
            'meta' => [],
        ]);

        $page->forceFill(['published_revision_id' => $revision->id])->save();

        $blocks = $this->getJson('/api/v1/site/pages/focal-media')->assertOk()->json('data.blocks');

        $this->assertEqualsWithDelta(0.3, $blocks[0]['data']['focal_x'], 0.0001);
        $this->assertEqualsWithDelta(0.7, $blocks[0]['data']['focal_y'], 0.0001);
        // گالری با پسوند موقعیتیِ خودش می‌آید.
        $this->assertEqualsWithDelta(0.3, $blocks[1]['data']['focal_x_0'], 0.0001);
        $this->assertEqualsWithDelta(0.7, $blocks[1]['data']['focal_y_0'], 0.0001);
    }

    public function test_page_payload_has_no_focal_keys_when_unset(): void
    {
        config(['filesystems.disks.s3.url' => 'http://cdn.test/cms']);

        $media = $this->media();

        $page = Page::query()->create([
            'title' => 'صفحهٔ آزمون',
            'slug' => 'no-focal-media',
            'status' => Page::STATUS_PUBLISHED,
            'blocks' => [['type' => 'image', 'data' => ['media_id' => $media->id]]],
            'meta' => [],
        ]);

        $revision = PageRevision::query()->create([
            'page_id' => $page->id,
            'version' => 1,
            'blocks' => $page->blocks,
            'meta' => [],
        ]);

        $page->forceFill(['published_revision_id' => $revision->id])->save();

        $data = $this->getJson('/api/v1/site/pages/no-focal-media')->assertOk()->json('data.blocks.0.data');

        $this->assertArrayNotHasKey('focal_x', $data);
        $this->assertArrayNotHasKey('focal_y', $data);
    }
}
