<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** بلوک faq: ثبت در رجیستری schema + ذخیره صفحه + رندر عمومی. */
class FaqBlockTest extends TestCase
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
        // F0.1: مسیرهای نوشتن صفحه `perm:pages.edit` گرفتند.
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

    public function test_schema_lists_faq_with_items_shape(): void
    {
        $res = $this->actingAs($this->user(), 'sanctum')->getJson('/api/v1/admin/blocks/schema');

        $res->assertOk();
        $byType = collect($res->json('data'))->keyBy('type');
        $this->assertArrayHasKey('faq', $byType->all(), 'تایپ faq در رجیستری نیست.');
        $this->assertContains('items', $byType['faq']['schema']['required']);
        $this->assertSame(20, $byType['faq']['schema']['properties']['items']['maxItems']);
        $this->assertContains('q', $byType['faq']['schema']['properties']['items']['items']['required']);
        $this->assertContains('a', $byType['faq']['schema']['properties']['items']['items']['required']);
        $this->assertSame(200, $byType['faq']['schema']['properties']['items']['items']['properties']['q']['maxLength']);
    }

    public function test_admin_can_save_page_with_faq_block(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/pages', [
            'title' => 'صفحه سوالات',
            'slug' => 'faq-page',
            'blocks' => [[
                'type' => 'faq',
                'data' => ['items' => [
                    ['q' => 'هزینه ارسال چقدر است؟', 'a' => 'ارسال رایگان است.'],
                    ['q' => 'مرجوعی دارید؟', 'a' => 'تا ۷ روز.'],
                ]],
            ]],
        ]);

        $res->assertCreated();
        $page = Page::query()->where('slug', 'faq-page')->firstOrFail();
        $this->assertSame('faq', $page->blocks[0]['type']);
        $this->assertCount(2, $page->blocks[0]['data']['items']);
    }

    public function test_public_page_returns_faq_after_publish(): void
    {
        $user = $this->user();
        $page = Page::query()->create([
            'user_id' => $user->id, 'title' => 'سوالات', 'slug' => 'pub-faq',
            'status' => 'draft',
            'blocks' => [[
                'type' => 'faq',
                'data' => ['items' => [['q' => 'پرسش؟', 'a' => 'پاسخ.']]],
            ]],
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/publish")
            ->assertOk();

        $this->getJson('/api/v1/site/pages/pub-faq')
            ->assertOk()
            ->assertJsonPath('data.blocks.0.type', 'faq')
            ->assertJsonPath('data.blocks.0.data.items.0.q', 'پرسش؟');
    }
}
