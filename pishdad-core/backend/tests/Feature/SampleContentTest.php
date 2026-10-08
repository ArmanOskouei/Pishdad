<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Media;
use App\Models\Page;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WF-M21 — نقطهٔ پایانیِ «محتوای نمونهٔ یک‌کلیکی».
 *
 * سه چیز قفل می‌شود: مجوز لازم، ساخته‌شدنِ واقعیِ محتوا، و idempotent بودن.
 */
class SampleContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    private function user(string ...$permissions): User
    {
        $user = User::query()->create([
            'name' => 'کاربر تست',
            'email' => 'sample'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);

        $user->givePermissionTo(...$permissions);

        return $user->fresh();
    }

    public function test_it_requires_the_pages_edit_permission(): void
    {
        $user = $this->user('pages.view');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/sample-content')
            ->assertForbidden();

        $this->assertDatabaseCount('pages', 0);
    }

    public function test_it_creates_sample_pages_media_and_form(): void
    {
        Storage::fake('public');
        $user = $this->user('pages.edit');

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/sample-content');

        $res->assertOk()->assertJsonPath('message', 'محتوای نمونه ساخته شد.');
        $this->assertGreaterThan(0, $res->json('data.pages'));

        foreach (['home', 'about', 'contact'] as $slug) {
            $this->assertDatabaseHas('pages', ['slug' => $slug, 'status' => Page::STATUS_DRAFT]);
        }

        $this->assertDatabaseHas('forms', ['slug' => 'contact']);

        $home = Page::query()->where('slug', 'home')->firstOrFail();
        $gallery = collect($home->blocks)->firstWhere('type', 'gallery');
        $this->assertNotNull($gallery, 'صفحهٔ خانه باید گالری نمونه داشته باشد.');

        $ids = $gallery['data']['media_ids'] ?? [];
        $this->assertNotEmpty($ids);

        foreach ($ids as $id) {
            $this->assertDatabaseHas('media', ['id' => $id]);
        }

        $this->assertSame(count($ids), Media::query()->count());
        foreach (Media::query()->get() as $media) {
            Storage::disk('public')->assertExists($media->path);
        }
    }

    public function test_rerunning_creates_nothing_new(): void
    {
        Storage::fake('public');
        $user = $this->user('pages.edit');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/sample-content')->assertOk();

        $pages = Page::query()->count();
        $media = Media::query()->count();
        $forms = Form::query()->count();
        $homeBlocks = count(Page::query()->where('slug', 'home')->firstOrFail()->blocks);

        $second = $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/sample-content');

        $second->assertOk()
            ->assertJsonPath('data.pages', 0)
            ->assertJsonPath('data.media', 0)
            ->assertJsonPath('data.forms', 0);

        $this->assertSame($pages, Page::query()->count());
        $this->assertSame($media, Media::query()->count());
        $this->assertSame($forms, Form::query()->count());
        $this->assertSame($homeBlocks, count(Page::query()->where('slug', 'home')->firstOrFail()->blocks));
    }
}
