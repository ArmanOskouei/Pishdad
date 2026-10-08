<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Page;
use App\Models\PageRevision;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** تسک ۲.۲ — DoD: آمار داشبورد + هشدارها + رجیستری خالی ویجت‌ها (تیکت = ۰ تا مرحله ۵). */
class ClientDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'مشتری تست',
            'email' => 'u'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ], $overrides));
    }

    /** مشترک نصب: آمار همه ردیف‌های نصب را می‌شمارد (نه فقط ردیف خود کاربر). */
    public function test_stats_counts_shared_rows(): void
    {
        $mine = $this->user();
        $theirs = $this->user();

        Page::query()->create(['user_id' => $mine->id, 'title' => 'الف', 'slug' => 'a', 'status' => 'draft', 'blocks' => []]);
        Page::query()->create(['user_id' => $mine->id, 'title' => 'ب', 'slug' => 'b', 'status' => 'draft', 'blocks' => []]);
        Page::query()->create(['user_id' => $theirs->id, 'title' => 'ج', 'slug' => 'c', 'status' => 'draft', 'blocks' => []]);
        Media::query()->create(['user_id' => $mine->id, 'disk' => 'local', 'path' => 'x', 'original_name' => 'x.jpg', 'mime' => 'image/jpeg', 'size' => 10]);

        $res = $this->actingAs($mine, 'sanctum')->getJson('/api/v1/admin/dashboard/stats');

        $res->assertOk()
            ->assertJsonPath('data.pages_count', 3)
            ->assertJsonPath('data.media_count', 1)
            ->assertJsonPath('data.open_tickets', 0);
    }

    public function test_stats_requires_auth(): void
    {
        $this->getJson('/api/v1/admin/dashboard/stats')->assertUnauthorized();
    }

    /**
     * هشدارِ فضای فایل — تنها هشدارِ مالی‌شکلی که این نصب دارد.
     *
     * سهمیه ثابت است (تیرِ `budget`) و روی ۸۰٪ هشدار می‌دهد. برای رد شدن از
     * آستانه، یک ردیفِ سنگین کافی است؛ فایلِ واقعی لازم نیست چون جمعِ ستون
     * `size` سنجیده می‌شود.
     */
    public function test_alerts_reports_storage_pressure(): void
    {
        $user = $this->user();

        Media::query()->create([
            'user_id' => $user->id, 'disk' => 'local', 'path' => 'big.bin',
            'original_name' => 'big.bin', 'mime' => 'application/octet-stream',
            'size' => 900 * 1024 * 1024, // ۹۰۰MB از سقفِ ۱GB ⇒ بالای ۸۰٪
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/dashboard/alerts');

        $res->assertOk();
        $types = collect($res->json('data'))->pluck('type')->all();
        $this->assertContains('storage', $types);
    }

    /** زیرِ آستانه هیچ هشداری نیست — وگرنه هشدار همیشه روشن می‌ماند. */
    public function test_alerts_is_empty_below_the_storage_threshold(): void
    {
        $user = $this->user();

        Media::query()->create([
            'user_id' => $user->id, 'disk' => 'local', 'path' => 'small.bin',
            'original_name' => 'small.bin', 'mime' => 'application/octet-stream',
            'size' => 10 * 1024 * 1024, // ۱۰MB ⇒ حدود ۱٪
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/dashboard/alerts')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_widgets_returns_empty_registry_with_ready_structure(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/dashboard/widgets');

        $res->assertOk()
            ->assertJsonPath('data.registry_version', '1.0')
            ->assertJsonPath('data.widgets', []);
        $this->assertNotEmpty($res->json('data.slots'));
    }

    /**
     * WF-H21 — چک‌لیست راه‌اندازی باید از دادهٔ **واقعی** مشتق شود، نه از حدس.
     * نصبِ خالی همه‌چیز ناتمام است؛ با افزودن لوگو/صفحه/سئو/فرم و انتشار،
     * پرچم‌ها یکی‌یکی روشن می‌شوند.
     */
    public function test_checklist_reflects_real_settings_and_pages(): void
    {
        $user = $this->user();

        // نصبِ تازه: هیچ گامی کامل نیست.
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.checklist.logo', false)
            ->assertJsonPath('data.checklist.page', false)
            ->assertJsonPath('data.checklist.seo', false)
            ->assertJsonPath('data.checklist.contact', false)
            ->assertJsonPath('data.checklist.published', false);

        // لوگو فقط وقتی «واقعی» است که رسانه‌ای در تنظیمات انتخاب شده باشد.
        $media = Media::query()->create([
            'user_id' => $user->id, 'disk' => 'local', 'path' => 'logo.png',
            'original_name' => 'logo.png', 'mime' => 'image/png', 'size' => 10,
        ]);
        Setting::set('site', 'global', ['logo_media_id' => $media->id]);

        // صفحهٔ دارای فرم تماس + متای سئو، هنوز پیش‌نویس.
        $page = Page::query()->create([
            'user_id' => $user->id, 'title' => 'تماس', 'slug' => 'contact',
            'status' => Page::STATUS_DRAFT,
            'blocks' => [['type' => 'contact-form', 'data' => ['title' => 'فرم تماس']]],
            'meta' => ['description' => 'راه‌های تماس با ما'],
        ]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.checklist.logo', true)
            ->assertJsonPath('data.checklist.page', true)
            ->assertJsonPath('data.checklist.seo', true)
            ->assertJsonPath('data.checklist.contact', true)
            // پیش‌نویس است ⇒ انتشار هنوز کامل نیست.
            ->assertJsonPath('data.checklist.published', false);

        $page->forceFill(['status' => Page::STATUS_PUBLISHED])->save();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.checklist.published', true);
    }

    /** WF-H21 — سئو باید از توضیحِ سایت هم (نه فقط متای صفحه) مشتق شود. */
    public function test_checklist_seo_can_come_from_site_description(): void
    {
        $user = $this->user();
        Setting::set('site', 'global', ['description' => 'توضیح متای سایت']);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.checklist.seo', true)
            // توضیح سایت به‌تنهایی نه صفحه می‌سازد نه فرم تماس.
            ->assertJsonPath('data.checklist.page', false)
            ->assertJsonPath('data.checklist.contact', false);
    }

    /**
     * پرمیشنِ تحلیل — همان برداری که `EnsurePermission` روی `/admin/analytics`
     * می‌سنجد. بدون سید، `can()` به‌جای throw کردن `false` می‌دهد.
     */
    private function grantAnalytics(User $user): User
    {
        $this->seed(RolesPermissionsSeeder::class);
        $user->givePermissionTo('analytics.view');

        return $user->fresh();
    }

    /**
     * صفحهٔ منتشرشده. `$live = false` یعنی `status = published` ولی **بدونِ
     * نسخهٔ زنده** — همان حالتی که مسیرِ عمومی و سازندهٔ منو آن را «منتشرشده»
     * نمی‌دانند، پس نباید در کارتِ پربازدید بیاید.
     */
    private function publishedPage(User $user, string $title, string $slug, bool $live = true): Page
    {
        $page = Page::query()->create([
            'user_id' => $user->id,
            'title' => $title,
            'slug' => $slug,
            'locale' => Page::LOCALE_DEFAULT,
            'status' => Page::STATUS_PUBLISHED,
            'blocks' => [],
        ]);

        if ($live) {
            $revision = PageRevision::query()->create([
                'page_id' => $page->id,
                'version' => 1,
                'blocks' => [],
                'created_by' => $user->id,
            ]);
            $page->forceFill(['published_revision_id' => $revision->id])->save();
        }

        return $page->fresh();
    }

    /** @param list<string> $paths */
    private function views(array $paths, ?Carbon $at = null): void
    {
        $rows = [];
        foreach ($paths as $path) {
            $rows[] = [
                'path' => $path,
                'slug' => trim($path, '/') === '' ? null : trim($path, '/'),
                'device' => 'desktop',
                'created_at' => $at ?? now(),
            ];
        }

        DB::table('page_views')->insert($rows);
    }

    /**
     * WF-M12 — کارت باید **همان** بازدیدِ ثبت‌شده را نشان دهد: مرتب نزولی،
     * فقط صفحه‌های منتشرشده، عنوان از خودِ صفحه، و بیرون از پنجرهٔ هفت‌روزه
     * حساب نمی‌شود.
     */
    public function test_top_pages_widget_lists_published_pages_by_views(): void
    {
        $user = $this->user();
        $about = $this->publishedPage($user, 'درباره ما', 'about');
        $this->publishedPage($user, 'تماس با ما', 'contact');
        // منتشرشده ولی بدونِ نسخهٔ زنده ⇒ مردم نمی‌بینندش ⇒ نباید بیاید.
        $this->publishedPage($user, 'بی‌نسخه', 'no-live-revision', live: false);

        $this->views(['/about', '/about', '/contact', '/no-live-revision']);
        // بیرون از پنجرهٔ هفت‌روزه — نباید شمرده شود.
        $this->views(['/about'], now()->subDays(9));

        $res = $this->actingAs($this->grantAnalytics($user), 'sanctum')
            ->getJson('/api/v1/admin/dashboard/stats');

        $items = $res->assertOk()
            ->assertJsonPath('data.top_pages_7d.days', 7)
            ->json('data.top_pages_7d.items');

        $this->assertCount(2, $items);
        $this->assertSame('/about', $items[0]['path']);
        $this->assertSame('درباره ما', $items[0]['title']);
        $this->assertSame($about->id, $items[0]['page_id']);
        $this->assertSame(2, $items[0]['views'], 'دو بازدیدِ امروز + بازدیدِ ۹ روز پیش نباید جمع شود.');
        $this->assertSame('/contact', $items[1]['path']);
        $this->assertSame(1, $items[1]['views']);
    }

    /** WF-M12 — بدونِ داده، آرایهٔ خالی؛ نه صفرِ ساختگی و نه `null`. */
    public function test_top_pages_widget_is_an_empty_list_without_data(): void
    {
        $user = $this->user();
        // فقط بازدیدِ قدیمی: بیرون از پنجره ⇒ کارت باید خالی باشد، نه پر.
        $this->views(['/about', '/about'], now()->subDays(40));

        $this->actingAs($this->grantAnalytics($user), 'sanctum')
            ->getJson('/api/v1/admin/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.top_pages_7d.days', 7)
            ->assertJsonPath('data.top_pages_7d.items', []);
    }

    /**
     * WF-M12 — `dashboard/stats` میاندریِ پرمیشن ندارد، پس گیت داخلی لازم است:
     * بدونِ `analytics.view` کلید `null` می‌شود (کارت پنهان) حتی وقتی داده هست.
     */
    public function test_top_pages_widget_is_hidden_without_analytics_permission(): void
    {
        $user = $this->user();
        $this->publishedPage($user, 'درباره ما', 'about');
        $this->views(['/about', '/about', '/about']);

        $data = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/dashboard/stats')
            ->assertOk()
            ->json('data');

        // کلید باید حاضر باشد و `null` — نه اینکه غایب باشد (غیبت یعنی شکست قرارداد).
        $this->assertArrayHasKey('top_pages_7d', $data);
        $this->assertNull($data['top_pages_7d']);
    }
}
