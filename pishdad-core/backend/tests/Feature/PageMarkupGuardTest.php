<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * F0.1 — XSS ذخیره‌شده در JSON-LD صفحهٔ عمومی.
 *
 * دو لایه، هر دو تست می‌شوند:
 *   ۱. بک‌اند: `no_markup` مانع ذخیرهٔ `</script>` در عنوان/متا می‌شود.
 *   ۲. مجوزدهی: مسیرهای نوشتن صفحه `perm:pages.edit` گرفتند، پس viewer
 *      (که فقط `*.view` دارد) دیگر نمی‌تواند عنوانِ ورودی JSON-LD را بازنویسی کند.
 *
 * لایهٔ فرانت (`safeJsonLd` در `app/[...path]/page.tsx`) جداگانه و بدون test
 * runner تأیید شد — ۳۲ assertion.
 */
class PageMarkupGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // نقش‌ها و پرمیشن‌ها با seeder ساخته می‌شوند؛ `RefreshDatabase` آن‌ها را
        // اجرا نمی‌کند ⇒ بدون این، `editor`/`viewer` هیچ پرمیشنی ندارند و
        // همه‌چیز 403 می‌شود و تست‌های مجاز هم شکست می‌خورند.
        $this->seed(RolesPermissionsSeeder::class);
    }

    private function user(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'مدیر تست',
            'email' => 'u'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ], $overrides));
    }

    /** کاربری با `pages.edit` (نقش editor) — مسیر مجاز. */
    private function editor(): User
    {
        $user = $this->user();
        $user->assignRole('editor');

        return $user->fresh();
    }

    /** کاربری با فقط `*.view` — مسیر نوشتن باید بسته باشد. */
    private function viewer(): User
    {
        $user = $this->user();
        $user->assignRole('viewer');

        return $user->fresh();
    }

    // ── لایهٔ ۱: اعتبارسنجی ورودی ─────────────────────────────────────────

    public function test_page_title_rejects_script_breakout_payload(): void
    {
        $res = $this->actingAs($this->editor(), 'sanctum')->postJson('/api/v1/admin/pages', [
            'title' => '</script><script>alert(1)</script>',
            'slug' => 'xss-'.uniqid(),
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors('title');
        $this->assertDatabaseMissing('pages', ['title' => '</script><script>alert(1)</script>']);
    }

    public function test_page_title_rejects_lone_open_tag(): void
    {
        // باز کردن تگ بدون بستنش برای مرورگر کافی است ⇒ باید رد شود.
        $res = $this->actingAs($this->editor(), 'sanctum')->postJson('/api/v1/admin/pages', [
            'title' => 'متن <script',
            'slug' => 'xss-'.uniqid(),
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors('title');
    }

    public function test_page_meta_rejects_markup(): void
    {
        $res = $this->actingAs($this->editor(), 'sanctum')->postJson('/api/v1/admin/pages', [
            'title' => 'عنوان سالم',
            'slug' => 'xss-'.uniqid(),
            'meta' => ['description' => '<img src=x onerror=alert(1)>'],
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors('meta.description');
    }

    public function test_page_update_rejects_markup(): void
    {
        $editor = $this->editor();
        $page = Page::query()->create(['title' => 'سالم', 'slug' => 'ok-'.uniqid()]);

        $res = $this->actingAs($editor, 'sanctum')->putJson("/api/v1/admin/pages/{$page->id}", [
            'title' => '</script><img src=x onerror=alert(1)>',
        ]);

        $res->assertStatus(422);
        $this->assertDatabaseHas('pages', ['id' => $page->id, 'title' => 'سالم']);
    }

    /**
     * متن فارسی سالم که `>` دارد باید بپذیرد — قاعده نباید سخت‌گیرانهٔ بی‌مورد
     * باشد. مسدودکردن `<` به‌تنهایی کافی است چون باز کردن تگ با `<` شروع
     * می‌شود.
     */
    public function test_benign_greater_than_is_accepted(): void
    {
        $res = $this->actingAs($this->editor(), 'sanctum')->postJson('/api/v1/admin/pages', [
            'title' => 'پلن A > B و R&D',
            'slug' => 'gt-'.uniqid(),
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('pages', ['title' => 'پلن A > B و R&D']);
    }

    // ── لایهٔ ۲: مجوزدهی مسیرهای نوشتن ───────────────────────────────────

    public function test_user_without_pages_edit_cannot_create_page(): void
    {
        $slug = 'viewer-'.uniqid();

        $res = $this->actingAs($this->viewer(), 'sanctum')->postJson('/api/v1/admin/pages', [
            'title' => 'توسط viewer',
            'slug' => $slug,
        ]);

        $res->assertStatus(403);
        $this->assertDatabaseMissing('pages', ['slug' => $slug]);
    }

    public function test_user_without_pages_edit_cannot_update_page(): void
    {
        $page = Page::query()->create(['title' => 'اصلی', 'slug' => 'orig-'.uniqid()]);

        $res = $this->actingAs($this->viewer(), 'sanctum')->putJson("/api/v1/admin/pages/{$page->id}", [
            'title' => 'بازنویسی‌شده',
        ]);

        $res->assertStatus(403);
        $this->assertDatabaseHas('pages', ['id' => $page->id, 'title' => 'اصلی']);
    }

    public function test_user_without_pages_edit_cannot_publish_page(): void
    {
        $page = Page::query()->create(['title' => 'پیش‌نویس', 'slug' => 'draft-'.uniqid()]);

        $res = $this->actingAs($this->viewer(), 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/publish");

        $res->assertStatus(403);
        $this->assertDatabaseHas('pages', ['id' => $page->id, 'status' => Page::STATUS_DRAFT]);
    }

    /** مسیرهای خواندن باید برای viewer باز بمانند — «مشترک بودن نصب» یعنی همه می‌بینند. */
    public function test_viewer_can_still_read_pages(): void
    {
        $this->actingAs($this->viewer(), 'sanctum')
            ->getJson('/api/v1/admin/pages')
            ->assertStatus(200);
    }
}
