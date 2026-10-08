<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageRevision;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** کار دوم — صفحه خانه: homepage_page_id در settings + حل عمومی /site/homepage. */
class HomepageTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مشتری تست',
            'email' => 'u'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
    }

    private function page(User $owner, string $slug, string $status = 'draft'): Page
    {
        $page = Page::query()->create([
            'user_id' => $owner->id,
            'title' => 'صفحه '.$slug,
            'slug' => $slug.'-'.uniqid(),
            'status' => Page::STATUS_DRAFT,
            'blocks' => [['type' => 'text', 'data' => ['body' => '<p>سلام</p>']]],
        ]);
        if ($status === Page::STATUS_PUBLISHED) {
            $rev = PageRevision::query()->create([
                'page_id' => $page->id, 'version' => 1,
                'blocks' => $page->blocks, 'created_by' => $owner->id,
            ]);
            $page->forceFill([
                'status' => Page::STATUS_PUBLISHED,
                'published_revision_id' => $rev->id,
                'published_at' => now(),
            ])->save();
            // اسلاگ ثابت برای سناریوهای عمومی.
            $page->forceFill(['slug' => $slug])->save();
        } else {
            $page->forceFill(['slug' => $slug])->save();
        }

        return $page->fresh();
    }

    public function test_homepage_setting_accepts_published_page(): void
    {
        $user = $this->user();
        $page = $this->page($user, 'landing', 'published');

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/admin/settings/site', [
            'title' => 'سایت تست',
            'homepage_page_id' => $page->id,
            'locale' => 'fa',
            'timezone' => 'Asia/Tehran',
        ])->assertOk()->assertJsonPath('message', 'تنظیمات سایت ذخیره شد.');

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/settings/site')
            ->assertOk()
            ->assertJsonPath('data.homepage_page_id', $page->id)
            ->assertJsonPath('data.locale', 'fa')
            ->assertJsonPath('data.timezone', 'Asia/Tehran');
    }

    /** مشترک نصب: صفحه خانه می‌تواند صفحه منتشرشده هر مدیر باشد؛ locale نامعتبر همچنان ۴۲۲. */
    public function test_homepage_setting_accepts_shared_page_and_validates_locale(): void
    {
        $mine = $this->user();
        $theirs = $this->user();
        $shared = $this->page($theirs, 'other', 'published');

        $this->actingAs($mine, 'sanctum')->putJson('/api/v1/admin/settings/site', [
            'title' => 'سایت تست',
            'homepage_page_id' => $shared->id,
        ])->assertOk()->assertJsonPath('message', 'تنظیمات سایت ذخیره شد.');

        $this->actingAs($mine, 'sanctum')->putJson('/api/v1/admin/settings/site', [
            'title' => 'سایت تست',
            'homepage_page_id' => 999999,
        ])->assertNotFound()->assertJsonPath('message', 'صفحه خانه انتخاب‌شده یافت نشد.');

        $this->actingAs($mine, 'sanctum')->putJson('/api/v1/admin/settings/site', [
            'title' => 'سایت تست',
            'locale' => 'de',
            'timezone' => 'Mars/Olympus',
        ])->assertStatus(422);
    }

    public function test_public_homepage_prefers_setting_then_home_slug_then_latest(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $home = $this->page($user, 'home', 'published');
        $latest = $this->page($user, 'news', 'published');

        // بدون تنظیم: اسلاگ home.
        $this->getJson('/api/v1/site/homepage')->assertOk()
            ->assertJsonPath('data.slug', 'home');

        // با تنظیم homepage_page_id: همان صفحه.
        $auth->putJson('/api/v1/admin/settings/site', [
            'title' => 'سایت تست', 'homepage_page_id' => $latest->id,
        ])->assertOk();
        $this->getJson('/api/v1/site/homepage')->assertOk()
            ->assertJsonPath('data.slug', $latest->slug);

        // صفحه خانه حذف‌شده از انتشار (draft): fallback به home.
        $latest->forceFill(['status' => 'draft', 'published_revision_id' => null])->save();
        $this->getJson('/api/v1/site/homepage')->assertOk()
            ->assertJsonPath('data.slug', 'home');

        $this->assertSame('home', $home->fresh()->slug);
    }

    public function test_public_homepage_404_when_nothing_published(): void
    {
        $this->getJson('/api/v1/site/homepage')->assertNotFound()
            ->assertJsonPath('message', 'صفحه خانه‌ای تنظیم نشده است.');
    }

    /**
     * L-B9 — انتشار صفحهٔ خانه باید تگ `site-homepage` را dispatch کند.
     *
     * بدون آن، `/` تا انقضای ISR (۳۰۰ثانیه) محتوای قدیمی را نگه می‌داشت و
     * «انتشار» فقط با گذر زمان دیده می‌شد.
     */
    public function test_publishing_the_homepage_dispatches_site_homepage_tag(): void
    {
        Http::fake(['*' => Http::response(['message' => 'ok'], 200)]);
        config(['revalidate.url' => 'http://front.local/api/revalidate']);
        $this->seed(RolesPermissionsSeeder::class);

        $user = $this->user();
        $user->givePermissionTo('pages.edit');
        $page = $this->page($user, 'home', Page::STATUS_DRAFT);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/publish")
            ->assertOk();

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/api/revalidate')
            && in_array('site-homepage', $req['tags'], true)
            && in_array('page:home', $req['tags'], true));
    }

    public function test_publishing_a_non_home_page_does_not_dispatch_site_homepage(): void
    {
        Http::fake(['*' => Http::response(['message' => 'ok'], 200)]);
        config(['revalidate.url' => 'http://front.local/api/revalidate']);
        $this->seed(RolesPermissionsSeeder::class);

        $user = $this->user();
        $user->givePermissionTo('pages.edit');
        $page = $this->page($user, 'articles', Page::STATUS_DRAFT);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/publish")
            ->assertOk();

        Http::assertNotSent(fn ($req) => in_array('site-homepage', $req['tags'] ?? [], true));
    }

    public function test_chrome_exposes_homepage_slug(): void
    {
        $user = $this->user();
        $page = $this->page($user, 'landing-x', 'published');

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/admin/settings/site', [
            'title' => 'سایت تست', 'homepage_page_id' => $page->id,
        ])->assertOk();

        $this->getJson('/api/v1/site/chrome')->assertOk()
            ->assertJsonPath('data.homepage_page_id', $page->id)
            ->assertJsonPath('data.homepage_slug', $page->slug);
    }
}
