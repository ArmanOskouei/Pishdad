<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\MediaTag;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** WF-H6 — پوشهٔ درختی/برچسب رسانه، فیلتر و انتقال/برچسب گروهی. */
class MediaFolderTagTest extends TestCase
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
            'mime' => 'application/octet-stream',
            'size' => 10,
        ], $overrides));
    }

    public function test_folder_tree_crud_listing_and_cycle_guard(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $root = $auth->postJson('/api/v1/admin/media/folders', ['name' => 'تصاویر'])
            ->assertCreated()->assertJsonPath('message', 'پوشه ساخته شد.')
            ->json('data.id');

        $child = $auth->postJson('/api/v1/admin/media/folders', ['name' => 'محصولات', 'parent_id' => $root])
            ->assertCreated()->json('data.id');

        $list = $auth->getJson('/api/v1/admin/media/folders')->assertOk();
        $this->assertSame(2, count($list->json('data')));
        $rows = collect($list->json('data'))->keyBy('id');
        $this->assertNull($rows[$root]['parent_id']);
        $this->assertSame($root, (int) $rows[$child]['parent_id']);
        $this->assertDatabaseHas('media_folders', ['id' => $child, 'parent_id' => $root]);

        $auth->putJson("/api/v1/admin/media/folders/{$root}", ['name' => 'تصاویر جدید'])
            ->assertOk()->assertJsonPath('data.name', 'تصاویر جدید');

        // حلقه: پوشه نمی‌تواند زیرشاخهٔ خودش شود.
        $auth->putJson("/api/v1/admin/media/folders/{$root}", ['parent_id' => $child])
            ->assertStatus(422);

        $auth->deleteJson("/api/v1/admin/media/folders/{$child}")
            ->assertOk()->assertJsonPath('message', 'پوشه حذف شد.');
        $this->assertDatabaseMissing('media_folders', ['id' => $child]);
    }

    public function test_deleting_folder_is_non_destructive(): void
    {
        $user = $this->user();
        $folder = MediaFolder::query()->create(['name' => 'قدیمی']);
        $media = $this->media($user, ['folder_id' => $folder->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/admin/media/folders/{$folder->id}")
            ->assertOk();

        // ستون nullable: فایل باقی می‌ماند و «بدون پوشه» می‌شود.
        $this->assertDatabaseHas('media', ['id' => $media->id, 'folder_id' => null]);
    }

    public function test_tag_crud(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $tag = $auth->postJson('/api/v1/admin/media/tags', ['name' => 'ویژه', 'color' => '#f00'])
            ->assertCreated()->assertJsonPath('message', 'برچسب ساخته شد.')
            ->json('data.id');

        $auth->postJson('/api/v1/admin/media/tags', ['name' => 'ویژه'])->assertStatus(422);

        $auth->putJson("/api/v1/admin/media/tags/{$tag}", ['name' => 'مهم'])
            ->assertOk()->assertJsonPath('data.name', 'مهم');

        $auth->getJson('/api/v1/admin/media/tags')->assertOk()->assertJsonPath('data.0.name', 'مهم');

        $auth->deleteJson("/api/v1/admin/media/tags/{$tag}")
            ->assertOk()->assertJsonPath('message', 'برچسب حذف شد.');
        $this->assertDatabaseMissing('media_tags', ['id' => $tag]);
    }

    public function test_filter_by_folder_tag_and_type(): void
    {
        $user = $this->user();
        $folder = MediaFolder::query()->create(['name' => 'تصاویر']);
        $tag = MediaTag::query()->create(['name' => 'ویژه']);

        $image = $this->media($user, ['original_name' => 'a.jpg', 'mime' => 'image/jpeg', 'folder_id' => $folder->id]);
        $image->tags()->attach($tag->id);
        $doc = $this->media($user, ['original_name' => 'b.pdf', 'mime' => 'application/pdf']);

        $auth = $this->actingAs($user, 'sanctum');

        $auth->getJson("/api/v1/admin/media?folder={$folder->id}")
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $image->id);

        $auth->getJson('/api/v1/admin/media?folder=none')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $doc->id);

        $auth->getJson("/api/v1/admin/media?tag={$tag->id}")
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $image->id);

        $auth->getJson('/api/v1/admin/media?type=image')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $image->id);

        // سازگاری با پارامتر قدیمی mime.
        $auth->getJson('/api/v1/admin/media?mime=application')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $doc->id);

        // خروجی، پوشه و برچسب‌ها را همراه دارد.
        $auth->getJson("/api/v1/admin/media/{$image->id}")
            ->assertOk()->assertJsonPath('data.folder.id', $folder->id)
            ->assertJsonPath('data.tags.0.id', $tag->id);
    }

    public function test_bulk_move(): void
    {
        $user = $this->user();
        $folder = MediaFolder::query()->create(['name' => 'مقصد']);
        $a = $this->media($user);
        $b = $this->media($user);

        $auth = $this->actingAs($user, 'sanctum');

        $auth->postJson('/api/v1/admin/media/bulk/move', [
            'ids' => [$a->id, $b->id], 'folder_id' => $folder->id,
        ])->assertOk()->assertJsonPath('data.moved', 2);

        $this->assertDatabaseHas('media', ['id' => $a->id, 'folder_id' => $folder->id]);
        $this->assertDatabaseHas('media', ['id' => $b->id, 'folder_id' => $folder->id]);

        $auth->postJson('/api/v1/admin/media/bulk/move', [
            'ids' => [$a->id], 'folder_id' => null,
        ])->assertOk();
        $this->assertDatabaseHas('media', ['id' => $a->id, 'folder_id' => null]);
    }

    public function test_bulk_tag_add_remove_sync(): void
    {
        $user = $this->user();
        $media = $this->media($user);
        $t1 = MediaTag::query()->create(['name' => 'الف']);
        $t2 = MediaTag::query()->create(['name' => 'ب']);
        $auth = $this->actingAs($user, 'sanctum');
        $url = '/api/v1/admin/media/bulk/tag';

        $auth->postJson($url, ['ids' => [$media->id], 'tag_ids' => [$t1->id], 'mode' => 'add'])->assertOk();
        $this->assertEqualsCanonicalizing([$t1->id], $media->fresh()->tags->pluck('id')->all());

        $auth->postJson($url, ['ids' => [$media->id], 'tag_ids' => [$t2->id], 'mode' => 'add'])->assertOk();
        $this->assertEqualsCanonicalizing([$t1->id, $t2->id], $media->fresh()->tags->pluck('id')->all());

        $auth->postJson($url, ['ids' => [$media->id], 'tag_ids' => [$t1->id], 'mode' => 'remove'])->assertOk();
        $this->assertEqualsCanonicalizing([$t2->id], $media->fresh()->tags->pluck('id')->all());

        $auth->postJson($url, ['ids' => [$media->id], 'tag_ids' => [$t1->id], 'mode' => 'sync'])->assertOk();
        $this->assertEqualsCanonicalizing([$t1->id], $media->fresh()->tags->pluck('id')->all());
    }

    public function test_update_media_folder_and_tags(): void
    {
        $user = $this->user();
        $folder = MediaFolder::query()->create(['name' => 'پوشه']);
        $tag = MediaTag::query()->create(['name' => 'برچسب']);
        $media = $this->media($user);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/admin/media/{$media->id}", [
                'folder_id' => $folder->id, 'tag_ids' => [$tag->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.folder.id', $folder->id)
            ->assertJsonPath('data.tags.0.id', $tag->id);
    }

    public function test_viewer_cannot_write_folder_tag_or_bulk(): void
    {
        $viewer = $this->user('viewer');
        $other = $this->user();
        $media = $this->media($other);
        $auth = $this->actingAs($viewer, 'sanctum');

        $auth->postJson('/api/v1/admin/media/folders', ['name' => 'x'])->assertStatus(403);
        $auth->postJson('/api/v1/admin/media/tags', ['name' => 'x'])->assertStatus(403);
        $auth->postJson('/api/v1/admin/media/bulk/move', ['ids' => [$media->id], 'folder_id' => null])->assertStatus(403);
    }
}
