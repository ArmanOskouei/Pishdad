<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** بلوک‌های quote/video/contact-form: رجیستری + ولیدیشن تایپ + رندر عمومی. */
class BlockTypesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // F0.1: پرمیشن‌ها با سیدر ساخته می‌شوند؛ RefreshDatabase اجرایشان نمی‌کند.
        $this->seed(RolesPermissionsSeeder::class);
    }

    private function user(): User
    {
        // F0.1: مسیرهای نوشتن صفحه `perm:pages.edit` گرفتند. قبلاً هر کاربر
        // لاگین‌کرده می‌توانست بنویسد (که خودِ باگ بود) و این fixture ناخواسته
        // همان رفتار را تثبیت کرده بود.
        // `->tap()` استفاده نمی‌شود چون `Builder::create()` خودش از `tap()`
        // داخلی استفاده می‌کند و callback ما را با `Builder` صدا می‌زند.
        $user = User::query()->create([
            'name' => 'مدیر تست',
            'email' => 'u'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
        $user->givePermissionTo('pages.view', 'pages.edit');

        return $user;
    }

    private function newBlocks(): array
    {
        return [
            ['type' => 'quote', 'data' => ['text' => 'سخن نمونه', 'author' => 'گوینده']],
            ['type' => 'video', 'data' => ['url' => 'https://example.com/v.mp4', 'caption' => 'کپشن']],
            ['type' => 'contact-form', 'data' => ['title' => 'فرم تماس', 'show_phone' => true]],
        ];
    }

    public function test_schema_lists_new_types_with_required_fields(): void
    {
        $res = $this->actingAs($this->user(), 'sanctum')->getJson('/api/v1/admin/blocks/schema');

        $res->assertOk();
        $byType = collect($res->json('data'))->keyBy('type');
        foreach (['quote', 'video', 'contact-form'] as $t) {
            $this->assertArrayHasKey($t, $byType->all(), "تایپ {$t} در رجیستری نیست.");
        }
        $this->assertContains('text', $byType['quote']['schema']['required']);
        $this->assertContains('url', $byType['video']['schema']['required']);
        $this->assertContains('title', $byType['contact-form']['schema']['required']);
    }

    public function test_admin_can_save_page_with_new_block_types(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/pages', [
            'title' => 'صفحه بلوک‌های جدید',
            'slug' => 'new-blocks',
            'blocks' => $this->newBlocks(),
        ]);

        $res->assertCreated();
        $page = Page::query()->where('slug', 'new-blocks')->firstOrFail();
        $this->assertSame(
            ['quote', 'video', 'contact-form'],
            collect($page->blocks)->pluck('type')->all()
        );
    }

    public function test_unknown_block_type_still_rejected(): void
    {
        $res = $this->actingAs($this->user(), 'sanctum')->postJson('/api/v1/admin/pages', [
            'title' => 'بد',
            'slug' => 'bad-blocks',
            'blocks' => [['type' => 'nope', 'data' => []]],
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors('blocks.0.type');
        $this->assertSame('نوع بلوک پشتیبانی نمی‌شود.', $res->json('errors')['blocks.0.type'][0]);
    }

    public function test_public_page_returns_new_block_types_after_publish(): void
    {
        $user = $this->user();
        $page = Page::query()->create([
            'user_id' => $user->id, 'title' => 'عمومی', 'slug' => 'pub-blocks',
            'status' => 'draft', 'blocks' => $this->newBlocks(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/publish")
            ->assertOk();

        $res = $this->getJson('/api/v1/site/pages/pub-blocks');

        $res->assertOk();
        $this->assertSame(
            ['quote', 'video', 'contact-form'],
            collect($res->json('data.blocks'))->pluck('type')->all()
        );
        $this->assertSame('سخن نمونه', $res->json('data.blocks.0.data.text'));
        $this->assertSame('https://example.com/v.mp4', $res->json('data.blocks.1.data.url'));
        $this->assertSame('فرم تماس', $res->json('data.blocks.2.data.title'));
    }
}
