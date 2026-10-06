<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** WF-H7 — گزارش تصاویر بدون alt + ویرایش گروهی alt. */
class MediaAltReportTest extends TestCase
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
            'disk' => 's3',
            'path' => 'media/'.uniqid().'.bin',
            'original_name' => 'f.bin',
            'mime' => 'image/jpeg',
            'size' => 10,
            'alt' => null,
        ], $overrides));
    }

    public function test_report_lists_only_images_with_empty_or_blank_alt(): void
    {
        $user = $this->user();

        $imgEmpty = $this->media($user, ['original_name' => 'empty.jpg', 'mime' => 'image/jpeg', 'alt' => null]);
        $imgBlank = $this->media($user, ['original_name' => 'blank.png', 'mime' => 'image/png', 'alt' => '   ']);
        $imgFilled = $this->media($user, ['original_name' => 'ok.jpg', 'mime' => 'image/jpeg', 'alt' => 'توضیح تصویر']);
        $docEmpty = $this->media($user, ['original_name' => 'doc.pdf', 'mime' => 'application/pdf', 'alt' => null]);

        $res = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/media/alt-report')
            ->assertOk();

        $this->assertSame(2, $res->json('data.total'));
        $ids = collect($res->json('data.data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$imgEmpty->id, $imgBlank->id], $ids);
        $this->assertNotContains($imgFilled->id, $ids);
        $this->assertNotContains($docEmpty->id, $ids);
    }

    public function test_bulk_alt_items_shape_persists_and_clears_report(): void
    {
        $user = $this->user();
        $a = $this->media($user, ['original_name' => 'a.jpg']);
        $b = $this->media($user, ['original_name' => 'b.jpg']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/media/bulk/alt', [
            'items' => [
                ['id' => $a->id, 'alt' => 'متن الف'],
                ['id' => $b->id, 'alt' => 'متن ب'],
            ],
        ])->assertOk()->assertJsonPath('data.updated', 2);

        $this->assertSame('متن الف', $a->fresh()->alt);
        $this->assertSame('متن ب', $b->fresh()->alt);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/media/alt-report')
            ->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_bulk_alt_ids_shape_applies_same_text_and_can_clear(): void
    {
        $user = $this->user();
        $a = $this->media($user);
        $b = $this->media($user);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/media/bulk/alt', [
            'ids' => [$a->id, $b->id], 'alt' => 'متن مشترک',
        ])->assertOk()->assertJsonPath('data.updated', 2);

        $this->assertSame('متن مشترک', $a->fresh()->alt);
        $this->assertSame('متن مشترک', $b->fresh()->alt);

        // پاک‌کردن با رشتهٔ خالی: دوباره در گزارش ظاهر می‌شوند.
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/media/bulk/alt', [
            'ids' => [$a->id, $b->id], 'alt' => '',
        ])->assertOk()->assertJsonPath('data.updated', 2);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/media/alt-report')
            ->assertOk()->assertJsonPath('data.total', 2);
    }

    public function test_bulk_alt_requires_a_selection(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/media/bulk/alt', [])
            ->assertStatus(422);
    }

    public function test_viewer_can_read_report_but_cannot_bulk_write(): void
    {
        $viewer = $this->user('viewer');
        $other = $this->user();
        $media = $this->media($other);

        $auth = $this->actingAs($viewer, 'sanctum');

        $auth->getJson('/api/v1/admin/media/alt-report')->assertOk();

        $auth->postJson('/api/v1/admin/media/bulk/alt', [
            'items' => [['id' => $media->id, 'alt' => 'نباید ذخیره شود']],
        ])->assertStatus(403);

        $this->assertNull($media->fresh()->alt);
    }
}
